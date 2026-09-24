import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  HttpException,
  HttpStatus,
  Injectable,
  Logger,
  NotFoundException,
} from '@nestjs/common';
import { Job, Prisma } from '@prisma/client';
import {
  ACTIVE_RIDER_STATUSES,
  applyMarkup,
  canTransition,
  concurrencyCapFor,
  CreateJobInput,
  FailureReason,
  JobSource,
  JobStatus,
  OfferStatus,
  PaymentMethod,
  renderMessage,
  RiderSource,
  RiderStatus,
} from '@pokbon-delivery/shared';
import { OutboxService } from '../outbox/outbox.service';
import { PluginClient } from '../plugin/plugin.client';
import { PricingService } from '../pricing/pricing.service';
import { PrismaService } from '../prisma/prisma.service';
import { fromMinor, SettingsService, toMinor } from '../settings/settings.service';
import { UploadsService } from '../uploads/uploads.service';
import { CodesService } from './codes.service';
import { commissionMinor } from './serialisers';

type Tx = Prisma.TransactionClient;

interface PhotoInput {
  kind: 'PICKUP' | 'DELIVERY' | 'FAILURE';
  contentType: string;
  base64: string;
}

/** Statuses the plugin is told about by SMS to the buyer (contract § 5). The plugin decides; this is the hint. */
const SMS_STATUSES = new Set<string>([
  JobStatus.ASSIGNED,
  JobStatus.PICKED_UP,
  JobStatus.CODE_SENT,
  JobStatus.DELIVERED,
  JobStatus.PAYMENT_FAILED,
  JobStatus.FAILED,
  JobStatus.CANCELLED,
]);

/**
 * The job state machine. PRD § 7. Everything that moves a job goes through
 * `transition`, which checks the table in @pokbon-delivery/shared, stamps the
 * time, writes the event and queues the callback in one transaction.
 */
@Injectable()
export class JobsService {
  private readonly logger = new Logger(JobsService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly pricing: PricingService,
    private readonly settings: SettingsService,
    private readonly codes: CodesService,
    private readonly plugin: PluginClient,
    private readonly outbox: OutboxService,
    private readonly uploads: UploadsService,
  ) {}

  // ---------------------------------------------------------------------------
  // Plugin-side: create, cancel, assign, payment outcome, bypass
  // ---------------------------------------------------------------------------

  /** Contract § 4. Idempotent on source + orderId + vendorId. */
  async createFromPlugin(input: CreateJobInput): Promise<{ job: Job; created: boolean }> {
    /*
     * Idempotent on source + orderId + vendorId, so a retried webhook cannot
     * put two riders on one parcel.
     *
     * A CANCELLED job is the exception. Cancelling is a deliberate "this one
     * is void", and the dispatcher's next move is to send the order again —
     * so handing the dead job back on that second attempt made cancel and
     * re-dispatch impossible, which is the workflow the admin screen offers.
     * Attempts after the first carry a #n suffix, so the original key and its
     * history stay exactly where they were.
     */
    const baseKey = `${input.source}:${input.orderId}:${input.vendorId ?? '-'}`;
    const attempts = await this.prisma.job.findMany({
      // Exact, or this key's own numbered attempts. A bare startsWith would
      // also match vendor 12 when asked about vendor 1.
      where: { OR: [{ idempotencyKey: baseKey }, { idempotencyKey: { startsWith: `${baseKey}#` } }] },
      orderBy: { createdAt: 'asc' },
    });

    const standing = attempts.find((job) => job.status !== JobStatus.CANCELLED);
    if (standing) return { job: standing, created: false };

    const idempotencyKey = attempts.length === 0 ? baseKey : `${baseKey}#${attempts.length + 1}`;

    const { quote, fromZoneCode, toZoneCode } = await this.pricing.quoteForPoints(input.pickup, input.dropoff);

    let riderFeeMinor: number;
    let buyerPriceMinor: number;
    let priceVersion: number | null;
    let priceRung: string | null;
    let priceMatched: string | null;
    if (input.pricing) {
      /*
       * The plugin's numbers win, and they are two different things.
       *
       * buyerPrice is what the customer actually paid for delivery, read off
       * the order. riderFee is what the route costs to ride, from the matrix.
       * They are not the same number and should not pretend to be: this used
       * to be labelled "quoted at checkout" for both, which was false for the
       * fee and hid a real loss behind a fictional margin.
       */
      riderFeeMinor = toMinor(input.pricing.riderFee);
      buyerPriceMinor = toMinor(input.pricing.buyerPrice);
      priceVersion = input.pricing.priceVersion ?? quote?.priceVersion ?? null;
      priceRung = 'plugin';
      priceMatched = 'charged at checkout; rider fee from the matrix';
    } else if (quote) {
      riderFeeMinor = quote.riderFeeMinor;
      buyerPriceMinor = quote.buyerPriceMinor;
      priceVersion = quote.priceVersion;
      priceRung = quote.rung;
      priceMatched = quote.matched;
    } else {
      throw new HttpException(
        {
          message: 'POKBON does not serve this route yet',
          fromZoneCode,
          toZoneCode,
        },
        HttpStatus.UNPROCESSABLE_ENTITY,
      );
    }

    const commission = this.pricing.commissionNow();

    const job = await this.prisma.$transaction(async (tx) => {
      const created = await tx.job.create({
        data: {
          idempotencyKey,
          source: input.source,
          externalRef: input.orderId,
          vendorId: input.vendorId ?? null,
          buyerUserId: input.buyerUserId ?? null,
          riderSource: input.riderSource,
          status: JobStatus.CREATED,
          pickupLat: input.pickup.lat,
          pickupLng: input.pickup.lng,
          pickupAddress: input.pickup.address,
          pickupZoneCode: fromZoneCode,
          pickupContactName: input.pickup.contactName,
          pickupContactPhone: input.pickup.contactPhone,
          pickupNote: input.pickup.note ?? null,
          dropoffLat: input.dropoff.lat,
          dropoffLng: input.dropoff.lng,
          dropoffAddress: input.dropoff.address,
          dropoffZoneCode: toZoneCode,
          dropoffGhanaPost: input.dropoff.ghanaPost ?? null,
          dropoffNote: input.dropoff.note ?? null,
          dropoffPinned: input.dropoff.pinned,
          dropoffContactName: input.dropoff.contactName,
          dropoffContactPhone: input.dropoff.contactPhone,
          parcelSize: input.parcel.sizeClass,
          parcelDescription: input.parcel.description ?? null,
          itemCount: input.parcel.itemCount,
          declaredValueMinor: toMinor(input.parcel.declaredValue),
          paymentMethod: input.payment.method,
          amountDueMinor: input.payment.method === PaymentMethod.PAY_ON_DELIVERY ? toMinor(input.payment.codAmount) : 0,
          riderFeeMinor,
          buyerPriceMinor,
          priceVersion,
          priceRung,
          priceMatched,
          commissionRateBps: commission.rateBps,
          commissionFlatMinor: commission.flatMinor,
        },
      });
      await this.event(tx, created.id, 'job.created', 'plugin', {
        source: input.source,
        paymentMethod: input.payment.method,
        riderSource: input.riderSource,
        pricedBy: priceRung,
        priceMatched,
      });
      return created;
    });

    this.logger.log(`Job ${job.id} created for ${input.source} ${input.orderId} (${job.pickupZoneCode} → ${job.dropoffZoneCode}, rider fee ${fromMinor(riderFeeMinor)})`);
    return { job, created: true };
  }

  async cancelFromPlugin(jobId: string, reason: string, actor: string): Promise<Job> {
    const job = await this.mustFind(jobId);
    return this.prisma.$transaction(async (tx) => {
      await tx.jobOffer.updateMany({
        where: { jobId, status: OfferStatus.OFFERED },
        data: { status: OfferStatus.WITHDRAWN, respondedAt: new Date() },
      });
      return this.transition(tx, job, JobStatus.CANCELLED, `plugin:${actor}`, {
        data: { cancelReason: reason, cancelledBy: actor, cancelledAt: new Date() },
        detail: { reason },
      });
    });
  }

  /** Dispatcher assigns by hand. Day one, this is the whole system (§ 6). */
  async assignManually(jobId: string, riderId: string, actor: string): Promise<Job> {
    const job = await this.mustFind(jobId);
    const rider = await this.prisma.rider.findUnique({ where: { id: riderId } });
    if (!rider) throw new NotFoundException('Rider not found');
    if (rider.status !== RiderStatus.APPROVED) throw new ConflictException('Rider is not approved');
    await this.assertConcurrency(riderId, rider.completedJobs);

    return this.prisma.$transaction(async (tx) => {
      await tx.jobOffer.updateMany({
        where: { jobId, status: OfferStatus.OFFERED },
        data: { status: OfferStatus.WITHDRAWN, respondedAt: new Date() },
      });

      /*
       * Moving a job off a rider who already has it.
       *
       * ASSIGNED -> ASSIGNED is not a legal transition, deliberately: a job
       * should not silently change hands. But a rider who accepted and then
       * went unreachable is an ordinary Tuesday, and until now a dispatcher
       * could only cancel the job and rebuild it.
       *
       * So it goes back through OFFERED, which IS legal from ASSIGNED, and
       * then on to the new rider. Two real transitions rather than one
       * illegal one: the job is taken back, then given out, and the event log
       * shows both — which is exactly what happened, and what somebody
       * reading it later needs to see.
       */
      let current = job;
      if (job.status === JobStatus.ASSIGNED && job.riderId !== riderId) {
        current = await this.transition(tx, job, JobStatus.OFFERED, `plugin:${actor}`, {
          data: { riderId: null },
          detail: { takenBackFrom: job.riderId, reason: 'reassigned by a dispatcher' },
        });
      }

      const updated = await this.transition(tx, current, JobStatus.ASSIGNED, `plugin:${actor}`, {
        data: { riderId, manualAssignedBy: actor, assignedAt: new Date() },
        detail: { riderId, manual: true, reassigned: current.id !== job.id || job.status === JobStatus.ASSIGNED },
      });
      await this.notifyAssigned(tx, updated);
      return updated;
    });
  }

  /** The rider accepted an offer. Called by DispatchService inside its own claim transaction. */
  async markAssigned(tx: Tx, job: Job, riderId: string): Promise<Job> {
    const updated = await this.transition(tx, job, JobStatus.ASSIGNED, `rider:${riderId}`, {
      data: { riderId, assignedAt: new Date() },
      detail: { riderId },
    });
    await this.notifyAssigned(tx, updated);
    return updated;
  }

  /** Contract § 4b: the plugin reports the doorstep payment outcome. */
  async paymentOutcome(
    jobId: string,
    outcome: { intentId: string; status: 'pending' | 'paid' | 'failed' | 'expired'; reference?: string; paidAt?: string; failureReason?: string },
    actor = 'plugin',
  ): Promise<Job> {
    const job = await this.mustFind(jobId);
    if (job.paymentIntentId && job.paymentIntentId !== outcome.intentId) {
      throw new ConflictException(`Intent ${outcome.intentId} is not this job's intent`);
    }
    if (outcome.status === 'pending') return job;

    // Already there: a replayed webhook must not do anything twice.
    if (outcome.status === 'paid' && (job.status === JobStatus.PAID || job.status === JobStatus.DELIVERED)) return job;

    return this.prisma.$transaction(async (tx) => {
      if (outcome.status === 'paid') {
        if (job.status !== JobStatus.PAYMENT_PENDING && job.status !== JobStatus.PAYMENT_FAILED && job.status !== JobStatus.CODE_VERIFIED) {
          throw new ConflictException(`Cannot record payment on a job in ${job.status}`);
        }
        // A webhook can land after the window closed, or before the prompt row
        // was written. Hop through PAYMENT_PENDING so the timeline stays honest.
        const from =
          job.status === JobStatus.PAYMENT_PENDING
            ? job
            : await this.transition(tx, job, JobStatus.PAYMENT_PENDING, `plugin:${actor}`, {
                detail: { lateWebhook: true },
                silent: true,
              });
        return this.transition(tx, from, JobStatus.PAID, `plugin:${actor}`, {
          data: {
            paymentStatus: 'paid',
            paymentRef: outcome.reference ?? null,
            paidAt: outcome.paidAt ? new Date(outcome.paidAt) : new Date(),
            paymentIntentId: outcome.intentId,
          },
          detail: { intentId: outcome.intentId, reference: outcome.reference },
          callbackExtra: { paymentRef: outcome.reference },
        });
      }
      if (job.status !== JobStatus.PAYMENT_PENDING) return job;
      return this.transition(tx, job, JobStatus.PAYMENT_FAILED, `plugin:${actor}`, {
        data: { paymentStatus: outcome.status },
        detail: { intentId: outcome.intentId, status: outcome.status, reason: outcome.failureReason },
        callbackExtra: { failureReason: outcome.failureReason ?? outcome.status },
      });
    });
  }

  /**
   * Dispatcher bypass of the code (§ 7). A separate, audited action with a
   * mandatory reason; the buyer is told it was confirmed by POKBON, not by code.
   */
  async bypassCode(jobId: string, reason: string, actor: string): Promise<Job> {
    const job = await this.mustFind(jobId);
    if (!([JobStatus.ARRIVED, JobStatus.CODE_SENT] as string[]).includes(job.status)) {
      throw new ConflictException(`Cannot bypass the code on a job in ${job.status}`);
    }
    const updated = await this.prisma.$transaction(async (tx) => {
      const from = job.status === JobStatus.ARRIVED
        ? await this.transition(tx, job, JobStatus.CODE_SENT, `plugin:${actor}`, { detail: { bypassPending: true }, silent: true })
        : job;
      return this.transition(tx, from, JobStatus.CODE_VERIFIED, `plugin:${actor}`, {
        data: { codeVerifiedAt: new Date(), codeBypassedBy: actor, codeBypassReason: reason },
        detail: { bypass: true, reason },
        callbackExtra: { codeBypassed: true, bypassReason: reason },
      });
    });
    return this.afterCodeVerified(updated, `plugin:${actor}`);
  }

  // ---------------------------------------------------------------------------
  // Rider-side actions
  // ---------------------------------------------------------------------------

  async atPickup(jobId: string, riderId: string): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    return this.prisma.$transaction((tx) =>
      this.transition(tx, job, JobStatus.AT_PICKUP, `rider:${riderId}`, { data: { atPickupAt: new Date() } }),
    );
  }

  async pickedUp(jobId: string, riderId: string, photo?: PhotoInput): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    const stored = photo ? await this.uploads.storeBase64(jobId, photo.contentType, photo.base64) : null;
    return this.prisma.$transaction(async (tx) => {
      if (stored) {
        await tx.jobPhoto.create({ data: { jobId, riderId, kind: 'PICKUP', url: stored.url, contentType: stored.contentType, bytes: stored.bytes } });
      }
      return this.transition(tx, job, JobStatus.PICKED_UP, `rider:${riderId}`, {
        data: { pickedUpAt: new Date() },
        detail: { photo: Boolean(stored) },
      });
    });
  }

  async enRoute(jobId: string, riderId: string): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    return this.prisma.$transaction((tx) => this.transition(tx, job, JobStatus.EN_ROUTE, `rider:${riderId}`));
  }

  async arrived(jobId: string, riderId: string, at?: { lat?: number; lng?: number }): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    return this.prisma.$transaction((tx) =>
      this.transition(tx, job, JobStatus.ARRIVED, `rider:${riderId}`, {
        data: { arrivedAt: new Date() },
        detail: at?.lat !== undefined ? { lat: at.lat, lng: at.lng } : undefined,
      }),
    );
  }

  /**
   * Send (or re-send) the code to the buyer. The rider's response carries no
   * code. The SMS goes through the plugin; for a POKBON order the inbox too.
   */
  async sendCode(jobId: string, riderId: string): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    if (!([JobStatus.ARRIVED, JobStatus.CODE_SENT] as string[]).includes(job.status)) {
      throw new ConflictException(`Cannot send a code from ${job.status}`);
    }

    return this.prisma.$transaction(async (tx) => {
      const code = await this.codes.issue(jobId, job.codeSends, tx);
      const amountLine =
        job.paymentMethod === PaymentMethod.PAY_ON_DELIVERY
          // GHS, not GH₵: the cedi sign is not in the GSM 7-bit alphabet and
          // these go out as type 0, so the network substitutes a question
          // mark. A customer asked at their door to approve "GH?150.00" is
          // being asked to trust a broken message. Unicode would carry the
          // symbol but halves the characters per segment and doubles the
          // cost, for a glyph nobody needs in an SMS.
          /*
           * Deliberately not "you will get a prompt".
           *
           * Whether a request reaches the handset is Paystack's decision, not
           * ours — some numbers come back needing a code submitted through the
           * API instead, and no prompt ever appears. Promising one and not
           * delivering it leaves a customer hunting through a MoMo menu that
           * has nothing in it. The payment message that follows says what is
           * actually happening for this charge, including a link if needed.
           */
          ? ` GHS ${fromMinor(job.amountDueMinor).toFixed(2)} to pay on MoMo, no cash - we will text you how to approve it.`
          : '';
      const message = renderMessage(
        this.settings.get('messages'),
        'delivery_code',
        'POKBON: your rider is at your door. Your delivery code is {code}. Read it to the rider only.{amount}',
        { code, amount: amountLine },
      );
      if (message === '') {
        // Nothing else confirms a delivery, so this is refused rather than
        // leaving a rider at a door with no way to finish.
        throw new ConflictException('Delivery code messages are switched off in POKBON Delivery → Messages.');
      }

      await this.outbox.enqueue('sms.send', { to: job.dropoffContactPhone, message, jobId, purpose: 'delivery_code' }, tx);
      if (job.source === JobSource.MARKETPLACE && job.buyerUserId) {
        await this.outbox.enqueue(
          'inbox.send',
          {
            buyerUserId: job.buyerUserId,
            title: 'Your rider has arrived',
            // THE CODE IS DELIBERATELY NOT IN HERE. The marketplace app's inbox
            // is filled by push notifications, so anything in this body is
            // readable on a locked screen by whoever is holding the phone —
            // which is exactly what the code exists to prevent (PRD § 7). The
            // SMS above carries the code; this only tells them to go and read it.
            body: `Your rider is at your door. Your delivery code has been sent to you by SMS — read it to the rider.${amountLine}`,
            jobId,
            orderId: job.externalRef,
            purpose: 'arrival_no_code',
          },
          tx,
        );
      }

      return this.transition(tx, job, JobStatus.CODE_SENT, `rider:${riderId}`, {
        data: { codeSends: { increment: 1 } },
        detail: { send: job.codeSends + 1 },
        // A re-send is CODE_SENT → CODE_SENT; the plugin still gets the callback
        // (the SMS with the code is what matters, and it is queued above).
      });
    });
  }

  /**
   * The rider typed what the buyer read out. The answer is matched or not;
   * on a match, pay on delivery moves straight to the payment prompt.
   */
  async verifyCode(jobId: string, riderId: string, attempt: string): Promise<{ job: Job; matched: boolean; outcome: string }> {
    const job = await this.mustOwn(jobId, riderId);
    if (job.status !== JobStatus.CODE_SENT) throw new ConflictException(`No code is outstanding on a job in ${job.status}`);

    const outcome = await this.codes.verify(jobId, attempt);
    if (outcome !== 'MATCHED') {
      await this.prisma.$transaction((tx) => this.event(tx, jobId, 'code.attempt', `rider:${riderId}`, { outcome }));
      if (outcome === 'LOCKED') {
        this.logger.warn(`Delivery code LOCKED on job ${jobId} for rider ${riderId} — dispatcher attention needed`);
      }
      return { job, matched: false, outcome };
    }

    const verified = await this.prisma.$transaction((tx) =>
      this.transition(tx, job, JobStatus.CODE_VERIFIED, `rider:${riderId}`, { data: { codeVerifiedAt: new Date() } }),
    );
    const next = await this.afterCodeVerified(verified, `rider:${riderId}`);
    return { job: next, matched: true, outcome };
  }

  /** Re-send the MoMo prompt. Same intent, same amount, never a second charge (§ 11). */
  async promptAgain(jobId: string, riderId: string): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    if (job.paymentMethod !== PaymentMethod.PAY_ON_DELIVERY) throw new ConflictException('This job is prepaid');
    if (!([JobStatus.CODE_VERIFIED, JobStatus.PAYMENT_PENDING, JobStatus.PAYMENT_FAILED] as string[]).includes(job.status)) {
      throw new ConflictException(`Cannot prompt for payment from ${job.status}`);
    }
    return this.requestPrompt(job, 'retry', `rider:${riderId}`);
  }

  /** Someone else pays: SMS a link for the same intent to another phone (§ 11 step 2). */
  async payByLink(jobId: string, riderId: string, phone: string): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    if (!job.paymentIntentId) throw new ConflictException('No payment has been requested yet');
    if (!([JobStatus.PAYMENT_PENDING, JobStatus.PAYMENT_FAILED] as string[]).includes(job.status)) {
      throw new ConflictException(`Cannot send a payment link from ${job.status}`);
    }
    await this.plugin.paymentLink(job.paymentIntentId, phone);

    /*
     * Record where it went, and whether that was somewhere else.
     *
     * A rider may legitimately send the link to a different phone — the number
     * on the order is often wrong or switched off and the person at the door
     * has another one. It stays allowed because forbidding it breaks the
     * honest case, and the worst a wrong number can do is ask a stranger to
     * pay an invoice they will ignore. But a rider who does this routinely is
     * standing between a customer and their money, so it is written down with
     * the number it was meant for.
     *
     * The delivery code is a different matter and is never redirected: it is
     * the only proof the goods reached the buyer rather than the rider.
     */
    const redirected = phone !== job.dropoffContactPhone;
    await this.prisma.$transaction((tx) =>
      this.event(tx, jobId, 'payment.link_sent', `rider:${riderId}`, {
        to: phone,
        onOrder: job.dropoffContactPhone,
        redirected,
      }),
    );
    if (redirected) {
      this.logger.warn(
        `Job ${jobId}: rider ${riderId} sent the payment link to ${phone}, not the number on the order`,
      );
    }
    return job;
  }

  /**
   * Hand over. Only from PAID, or from CODE_VERIFIED when prepaid. Releases
   * the rider's fee, consumes any failed-trip uplift, applies the commission
   * in force when the job was created.
   */
  async delivered(jobId: string, riderId: string, photo?: PhotoInput): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    const prepaidOk = job.status === JobStatus.CODE_VERIFIED && job.paymentMethod === PaymentMethod.PREPAID;
    if (job.status !== JobStatus.PAID && !prepaidOk) {
      throw new ConflictException(
        job.paymentMethod === PaymentMethod.PAY_ON_DELIVERY
          ? 'The buyer has not paid yet. Do not hand over the item.'
          : `Cannot deliver from ${job.status}`,
      );
    }
    const stored = photo ? await this.uploads.storeBase64(jobId, photo.contentType, photo.base64) : null;

    return this.prisma.$transaction(async (tx) => {
      const rider = await tx.rider.findUniqueOrThrow({ where: { id: riderId } });

      /*
       * A trip that was completed is not a failed trip.
       *
       * FAILED -> EN_ROUTE -> DELIVERED is the honest version of the same path
       * the loop abused: the customer was unreachable, rang back, and the
       * rider finished. Without this the rider collects the full fee for the
       * delivery AND the compensation for failing it — paid for one journey
       * twice, which is not what the uplift is for.
       *
       * So this job's own credit comes back out of the pot before the pot is
       * paid. Uplift earned on OTHER failed jobs is untouched and still pays
       * out here, which is the whole design: you are compensated on your next
       * delivery for the trip that went nowhere.
       */
      const ownCredit = job.upliftGeneratedMinor;
      const upliftMinor = Math.max(0, rider.pendingUpliftMinor - ownCredit);
      const commission = commissionMinor(job);

      if (stored) {
        await tx.jobPhoto.create({ data: { jobId, riderId, kind: 'DELIVERY', url: stored.url, contentType: stored.contentType, bytes: stored.bytes } });
      }

      await tx.riderEarning.create({ data: { riderId, jobId, type: 'FEE', amountMinor: job.riderFeeMinor, note: `Delivery ${job.pickupZoneCode ?? '?'} → ${job.dropoffZoneCode ?? '?'}` } });
      if (upliftMinor > 0) {
        await tx.riderEarning.create({ data: { riderId, jobId, type: 'UPLIFT', amountMinor: upliftMinor, note: 'Failed-trip uplift' } });
      }
      if (commission > 0) {
        await tx.riderEarning.create({ data: { riderId, jobId, type: 'COMMISSION', amountMinor: -commission, note: `Commission ${job.commissionRateBps / 100}%` } });
      }
      await tx.rider.update({
        where: { id: riderId },
        data: { completedJobs: { increment: 1 }, pendingUpliftMinor: 0 },
      });

      return this.transition(tx, job, JobStatus.DELIVERED, `rider:${riderId}`, {
        // upliftGeneratedMinor is cleared: this job is settled, and leaving a
        // stale credit on it would let a later correction pay it twice.
        data: { deliveredAt: new Date(), upliftMinor, upliftGeneratedMinor: 0 },
        detail: { photo: Boolean(stored), riderFeeMinor: job.riderFeeMinor, upliftMinor, commissionMinor: commission },
        callbackExtra: {
          deliveryFee: fromMinor(job.buyerPriceMinor),
          riderFee: fromMinor(job.riderFeeMinor),
          paymentRef: job.paymentRef,
        },
      });
    });
  }

  /**
   * Could not deliver. Fixed reason, optional photo. Marketplace job with a
   * POKBON rider: credit the failed-trip uplift for the rider's next delivery.
   * Standalone: nothing here; the return fee is between rider and sender (§ 11).
   */
  async failed(jobId: string, riderId: string, input: { reason: FailureReason; detail?: string; photo?: PhotoInput }): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    if (input.reason === FailureReason.REFUSED_DAMAGED && !input.photo) {
      throw new BadRequestException('A photo is required when goods are refused as damaged');
    }
    const stored = input.photo ? await this.uploads.storeBase64(jobId, input.photo.contentType, input.photo.base64) : null;

    return this.prisma.$transaction(async (tx) => {
      if (stored) {
        await tx.jobPhoto.create({ data: { jobId, riderId, kind: 'FAILURE', url: stored.url, contentType: stored.contentType, bytes: stored.bytes } });
      }
      /*
       * Once per job, not once per failure.
       *
       * FAILED -> EN_ROUTE is a legal transition, and deliberately so: a
       * customer who was unreachable rings back and the rider finishes the
       * delivery. But the credit was unconditional, so failing, resuming and
       * failing again paid the uplift every lap — two authenticated calls per
       * iteration, unbounded, on a single job. Nothing capped it and nothing
       * recorded that it had already been paid.
       *
       * upliftGeneratedMinor is the record. It is on the JOB rather than a
       * counter on the rider because "has this trip already been compensated"
       * is a fact about the trip, and a bare running total cannot answer it.
       */
      let upliftCredited = 0;
      const alreadyCredited = job.upliftGeneratedMinor > 0;
      if (!alreadyCredited && job.source === JobSource.MARKETPLACE && job.riderSource === RiderSource.POKBON) {
        // The uplift is the markup on the rider fee, credited for the NEXT delivery (§ 11).
        upliftCredited = applyMarkup(job.riderFeeMinor, this.settings.get('failed_trip_uplift')) - job.riderFeeMinor;
        if (upliftCredited > 0) {
          await tx.rider.update({ where: { id: riderId }, data: { pendingUpliftMinor: { increment: upliftCredited } } });
        }
      }

      return this.transition(tx, job, JobStatus.FAILED, `rider:${riderId}`, {
        data: {
          failedAt: new Date(),
          failureReason: input.reason,
          failureDetail: input.detail ?? null,
          ...(upliftCredited > 0 ? { upliftGeneratedMinor: upliftCredited } : {}),
        },
        detail: {
          reason: input.reason,
          detail: input.detail,
          photo: Boolean(stored),
          upliftCreditedMinor: upliftCredited,
          // Visible in the event log, so a rider asking why a second failure
          // paid nothing gets an answer rather than a shrug.
          alreadyCredited,
        },
        callbackExtra: { failureReason: input.reason },
      });
    });
  }

  /**
   * The order behind this job has been called off. Work out what that means.
   *
   * The marketplace can cancel an order at any moment — a customer asks, a
   * vendor runs out, somebody in the office decides. Until now nothing told
   * the delivery service, so a rider carried on riding to a customer for an
   * order that no longer existed, and the first anyone knew was a doorstep
   * conversation.
   *
   * The decision is here rather than in the plugin because it depends on the
   * lifecycle, and the lifecycle lives here:
   *
   *   - before a rider has the goods, the job is simply called off;
   *   - once a rider is carrying the parcel, it cannot be. Where a parcel
   *     physically is cannot be undone by a status change, so the job fails
   *     with ORDER_CANCELLED and the rider's screen tells them to take it
   *     back — the same failed-then-returned path a refused delivery uses,
   *     which keeps the goods accounted for;
   *   - a job that has already finished is left alone.
   *
   * A rider who had accepted the job is credited the failed-trip uplift either
   * way. They rode somewhere for a delivery that was called off by somebody
   * else, which is precisely the situation that uplift exists for. A
   * dispatcher calling off a job they created by mistake does NOT go through
   * here, so that case is unchanged.
   */
  async recallForOrderCancellation(jobId: string, reason: string, actor: string): Promise<{ job: Job; outcome: 'cancelled' | 'recalled' | 'ignored' }> {
    const job = await this.mustFind(jobId);

    if (!canTransition(job.status as JobStatus, JobStatus.CANCELLED) && !canTransition(job.status as JobStatus, JobStatus.FAILED)) {
      // Delivered, returned, already cancelled. Nothing to undo.
      return { job, outcome: 'ignored' };
    }

    const carrying = !canTransition(job.status as JobStatus, JobStatus.CANCELLED);
    const accepted = job.riderId !== null;

    return this.prisma.$transaction(async (tx) => {
      await tx.jobOffer.updateMany({
        where: { jobId, status: OfferStatus.OFFERED },
        data: { status: OfferStatus.WITHDRAWN, respondedAt: new Date() },
      });

      /*
       * Same rule as failed(): once per job.
       *
       * A job can reach here after already having been compensated — failed,
       * resumed, and then the order cancelled underneath it — and paying twice
       * for one wasted journey is the same fault wearing a different hat.
       */
      let recallUplift = 0;
      if (
        accepted &&
        job.upliftGeneratedMinor === 0 &&
        job.source === JobSource.MARKETPLACE &&
        job.riderSource === RiderSource.POKBON &&
        job.riderId
      ) {
        recallUplift = applyMarkup(job.riderFeeMinor, this.settings.get('failed_trip_uplift')) - job.riderFeeMinor;
        if (recallUplift > 0) {
          await tx.rider.update({ where: { id: job.riderId }, data: { pendingUpliftMinor: { increment: recallUplift } } });
        }
      }

      if (carrying) {
        const updated = await this.transition(tx, job, JobStatus.FAILED, `plugin:${actor}`, {
          data: {
            failedAt: new Date(),
            failureReason: FailureReason.ORDER_CANCELLED,
            failureDetail: reason,
            ...(recallUplift > 0 ? { upliftGeneratedMinor: recallUplift } : {}),
          },
          detail: { reason, recalled: true, byOrderCancellation: true },
          callbackExtra: { failureReason: FailureReason.ORDER_CANCELLED },
        });
        return { job: updated, outcome: 'recalled' as const };
      }

      const updated = await this.transition(tx, job, JobStatus.CANCELLED, `plugin:${actor}`, {
        data: {
          cancelReason: reason,
          cancelledBy: actor,
          cancelledAt: new Date(),
          ...(recallUplift > 0 ? { upliftGeneratedMinor: recallUplift } : {}),
        },
        detail: { reason, byOrderCancellation: true },
      });
      return { job: updated, outcome: 'cancelled' as const };
    });
  }

  /**
   * Goods are back with the vendor or sender, recorded by a dispatcher.
   *
   * The rider's own returned() needs the rider: it proves who carried the
   * parcel back. But a rider whose phone has died, or who has stopped
   * answering, leaves a FAILED job that nobody can close — the goods are
   * accounted for in the real world and the system says the delivery is still
   * open for ever. So a dispatcher can close it, and the record says it was
   * them rather than pretending the rider did it.
   */
  async returnedFromPlugin(jobId: string, reason: string, actor: string): Promise<Job> {
    const job = await this.mustFind(jobId);
    return this.prisma.$transaction((tx) =>
      this.transition(tx, job, JobStatus.RETURNED, `plugin:${actor}`, {
        data: { returnedAt: new Date() },
        detail: { detail: reason, closedBy: actor, byDispatcher: true },
      }),
    );
  }

  /** Goods are back with the vendor or sender. Terminal. */
  async returned(jobId: string, riderId: string, input: { detail?: string; photo?: PhotoInput }): Promise<Job> {
    const job = await this.mustOwn(jobId, riderId);
    const stored = input.photo ? await this.uploads.storeBase64(jobId, input.photo.contentType, input.photo.base64) : null;
    return this.prisma.$transaction(async (tx) => {
      if (stored) {
        await tx.jobPhoto.create({ data: { jobId, riderId, kind: 'FAILURE', url: stored.url, contentType: stored.contentType, bytes: stored.bytes } });
      }
      return this.transition(tx, job, JobStatus.RETURNED, `rider:${riderId}`, {
        data: { returnedAt: new Date() },
        detail: { detail: input.detail, photo: Boolean(stored) },
      });
    });
  }

  /**
   * Ask the plugin whether a pending payment has landed yet.
   *
   * Payment normally reaches here by webhook: Paystack tells the site, the
   * site marks the order paid, and the plugin reports it. When that chain is
   * slow or broken nothing else looks until the payment window closes minutes
   * later — and then marks it failed, with a rider standing at a door and the
   * customer's money already gone.
   *
   * So the rider's own screen can settle it. Throttled to once every ten
   * seconds per job: the screen refreshes every five, and each call is a
   * round trip to WordPress and on to Paystack.
   */
  private readonly lastPaymentCheck = new Map<string, number>();

  async refreshPaymentIfPending(job: Job): Promise<Job> {
    if (job.status !== JobStatus.PAYMENT_PENDING || !job.paymentIntentId) return job;
    if (this.plugin.isConsole) return job;

    const last = this.lastPaymentCheck.get(job.id) ?? 0;
    if (Date.now() - last < 10_000) return job;
    this.lastPaymentCheck.set(job.id, Date.now());

    try {
      const status = await this.plugin.paymentStatus(job.paymentIntentId);
      if (status.status !== 'paid') return job;

      await this.paymentOutcome(
        job.id,
        { intentId: job.paymentIntentId, status: 'paid', reference: status.reference, paidAt: status.paidAt },
        'rider-check',
      );
      this.logger.log(`Job ${job.id}: payment confirmed by the rider's own refresh, ahead of the webhook`);
      return this.mustFind(job.id);
    } catch (error) {
      // Not worth failing the screen over: the rider still sees the job, and
      // the sweep and the webhook both remain.
      this.logger.warn(`Payment check failed for job ${job.id}: ${String(error)}`);
      return job;
    }
  }

  /** Payment window closed with no answer. Called by the scheduler. */
  async expireStalePayments(): Promise<number> {
    const waitMinutes = this.settings.get('payment_wait_minutes');
    const cutoff = new Date(Date.now() - waitMinutes * 60_000);
    const stale = await this.prisma.job.findMany({
      where: { status: JobStatus.PAYMENT_PENDING, paymentPendingSince: { lt: cutoff } },
      take: 50,
    });
    for (const job of stale) {
      // Ask the plugin first: the webhook may simply be late.
      if (job.paymentIntentId && !this.plugin.isConsole) {
        try {
          const status = await this.plugin.paymentStatus(job.paymentIntentId);
          if (status.status === 'paid') {
            await this.paymentOutcome(job.id, { intentId: job.paymentIntentId, status: 'paid', reference: status.reference, paidAt: status.paidAt }, 'sweep');
            continue;
          }
        } catch (error) {
          this.logger.warn(`Payment status check failed for job ${job.id}: ${String(error)}`);
        }
      }
      await this.prisma.$transaction((tx) =>
        this.transition(tx, job, JobStatus.PAYMENT_FAILED, 'system', {
          data: { paymentStatus: 'expired' },
          detail: { reason: 'wait_window_closed', waitMinutes },
          callbackExtra: { failureReason: 'expired' },
        }),
      );
    }
    return stale.length;
  }

  // ---------------------------------------------------------------------------
  // Queries
  // ---------------------------------------------------------------------------

  mustFind(jobId: string): Promise<Job> {
    return this.prisma.job.findUnique({ where: { id: jobId } }).then((j) => {
      if (!j) throw new NotFoundException('Job not found');
      return j;
    });
  }

  async mustOwn(jobId: string, riderId: string): Promise<Job> {
    const job = await this.mustFind(jobId);
    if (job.riderId !== riderId) throw new ForbiddenException('This job is not assigned to you');
    return job;
  }

  async activeForRider(riderId: string): Promise<Job[]> {
    return this.prisma.job.findMany({
      where: { riderId, status: { in: [...ACTIVE_RIDER_STATUSES] } },
      orderBy: { assignedAt: 'asc' },
    });
  }

  async assertConcurrency(riderId: string, completedJobs: number): Promise<void> {
    const cap = concurrencyCapFor(this.settings.get('concurrency_caps'), completedJobs);
    const holding = await this.prisma.job.count({ where: { riderId, status: { in: [...ACTIVE_RIDER_STATUSES] } } });
    if (holding >= cap) {
      throw new ConflictException(`This rider already holds ${holding} job(s); their limit is ${cap}`);
    }
  }

  // ---------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------

  /** After the code matched or was bypassed: prompt for payment, or nothing more to do when prepaid. */
  private async afterCodeVerified(job: Job, actor: string): Promise<Job> {
    if (job.paymentMethod !== PaymentMethod.PAY_ON_DELIVERY) return job;
    try {
      return await this.requestPrompt(job, 'arrival', actor);
    } catch (error) {
      // Stay in CODE_VERIFIED. The rider's screen offers "Send prompt again".
      this.logger.error(`Payment prompt failed for job ${job.id}: ${String(error)}`);
      await this.prisma.$transaction((tx) => this.event(tx, job.id, 'payment.prompt_failed', actor, { error: String(error).slice(0, 300) }));
      return this.mustFind(job.id);
    }
  }

  private async requestPrompt(job: Job, reason: 'arrival' | 'retry', actor: string): Promise<Job> {
    const max = this.settings.get('payment_max_prompts');
    if (job.promptCount >= max) {
      throw new ConflictException(`The prompt has been sent ${max} times. Use pay-by-link or mark the delivery failed.`);
    }
    const intent = await this.plugin.paymentPrompt({ jobId: job.id, orderId: job.externalRef, reason });
    return this.prisma.$transaction((tx) =>
      this.transition(tx, job, JobStatus.PAYMENT_PENDING, actor, {
        data: {
          paymentIntentId: intent.intentId,
          paymentStatus: intent.status,
          promptCount: { increment: 1 },
          paymentPendingSince: job.paymentPendingSince ?? new Date(),
        },
        detail: {
          intentId: intent.intentId,
          reason,
          prompt: job.promptCount + 1,
          // What the customer was actually asked to do, and whether they were
          // given a link they can use when the handset request cannot finish.
          stage: intent.stage ?? null,
          instruction: intent.instruction ?? null,
          payUrl: intent.payUrl ?? null,
        },
      }),
    );
  }

  private async notifyAssigned(tx: Tx, job: Job): Promise<void> {
    const rider = job.riderId ? await tx.rider.findUnique({ where: { id: job.riderId } }) : null;
    const amountLine =
      job.paymentMethod === PaymentMethod.PAY_ON_DELIVERY
        // GHS and a plain hyphen: neither the cedi sign nor an em dash
        // survives the GSM 7-bit alphabet.
        ? ` Have GHS ${fromMinor(job.amountDueMinor).toFixed(2)} ready on MoMo - you will approve a prompt at the door, no cash.`
        : '';
    const message = renderMessage(
      this.settings.get('messages'),
      'assigned',
      'POKBON: {rider} is on the way with your delivery.{amount}',
      { rider: rider?.fullName ?? 'your rider', amount: amountLine },
    );
    // Unlike the codes, this one is a courtesy. Switched off, the delivery
    // still works, so it is skipped quietly rather than refused.
    if (message === '') return;

    await this.outbox.enqueue(
      'sms.send',
      {
        to: job.dropoffContactPhone,
        message,
        jobId: job.id,
        purpose: 'assigned',
      },
      tx,
    );
  }

  /**
   * The one way a job changes status. Checks the table, stamps, writes the
   * event, queues the plugin callback — all in the caller's transaction.
   */
  private async transition(
    tx: Tx,
    job: Job,
    to: JobStatus,
    actor: string,
    opts: {
      data?: Prisma.JobUncheckedUpdateManyInput;
      detail?: Record<string, unknown>;
      callbackExtra?: Record<string, unknown>;
      /** Skip the plugin callback (an intermediate hop). */
      silent?: boolean;
    } = {},
  ): Promise<Job> {
    const from = job.status as JobStatus;
    if (!canTransition(from, to)) {
      throw new ConflictException(`Cannot move a job from ${from} to ${to}`);
    }

    // Conditional update: two concurrent actions cannot both win.
    const result = await tx.job.updateMany({
      where: { id: job.id, status: from },
      data: { ...opts.data, status: to },
    });
    if (result.count !== 1) {
      throw new ConflictException(`Job changed while processing; it is no longer ${from}`);
    }
    const updated = await tx.job.findUniqueOrThrow({ where: { id: job.id }, include: { rider: { include: { location: true } } } });

    await this.event(tx, job.id, `status.${to.toLowerCase()}`, actor, { from, to, ...opts.detail });

    if (!opts.silent) {
      await this.outbox.enqueue(
        'job.status',
        {
          jobId: job.id,
          orderId: job.source === JobSource.MARKETPLACE ? job.externalRef : undefined,
          externalRef: job.externalRef,
          source: job.source,
          status: to.toLowerCase(),
          at: new Date().toISOString(),
          rider: updated.rider
            ? {
                name: updated.rider.fullName,
                photoUrl: updated.rider.photoUrl,
                rating: updated.rider.ratingAvg,
                vehicle: updated.rider.vehicleClass,
                phone: updated.rider.phone,
              }
            : undefined,
          position: updated.rider?.location
            ? { lat: updated.rider.location.lat, lng: updated.rider.location.lng, at: updated.rider.location.updatedAt.toISOString() }
            : undefined,
          smsHint: SMS_STATUSES.has(to),
          ...opts.callbackExtra,
        },
        tx,
      );
    }

    const { rider: _rider, ...plain } = updated;
    void _rider;
    return plain as Job;
  }

  private event(tx: Tx | PrismaService, jobId: string, type: string, actor: string, detail?: Record<string, unknown>) {
    return tx.jobEvent.create({ data: { jobId, type, actor, detail: (detail ?? undefined) as Prisma.InputJsonValue | undefined } });
  }
}
