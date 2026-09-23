import { ConflictException, ForbiddenException, Injectable, Logger, NotFoundException } from '@nestjs/common';
import { Job } from '@prisma/client';
import {
  canTransition,
  concurrencyCapFor,
  haversineMetres,
  JobStatus,
  OfferStatus,
  RiderStatus,
  ACTIVE_RIDER_STATUSES,
} from '@pokbon-delivery/shared';
import { JobsService } from '../jobs/jobs.service';
import { OutboxService } from '../outbox/outbox.service';
import { PrismaService } from '../prisma/prisma.service';
import { SettingsService } from '../settings/settings.service';

/**
 * The offer cascade. PRD § 6. One rider at a time, short timeout, then the
 * next. Harvested from AutoRescue's dispatch, reduced to what delivery needs.
 *
 * Candidates: approved, on duty, agreement current, vehicle class active,
 * under their concurrency cap, not already offered this job, and either
 * within the offer radius of the pickup on a fresh position or based in the
 * pickup zone. Nearest first.
 */
@Injectable()
export class DispatchService {
  private readonly logger = new Logger(DispatchService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
    private readonly jobs: JobsService,
    private readonly outbox: OutboxService,
  ) {}

  /** Offer to the next eligible rider. No-op once assigned. Returns the offer or null. */
  async offerNext(jobId: string, actor = 'system') {
    const job = await this.jobs.mustFind(jobId);
    if (!([JobStatus.CREATED, JobStatus.OFFERED, JobStatus.UNFULFILLED] as string[]).includes(job.status)) return null;

    const live = await this.prisma.jobOffer.count({ where: { jobId, status: OfferStatus.OFFERED, expiresAt: { gt: new Date() } } });
    if (live > 0) return null;

    const depth = this.settings.get('offer_cascade_depth');
    const tried = await this.prisma.jobOffer.findMany({ where: { jobId }, select: { riderId: true, sequence: true, status: true } });

    /*
     * "They never answered" is not "they said no".
     *
     * Every previous offer used to bar its rider from seeing this job again,
     * which is right while the cascade is walking down a list — the point is
     * to reach somebody else. It is wrong afterwards. An offer lapses because
     * the rider was riding, or their signal dropped, or the window is forty
     * five seconds long; they never saw it. On a small roster that left the
     * one available rider permanently locked out of a job, and the dispatcher
     * pressing Offer again was told "nobody eligible" with a rider sitting on
     * duty a kilometre from the pickup.
     *
     * A decline stands: that rider looked at the work and refused it, and
     * pushing it back at them is how you lose riders. A lapse does not.
     */
    const refused = tried
      .filter((offer) => offer.status === OfferStatus.DECLINED)
      .map((offer) => offer.riderId);
    /*
     * Depth counts riders, not attempts.
     *
     * It is "how far down the list do we walk before a human should look at
     * this", and now that a lapsed offer can go back to the same rider, three
     * retries to one rider is not three riders. Counting attempts would send
     * a job to UNFULFILLED while most of the roster had never seen it.
     */
    const ridersTried = new Set(tried.map((offer) => offer.riderId)).size;
    if (ridersTried >= depth) {
      if (job.status !== JobStatus.UNFULFILLED) {
        await this.prisma.$transaction(async (tx) => {
          await tx.job.updateMany({ where: { id: jobId, status: job.status }, data: { status: JobStatus.UNFULFILLED } });
          await tx.jobEvent.create({ data: { jobId, type: 'status.unfulfilled', actor, detail: { ridersTried, offers: tried.length } } });
          await this.outbox.enqueue('job.status', { jobId, orderId: job.externalRef, source: job.source, status: 'unfulfilled', at: new Date().toISOString() }, tx);
        });
        this.logger.warn(`Job ${jobId} unfulfilled after ${ridersTried} rider(s), ${tried.length} offer(s) — dispatcher must act`);
      }
      return null;
    }

    const candidate = await this.pickCandidate(job, refused);
    if (!candidate) {
      if (job.status !== JobStatus.UNFULFILLED) {
        await this.prisma.$transaction(async (tx) => {
          await tx.job.updateMany({ where: { id: jobId, status: job.status }, data: { status: JobStatus.UNFULFILLED } });
          await tx.jobEvent.create({ data: { jobId, type: 'status.unfulfilled', actor, detail: { reason: 'no_candidates', ridersTried, offers: tried.length } } });
          await this.outbox.enqueue('job.status', { jobId, orderId: job.externalRef, source: job.source, status: 'unfulfilled', at: new Date().toISOString() }, tx);
        });
        this.logger.warn(`Job ${jobId}: nobody eligible to offer to`);
      }
      return null;
    }

    const timeout = this.settings.get('offer_timeout_seconds');
    const sequence = (tried.reduce((m, t) => Math.max(m, t.sequence), 0) || 0) + 1;

    return this.prisma.$transaction(async (tx) => {
      /*
       * Upsert, because a rider gets one offer row per job.
       *
       * The unique key on (jobId, riderId) is the schema's version of the old
       * rule that a rider only ever sees a job once, so simply allowing the
       * re-offer above produced a constraint violation and a 500 in the
       * dispatcher's face. A lapsed offer is revived rather than duplicated:
       * same row, new window, next sequence number. The history of when it
       * was offered lives in the job's event log, which is where a dispatcher
       * looks anyway, so nothing is lost by reusing the row.
       */
      const offer = await tx.jobOffer.upsert({
        where: { jobId_riderId: { jobId, riderId: candidate.riderId } },
        create: {
          jobId,
          riderId: candidate.riderId,
          sequence,
          distanceMetres: candidate.distanceMetres,
          expiresAt: new Date(Date.now() + timeout * 1000),
        },
        update: {
          sequence,
          status: OfferStatus.OFFERED,
          distanceMetres: candidate.distanceMetres,
          offeredAt: new Date(),
          expiresAt: new Date(Date.now() + timeout * 1000),
          respondedAt: null,
          // A previous decline cannot reach here — declines still bar the
          // rider — but clearing it keeps the row honest about this offer.
          declineReason: null,
        },
      });
      if (job.status !== JobStatus.OFFERED) {
        await tx.job.updateMany({ where: { id: jobId, status: job.status }, data: { status: JobStatus.OFFERED } });
      }
      await tx.jobEvent.create({
        data: { jobId, type: 'offer.made', actor, detail: { riderId: candidate.riderId, sequence, distanceMetres: candidate.distanceMetres, timeout } },
      });
      // Rider push notification: phase 1. The app polls GET /rider/jobs/offers meanwhile.
      return offer;
    });
  }

  /** The rider accepts. Atomic claim; the concurrency cap is checked inside. */
  async accept(offerId: string, riderId: string): Promise<Job> {
    const offer = await this.prisma.jobOffer.findUnique({ where: { id: offerId }, include: { job: true, rider: true } });
    if (!offer) throw new NotFoundException('Offer not found');
    if (offer.riderId !== riderId) throw new ForbiddenException('This offer is not yours');
    if (offer.status !== OfferStatus.OFFERED) throw new ConflictException(`This offer is ${offer.status.toLowerCase()}`);
    if (offer.expiresAt < new Date()) throw new ConflictException('This offer has expired');
    if (!canTransition(offer.job.status as JobStatus, JobStatus.ASSIGNED)) {
      throw new ConflictException('This job is no longer available');
    }
    await this.jobs.assertConcurrency(riderId, offer.rider.completedJobs);

    return this.prisma.$transaction(async (tx) => {
      const claim = await tx.jobOffer.updateMany({
        where: { id: offerId, status: OfferStatus.OFFERED },
        data: { status: OfferStatus.ACCEPTED, respondedAt: new Date() },
      });
      if (claim.count !== 1) throw new ConflictException('This offer was already answered');
      return this.jobs.markAssigned(tx, offer.job, riderId);
    });
  }

  async decline(offerId: string, riderId: string, reason?: string): Promise<void> {
    const offer = await this.prisma.jobOffer.findUnique({ where: { id: offerId } });
    if (!offer) throw new NotFoundException('Offer not found');
    if (offer.riderId !== riderId) throw new ForbiddenException('This offer is not yours');
    const done = await this.prisma.jobOffer.updateMany({
      where: { id: offerId, status: OfferStatus.OFFERED },
      data: { status: OfferStatus.DECLINED, respondedAt: new Date(), declineReason: reason ?? null },
    });
    if (done.count === 1) {
      await this.prisma.jobEvent.create({ data: { jobId: offer.jobId, type: 'offer.declined', actor: `rider:${riderId}`, detail: { reason } } });
      await this.offerNext(offer.jobId);
    }
  }

  /** Called by the scheduler. Expires overdue offers and cascades each job once. */
  /**
   * Jobs that nobody could take at the time, tried again.
   *
   * offerNext() is only ever called when something happens: a job is created,
   * an offer expires, a dispatcher presses a button. Nothing ever asked again
   * on its own. So a job created at midnight, when every rider was off duty,
   * stayed at CREATED for ever — and a job whose cascade ran out of eligible
   * riders stayed at UNFULFILLED for ever — with no screen explaining why and
   * no event to wait for.
   *
   * Seen on order #87712: three jobs, one of which was never offered to anyone
   * because the only rider on duty was 14.78 km from that pickup and outside
   * the radius. Had they ridden closer an hour later, nothing would have
   * noticed. The rider toggled duty and signed in and out looking for work
   * that was sitting right there.
   *
   * Safe to run on a timer because offerNext() already refuses to act twice:
   * it returns null when a live offer exists, never re-offers to a rider who
   * declined, and respects the cascade depth. So this is "ask again", not
   * "ask harder" — nobody gets pestered.
   *
   * Bounded by age. A job nobody has taken in a day is not waiting for a
   * timer, it is waiting for a human, and retrying it for ever would hide
   * that rather than surface it.
   */
  async retryStranded(maxAgeHours = 24): Promise<number> {
    const since = new Date(Date.now() - maxAgeHours * 60 * 60 * 1000);

    const stranded = await this.prisma.job.findMany({
      where: {
        status: { in: [JobStatus.CREATED, JobStatus.UNFULFILLED] },
        createdAt: { gte: since },
        // Nothing live in front of a rider right now.
        offers: { none: { status: OfferStatus.OFFERED, expiresAt: { gt: new Date() } } },
      },
      select: { id: true },
      take: 50,
    });

    let offered = 0;
    for (const job of stranded) {
      try {
        if (await this.offerNext(job.id, 'retry-sweep')) offered += 1;
      } catch {
        // One unofferable job must not stop the others. The next sweep tries
        // again, and a job that keeps failing ages out of the window.
      }
    }
    return offered;
  }

  async expireOverdueOffers(): Promise<number> {
    const overdue = await this.prisma.jobOffer.findMany({
      where: { status: OfferStatus.OFFERED, expiresAt: { lt: new Date() } },
      take: 100,
    });
    let expired = 0;
    for (const offer of overdue) {
      const done = await this.prisma.jobOffer.updateMany({
        where: { id: offer.id, status: OfferStatus.OFFERED },
        data: { status: OfferStatus.EXPIRED, respondedAt: new Date() },
      });
      if (done.count !== 1) continue;
      expired++;
      await this.prisma.jobEvent.create({ data: { jobId: offer.jobId, type: 'offer.expired', actor: 'system', detail: { riderId: offer.riderId } } });
      await this.offerNext(offer.jobId);
    }
    return expired;
  }

  async openOffersFor(riderId: string) {
    return this.prisma.jobOffer.findMany({
      where: { riderId, status: OfferStatus.OFFERED, expiresAt: { gt: new Date() } },
      include: { job: true },
      orderBy: { offeredAt: 'asc' },
    });
  }

  private async pickCandidate(job: Job, excludeRiderIds: string[]): Promise<{ riderId: string; distanceMetres: number } | null> {
    const radius = this.settings.get('offer_radius_metres');
    const staleSeconds = this.settings.get('location_stale_seconds');
    const activeClasses = this.settings.get('active_vehicle_classes');
    const agreementVersion = this.settings.get('agreement_version');
    const caps = this.settings.get('concurrency_caps');
    const freshAfter = new Date(Date.now() - staleSeconds * 1000);

    const riders = await this.prisma.rider.findMany({
      where: {
        status: RiderStatus.APPROVED,
        onDuty: true,
        agreementVersion,
        vehicleClass: { in: activeClasses },
        id: { notIn: excludeRiderIds },
      },
      include: { location: true, _count: { select: { jobs: { where: { status: { in: [...ACTIVE_RIDER_STATUSES] } } } } } },
    });

    const pickup = { lat: job.pickupLat, lng: job.pickupLng };
    const ranked = riders
      .filter((r) => r._count.jobs < concurrencyCapFor(caps, r.completedJobs))
      .map((r) => {
        const fresh = r.location && r.location.updatedAt >= freshAfter;
        const distance = fresh ? haversineMetres(pickup, r.location!) : null;
        const inZone = job.pickupZoneCode !== null && r.baseZoneCode === job.pickupZoneCode;
        return { riderId: r.id, distance, inZone };
      })
      .filter((c) => (c.distance !== null && c.distance <= radius) || c.inZone)
      .sort((a, b) => (a.distance ?? radius + 1) - (b.distance ?? radius + 1));

    const best = ranked[0];
    return best ? { riderId: best.riderId, distanceMetres: best.distance ?? radius } : null;
  }
}
