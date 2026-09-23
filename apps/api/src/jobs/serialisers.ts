import type { Job, JobEvent, JobOffer, JobPhoto, Rider } from '@prisma/client';
import { canTransition, JobStatus } from '@pokbon-delivery/shared';
import { fromMinor } from '../settings/settings.service';

/**
 * What a dispatcher may do to this job, right now.
 *
 * Derived from the lifecycle rather than restated in the admin screen. The
 * plugin had handlers for cancelling, assigning and bypassing the code and
 * rendered a button for none of them, so a dispatcher looking at a stuck job
 * could see everything about it and do nothing — and the obvious fix, adding
 * buttons, would have meant a second copy of the transition table in PHP,
 * drifting out of step with this one.
 *
 * Offering an action the lifecycle will refuse is the same failure as
 * demanding a photo the app cannot take: a control that exists to be pressed
 * and then says no.
 */
function allowedActionsFor(status: string) {
  const from = status as JobStatus;
  return {
    /** Put it in front of a rider, or the next one. */
    offer: canTransition(from, JobStatus.OFFERED),
    /**
     * Hand it to a named rider — including one who already has a rider.
     *
     * ASSIGNED -> ASSIGNED is not legal, and stays that way. assignManually()
     * takes an assigned job back through OFFERED first, which is legal, so a
     * dispatcher can move a job off a rider who has gone unreachable without
     * cancelling and rebuilding it.
     */
    assign: canTransition(from, JobStatus.ASSIGNED) || from === JobStatus.ASSIGNED,
    /** Call it off. Only before the goods are in a rider's hands. */
    cancel: canTransition(from, JobStatus.CANCELLED),
    /** Goods are back with the vendor or sender. Closes a failed job. */
    markReturned: canTransition(from, JobStatus.RETURNED),
    /** Accept the delivery without the code. Audited, reason required. */
    bypassCode: canTransition(from, JobStatus.CODE_VERIFIED),
  };
}

/**
 * What a rider may see of a job. PRD § 9c.
 *
 * Structural, not a flag: buyerPriceMinor, amountDueMinor, declaredValueMinor
 * and buyerUserId are simply not read here. A rider sees their fee, any uplift
 * on it, the addresses, the buyer's phone (§ 13), and the state of the doorstep
 * payment as pending / paid / failed — never the amount.
 */
export function toRiderJobView(job: Job, extras: { photos?: JobPhoto[] } = {}) {
  return {
    id: job.id,
    source: job.source,
    status: job.status,
    paymentMethod: job.paymentMethod,
    parcel: { size: job.parcelSize, itemCount: job.itemCount, description: job.parcelDescription },
    pickup: {
      lat: job.pickupLat,
      lng: job.pickupLng,
      address: job.pickupAddress,
      zoneCode: job.pickupZoneCode,
      contactName: job.pickupContactName,
      contactPhone: job.pickupContactPhone,
      note: job.pickupNote,
    },
    dropoff: {
      lat: job.dropoffLat,
      lng: job.dropoffLng,
      address: job.dropoffAddress,
      zoneCode: job.dropoffZoneCode,
      ghanaPost: job.dropoffGhanaPost,
      note: job.dropoffNote,
      /** False: lat/lng are a zone centre. Navigate by address, not by point. */
      pinned: job.dropoffPinned,
      contactName: job.dropoffContactName,
      contactPhone: job.dropoffContactPhone,
    },
    earnings: {
      riderFee: fromMinor(job.riderFeeMinor),
      uplift: fromMinor(job.upliftMinor),
      commission: fromMinor(commissionMinor(job)),
      total: fromMinor(job.riderFeeMinor + job.upliftMinor - commissionMinor(job)),
      currency: 'GHS',
    },
    payment: {
      /** pending | paid | failed | expired | null */
      status: job.paymentStatus,
      promptCount: job.promptCount,
      pendingSince: job.paymentPendingSince,
    },
    code: { sends: job.codeSends, bypassed: Boolean(job.codeBypassedBy) },
    failure: job.failureReason ? { reason: job.failureReason, detail: job.failureDetail } : null,
    timeline: {
      createdAt: job.createdAt,
      assignedAt: job.assignedAt,
      atPickupAt: job.atPickupAt,
      pickedUpAt: job.pickedUpAt,
      arrivedAt: job.arrivedAt,
      codeVerifiedAt: job.codeVerifiedAt,
      paidAt: job.paidAt,
      deliveredAt: job.deliveredAt,
      failedAt: job.failedAt,
      returnedAt: job.returnedAt,
    },
    photos: extras.photos?.map((p) => ({ kind: p.kind, url: p.url, at: p.createdAt })) ?? [],
  };
}

/** An offer as the rider sees it: where, how big, what it pays. Nothing about the buyer's price. */
export function toRiderOfferView(offer: JobOffer & { job: Job }) {
  return {
    offerId: offer.id,
    jobId: offer.jobId,
    sequence: offer.sequence,
    expiresAt: offer.expiresAt,
    distanceMetres: offer.distanceMetres,
    pickup: { zoneCode: offer.job.pickupZoneCode, address: offer.job.pickupAddress, lat: offer.job.pickupLat, lng: offer.job.pickupLng },
    dropoff: { zoneCode: offer.job.dropoffZoneCode, address: offer.job.dropoffAddress, lat: offer.job.dropoffLat, lng: offer.job.dropoffLng },
    parcel: { size: offer.job.parcelSize, itemCount: offer.job.itemCount, description: offer.job.parcelDescription },
    paymentMethod: offer.job.paymentMethod,
    /*
     * The same shape, and the same `total`, as the accepted job.
     *
     * The offer used to carry the gross fee alone while the job screen showed
     * it net of commission, so a rider accepted GH¢40 of work and then found
     * GH¢36. An offer is the moment somebody consents to a price; it has to
     * be the price they are paid, and the deduction has to be visible before
     * they tap rather than after.
     */
    earnings: {
      riderFee: fromMinor(offer.job.riderFeeMinor),
      uplift: fromMinor(offer.job.upliftMinor),
      commission: fromMinor(commissionMinor(offer.job)),
      total: fromMinor(offer.job.riderFeeMinor + offer.job.upliftMinor - commissionMinor(offer.job)),
      currency: 'GHS',
    },
  };
}

/** Everything, for the admin plugin. */
export function toAdminJobView(
  job: Job & { rider?: Rider | null; events?: JobEvent[]; photos?: JobPhoto[]; offers?: (JobOffer & { rider?: Rider })[] },
) {
  return {
    ...job,
    money: {
      riderFee: fromMinor(job.riderFeeMinor),
      buyerPrice: fromMinor(job.buyerPriceMinor),
      margin: fromMinor(job.buyerPriceMinor - job.riderFeeMinor),
      amountDue: fromMinor(job.amountDueMinor),
      commission: fromMinor(commissionMinor(job)),
      uplift: fromMinor(job.upliftMinor),
      declaredValue: fromMinor(job.declaredValueMinor),
      currency: 'GHS',
      /** Why this fee, in words. A price nobody can explain is a price nobody trusts. */
      pricedBy: job.priceRung,
      priceMatched: job.priceMatched,
    },
    rider: job.rider ? toAdminRiderSummary(job.rider) : null,
    allowedActions: allowedActionsFor(job.status),
    events: job.events ?? [],
    photos: job.photos ?? [],
    offers:
      job.offers?.map((o) => ({
        id: o.id,
        sequence: o.sequence,
        status: o.status,
        distanceMetres: o.distanceMetres,
        offeredAt: o.offeredAt,
        expiresAt: o.expiresAt,
        respondedAt: o.respondedAt,
        declineReason: o.declineReason,
        rider: o.rider ? toAdminRiderSummary(o.rider) : { id: o.riderId },
      })) ?? [],
  };
}

export function toAdminRiderSummary(r: Rider) {
  return {
    id: r.id,
    fullName: r.fullName,
    phone: r.phone,
    status: r.status,
    vehicleClass: r.vehicleClass,
    vehicleRegistration: r.vehicleRegistration,
    baseZoneCode: r.baseZoneCode,
    photoUrl: r.photoUrl,
    onDuty: r.onDuty,
    completedJobs: r.completedJobs,
    ratingAvg: r.ratingAvg,
    idVerificationLevel: r.idVerificationLevel,
  };
}

/** What the buyer's tracking proxy gets (contract § 5). No code, no rider phone unless assigned. */
export function toTrackingView(job: Job & { rider?: (Rider & { location?: { lat: number; lng: number; updatedAt: Date } | null }) | null }) {
  const pos = job.rider?.location;
  return {
    status: job.status,
    rider: job.rider
      ? {
          name: job.rider.fullName,
          photoUrl: job.rider.photoUrl,
          rating: job.rider.ratingAvg,
          vehicle: job.rider.vehicleClass,
          phone: job.rider.phone,
        }
      : null,
    position: pos ? { lat: pos.lat, lng: pos.lng, at: pos.updatedAt } : null,
    updatedAtSeconds: pos ? Math.floor((Date.now() - pos.updatedAt.getTime()) / 1000) : null,
    etaMinutes: null,
    code: null,
  };
}

export function commissionMinor(job: Job): number {
  return Math.round((job.riderFeeMinor * job.commissionRateBps) / 10_000) + job.commissionFlatMinor;
}
