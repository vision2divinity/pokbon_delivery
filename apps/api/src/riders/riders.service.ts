import { BadRequestException, ConflictException, Injectable, Logger, NotFoundException } from '@nestjs/common';
import { Rider } from '@prisma/client';
import { ACTIVE_RIDER_STATUSES, OfferStatus, RiderStatus, RiderUpsertInput } from '@pokbon-delivery/shared';
import { AuthService } from '../auth/auth.service';
import { PrismaService } from '../prisma/prisma.service';
import { fromMinor, SettingsService } from '../settings/settings.service';

/** What a rider must have before they can submit for review. PRD § 4a. */
const REQUIRED_FOR_APPLICATION: (keyof Rider)[] = [
  'fullName',
  'vehicleClass',
  'vehicleRegistration',
  'baseZoneCode',
  'idType',
  'idNumber',
  'licenceNumber',
  'momoNumber',
  'photoUrl',
  'idPhotoUrl',
  'licencePhotoUrl',
  'agreementVersion',
];

@Injectable()
export class RidersService {
  private readonly logger = new Logger(RidersService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
    private readonly auth: AuthService,
  ) {}

  async me(riderId: string) {
    const rider = await this.prisma.rider.findUnique({ where: { id: riderId }, include: { location: true } });
    if (!rider) throw new NotFoundException('Rider not found');
    const [balance, active] = await Promise.all([this.balanceMinor(riderId), this.activeJobCount(riderId)]);
    const currentAgreement = this.settings.get('agreement_version');
    return {
      id: rider.id,
      phone: rider.phone,
      fullName: rider.fullName,
      status: rider.status,
      vehicleClass: rider.vehicleClass,
      vehicleRegistration: rider.vehicleRegistration,
      baseZoneCode: rider.baseZoneCode,
      idType: rider.idType,
      idVerificationLevel: rider.idVerificationLevel,
      licenceNumber: rider.licenceNumber,
      momoNumber: rider.momoNumber,
      nextOfKinName: rider.nextOfKinName,
      nextOfKinPhone: rider.nextOfKinPhone,
      photoUrl: rider.photoUrl,
      idPhotoUrl: rider.idPhotoUrl,
      licencePhotoUrl: rider.licencePhotoUrl,
      agreement: {
        accepted: rider.agreementVersion,
        current: currentAgreement,
        upToDate: rider.agreementVersion === currentAgreement,
        acceptedAt: rider.agreementAcceptedAt,
      },
      onDuty: rider.onDuty,
      completedJobs: rider.completedJobs,
      rating: rider.ratingAvg,
      activeJobs: active,
      pendingUplift: fromMinor(rider.pendingUpliftMinor),
      balance: fromMinor(balance),
      currency: 'GHS',
      missingForApplication: REQUIRED_FOR_APPLICATION.filter((k) => !rider[k]),
      /** Shown at enrolment and in the app so a later change is a scheduled one, not a surprise (§ 9d). */
      commissionSchedule: this.settings.get('rider_commission_schedule'),
      lastLocationAt: rider.location?.updatedAt ?? null,
    };
  }

  async updateProfile(riderId: string, data: Record<string, unknown>) {
    const rider = await this.mustFind(riderId);
    if (([RiderStatus.SUSPENDED, RiderStatus.LEFT, RiderStatus.REJECTED] as string[]).includes(rider.status)) {
      throw new ConflictException(`Profile cannot be edited while ${rider.status.toLowerCase()}`);
    }
    await this.prisma.rider.update({ where: { id: riderId }, data });
    return this.me(riderId);
  }

  async acceptAgreement(riderId: string, version: string) {
    const current = this.settings.get('agreement_version');
    if (version !== current) throw new BadRequestException(`The current agreement is version ${current}`);
    await this.prisma.rider.update({ where: { id: riderId }, data: { agreementVersion: version, agreementAcceptedAt: new Date() } });
    return this.me(riderId);
  }

  /** DRAFT → APPLIED. Into the admin queue. */
  async submitApplication(riderId: string) {
    const rider = await this.mustFind(riderId);
    if (rider.status !== RiderStatus.DRAFT && rider.status !== RiderStatus.REJECTED) {
      throw new ConflictException(`Application is already ${rider.status.toLowerCase()}`);
    }
    const missing = REQUIRED_FOR_APPLICATION.filter((k) => !rider[k]);
    if (missing.length) throw new BadRequestException({ message: 'Application is incomplete', missing });
    if (rider.agreementVersion !== this.settings.get('agreement_version')) {
      throw new BadRequestException('Accept the current contractor agreement first');
    }
    await this.prisma.rider.update({ where: { id: riderId }, data: { status: RiderStatus.APPLIED, appliedAt: new Date() } });
    this.logger.log(`Rider ${riderId} applied`);
    return this.me(riderId);
  }

  /** On or off duty. Approved riders only, on the current agreement. */
  async setDuty(riderId: string, onDuty: boolean, at?: { lat?: number; lng?: number }) {
    const rider = await this.mustFind(riderId);
    if (onDuty) {
      if (rider.status !== RiderStatus.APPROVED) throw new ConflictException('Only approved riders can go on duty');
      if (rider.agreementVersion !== this.settings.get('agreement_version')) {
        throw new ConflictException('Accept the current contractor agreement before going on duty');
      }
    }
    await this.prisma.$transaction(async (tx) => {
      await tx.rider.update({ where: { id: riderId }, data: { onDuty, dutyChangedAt: new Date() } });
      if (onDuty && at?.lat !== undefined && at?.lng !== undefined) {
        await tx.riderLocation.upsert({
          where: { riderId },
          create: { riderId, lat: at.lat, lng: at.lng },
          update: { lat: at.lat, lng: at.lng },
        });
      }
      if (!onDuty) {
        // Open offers to someone who just went off duty should cascade, not sit.
        await tx.jobOffer.updateMany({
          where: { riderId, status: OfferStatus.OFFERED },
          data: { status: OfferStatus.WITHDRAWN, respondedAt: new Date(), declineReason: 'went_off_duty' },
        });
      }
    });
    return this.me(riderId);
  }

  /** Presence, not history: one row per rider. Accepted while on duty or holding a job. */
  async ping(riderId: string, lat: number, lng: number, accuracy?: number): Promise<{ accepted: boolean }> {
    const rider = await this.mustFind(riderId);
    const holding = rider.onDuty || (await this.activeJobCount(riderId)) > 0;
    if (!holding) return { accepted: false };
    await this.prisma.riderLocation.upsert({
      where: { riderId },
      create: { riderId, lat, lng, accuracy: accuracy !== undefined ? Math.round(accuracy) : null },
      update: { lat, lng, accuracy: accuracy !== undefined ? Math.round(accuracy) : null },
    });
    return { accepted: true };
  }

  /** Contractors can leave at any time (§ 10) — once nothing is in their hands. */
  async leave(riderId: string) {
    const active = await this.activeJobCount(riderId);
    if (active > 0) throw new ConflictException(`Finish or hand back ${active} active job(s) first`);
    await this.prisma.rider.update({ where: { id: riderId }, data: { status: RiderStatus.LEFT, onDuty: false } });
    await this.auth.revokeAllForRider(riderId);
    this.logger.log(`Rider ${riderId} left the platform`);
    return { left: true };
  }

  async earnings(riderId: string) {
    const entries = await this.prisma.riderEarning.findMany({ where: { riderId }, orderBy: { createdAt: 'desc' }, take: 200 });
    return {
      balance: fromMinor(entries.reduce((s, e) => s + e.amountMinor, 0)),
      currency: 'GHS',
      payoutCycle: this.settings.get('payout_cycle'),
      entries: entries.map((e) => ({ id: e.id, jobId: e.jobId, type: e.type, amount: fromMinor(e.amountMinor), note: e.note, at: e.createdAt })),
    };
  }

  // ---------------------------------------------------------------------------
  // Plugin side
  // ---------------------------------------------------------------------------

  async list(status?: string) {
    return this.prisma.rider.findMany({
      where: status ? { status } : undefined,
      include: { location: true },
      orderBy: [{ status: 'asc' }, { appliedAt: 'asc' }, { createdAt: 'asc' }],
    });
  }

  async detail(riderId: string) {
    const rider = await this.prisma.rider.findUnique({
      where: { id: riderId },
      include: {
        location: true,
        jobs: { orderBy: { createdAt: 'desc' }, take: 20 },
        earnings: { orderBy: { createdAt: 'desc' }, take: 50 },
      },
    });
    if (!rider) throw new NotFoundException('Rider not found');
    return { ...rider, balance: fromMinor(await this.balanceMinor(riderId)), pendingUplift: fromMinor(rider.pendingUpliftMinor) };
  }

  /**
   * Create or update a rider from the admin, by phone number.
   *
   * The normal path is self-signup, and it stays the normal path. This exists
   * because the first riders are recruited in person, with their licence on
   * the table — telling them to go home and find an app loses them.
   *
   * Keyed on the phone number, which is also the login identity, so this is
   * safe to run twice and safe to run on somebody who has already signed up.
   * An existing rider is updated, never duplicated, and nothing here can
   * demote somebody: a SUSPENDED or LEFT rider keeps that status until
   * somebody decides otherwise on the rider screen.
   */
  async upsertFromPlugin(input: RiderUpsertInput) {
    const existing = await this.prisma.rider.findUnique({ where: { phone: input.phone } });

    // Blank means "leave this as it is", never "erase it". Somebody re-adding a
    // rider to correct one field is adding information, not volunteering to
    // wipe the licence and next of kin they typed in last week. Removing a
    // detail is done on the rider's own screen, deliberately.
    const given = <T,>(value: T | undefined | null): T | undefined => {
      if (value === undefined || value === null) return undefined;
      if (typeof value === 'string' && value.trim() === '') return undefined;
      return value;
    };

    // Recording the agreement is a claim somebody made on a date, not a fact
    // about the database, so it is written with who said so. Without it the
    // rider simply accepts it in the app before going on duty, which is the
    // right fallback rather than a silent bypass.
    const alreadyAccepted = existing?.agreementAcceptedAt != null;
    const agreement = input.agreementSignedOnPaper
      ? {
          agreementVersion: this.settings.get('agreement_version'),
          agreementAcceptedAt: new Date(),
        }
      : {};

    // Only say something about the agreement when it is true. Leaving the box
    // unticked for a rider who has already accepted does not un-accept them,
    // and the note must not claim it did.
    const agreementLine = input.agreementSignedOnPaper
      ? `Agreement recorded as signed on paper by ${input.actor}.`
      : alreadyAccepted
        ? null
        : 'Agreement not yet accepted — the rider must accept it in the app before going on duty.';

    const note = [agreementLine, given(input.note)].filter(Boolean).join(' ');

    const profile = {
      fullName: given(input.fullName),
      vehicleClass: input.vehicleClass,
      vehicleRegistration: given(input.vehicleRegistration),
      baseZoneCode: given(input.baseZoneCode),
      momoNumber: given(input.momoNumber),
      licenceNumber: given(input.licenceNumber),
      idType: given(input.idType),
      idNumber: given(input.idNumber),
      nextOfKinName: given(input.nextOfKinName),
      nextOfKinPhone: given(input.nextOfKinPhone),
      // An empty note here means nothing new was said, so the existing one stands.
      reviewNote: given(note),
      decidedBy: input.actor,
      decidedAt: new Date(),
      ...agreement,
    };

    if (existing) {
      // Never quietly reinstate somebody who was suspended or who left. That
      // decision belongs on the rider screen, where it is deliberate.
      const locked: string[] = [RiderStatus.SUSPENDED, RiderStatus.LEFT, RiderStatus.REJECTED];
      const status = locked.includes(existing.status) ? existing.status : input.status;

      const updated = await this.prisma.rider.update({
        where: { id: existing.id },
        data: { ...profile, status },
      });

      this.logger.log(`Rider ${updated.id} updated from the admin by ${input.actor}`);
      return {
        rider: await this.detail(updated.id),
        created: false,
        statusHeld: status !== input.status ? status : null,
      };
    }

    const created = await this.prisma.rider.create({
      data: {
        phone: input.phone,
        status: input.status,
        appliedAt: new Date(),
        ...profile,
        fullName: input.fullName,
      },
    });

    this.logger.log(`Rider ${created.id} created from the admin by ${input.actor} (${input.phone})`);
    return { rider: await this.detail(created.id), created: true, statusHeld: null };
  }

  async decide(
    riderId: string,
    input: { decision: 'approve' | 'reject' | 'suspend' | 'reinstate'; note?: string; actor: string; idVerificationLevel?: string },
  ) {
    const rider = await this.mustFind(riderId);
    const allowed: Record<string, RiderStatus[]> = {
      approve: [RiderStatus.APPLIED, RiderStatus.SUSPENDED],
      reject: [RiderStatus.APPLIED, RiderStatus.DRAFT],
      suspend: [RiderStatus.APPROVED, RiderStatus.APPLIED],
      reinstate: [RiderStatus.SUSPENDED],
    };
    if (!allowed[input.decision].includes(rider.status as RiderStatus)) {
      throw new ConflictException(`Cannot ${input.decision} a rider who is ${rider.status.toLowerCase()}`);
    }
    const next: Record<string, RiderStatus> = {
      approve: RiderStatus.APPROVED,
      reject: RiderStatus.REJECTED,
      suspend: RiderStatus.SUSPENDED,
      reinstate: RiderStatus.APPROVED,
    };
    await this.prisma.$transaction(async (tx) => {
      await tx.rider.update({
        where: { id: riderId },
        data: {
          status: next[input.decision],
          reviewNote: input.note ?? null,
          decidedBy: input.actor,
          decidedAt: new Date(),
          ...(input.idVerificationLevel ? { idVerificationLevel: input.idVerificationLevel } : {}),
          ...(input.decision === 'suspend' ? { onDuty: false } : {}),
        },
      });
      if (input.decision === 'suspend') {
        await tx.jobOffer.updateMany({
          where: { riderId, status: OfferStatus.OFFERED },
          data: { status: OfferStatus.WITHDRAWN, respondedAt: new Date(), declineReason: 'suspended' },
        });
        await this.auth.revokeAllForRider(riderId);
      }
    });
    this.logger.log(`Rider ${riderId} ${input.decision}d by ${input.actor}`);
    return this.detail(riderId);
  }

  // ---------------------------------------------------------------------------

  private async mustFind(riderId: string): Promise<Rider> {
    const rider = await this.prisma.rider.findUnique({ where: { id: riderId } });
    if (!rider) throw new NotFoundException('Rider not found');
    return rider;
  }

  private async balanceMinor(riderId: string): Promise<number> {
    const agg = await this.prisma.riderEarning.aggregate({ where: { riderId }, _sum: { amountMinor: true } });
    return agg._sum.amountMinor ?? 0;
  }

  private activeJobCount(riderId: string): Promise<number> {
    return this.prisma.job.count({ where: { riderId, status: { in: [...ACTIVE_RIDER_STATUSES] } } });
  }
}
