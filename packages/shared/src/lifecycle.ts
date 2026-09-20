/**
 * The delivery lifecycle. PRD draft 3 § 7.
 *
 * Forward only. Corrections are new events, never rewrites. `DELIVERED` is
 * reachable only through `CODE_VERIFIED`, and only through `PAID` when payment
 * is due at the door. Those two rules are the product; everything in this file
 * exists to make them impossible to bypass by accident.
 */
export const JobStatus = {
  /** Created, not yet offered. Vendor set the order to processing, or a requester paid. */
  CREATED: 'CREATED',
  /** A cascade is running — one rider holds a live offer. */
  OFFERED: 'OFFERED',
  /** A rider accepted. Buyer sees name, photo, vehicle. */
  ASSIGNED: 'ASSIGNED',
  /** Rider reached the vendor or sender. */
  AT_PICKUP: 'AT_PICKUP',
  /** Rider confirmed collection, with a photo. Map goes live. */
  PICKED_UP: 'PICKED_UP',
  /** Moving to the drop-off. */
  EN_ROUTE: 'EN_ROUTE',
  /** Rider tapped Arrived. */
  ARRIVED: 'ARRIVED',
  /** Rider tapped Send code; the code went to the buyer. The rider never sees it. */
  CODE_SENT: 'CODE_SENT',
  /** The rider typed what the buyer read out and it matched, server-side. */
  CODE_VERIFIED: 'CODE_VERIFIED',
  /** The plugin pushed the MoMo prompt to the buyer's phone. */
  PAYMENT_PENDING: 'PAYMENT_PENDING',
  /** Paystack confirmed, via the plugin. Rider's screen says PAID. */
  PAID: 'PAID',
  /** Goods handed over. Terminal, good. */
  DELIVERED: 'DELIVERED',
  /** Prompt expired, no funds, wallet down, window closed. Not terminal: retry or fail. */
  PAYMENT_FAILED: 'PAYMENT_FAILED',
  /** Nobody home, refused, unreachable, damaged. Goods are going back. */
  FAILED: 'FAILED',
  /** Goods back with the vendor or sender. Terminal. */
  RETURNED: 'RETURNED',
  /** Cancelled by buyer, vendor, ops or timeout before pickup. Terminal. */
  CANCELLED: 'CANCELLED',
  /** Cascade exhausted with nobody available. Ops must act. */
  UNFULFILLED: 'UNFULFILLED',
} as const;
export type JobStatus = (typeof JobStatus)[keyof typeof JobStatus];

export const TERMINAL_STATUSES: readonly JobStatus[] = [
  JobStatus.DELIVERED,
  JobStatus.RETURNED,
  JobStatus.CANCELLED,
];

/** Statuses in which a rider is committed to the job and counts against concurrency. */
export const ACTIVE_RIDER_STATUSES: readonly JobStatus[] = [
  JobStatus.ASSIGNED,
  JobStatus.AT_PICKUP,
  JobStatus.PICKED_UP,
  JobStatus.EN_ROUTE,
  JobStatus.ARRIVED,
  JobStatus.CODE_SENT,
  JobStatus.CODE_VERIFIED,
  JobStatus.PAYMENT_PENDING,
  JobStatus.PAID,
  JobStatus.PAYMENT_FAILED,
  JobStatus.FAILED,
];

/** Statuses from which the buyer's tracking map shows a live position. */
export const TRACKABLE_STATUSES: readonly JobStatus[] = [
  JobStatus.PICKED_UP,
  JobStatus.EN_ROUTE,
  JobStatus.ARRIVED,
  JobStatus.CODE_SENT,
  JobStatus.CODE_VERIFIED,
  JobStatus.PAYMENT_PENDING,
  JobStatus.PAID,
];

/**
 * Allowed transitions. Anything not listed is refused with a 409.
 *
 * Note what is absent on purpose:
 *  - nothing → DELIVERED except CODE_VERIFIED (prepaid) and PAID (pay on delivery);
 *    the service layer additionally refuses CODE_VERIFIED → DELIVERED when the
 *    job's payment method is PAY_ON_DELIVERY.
 *  - no way back from PAID except DELIVERED. Money has moved.
 *  - CANCELLED only before the goods are in the rider's hands. After PICKED_UP a
 *    job that goes wrong is FAILED → RETURNED, which keeps the goods accounted for.
 */
const TRANSITIONS: Record<JobStatus, readonly JobStatus[]> = {
  CREATED: [JobStatus.OFFERED, JobStatus.ASSIGNED, JobStatus.CANCELLED, JobStatus.UNFULFILLED],
  OFFERED: [JobStatus.OFFERED, JobStatus.ASSIGNED, JobStatus.CANCELLED, JobStatus.UNFULFILLED],
  UNFULFILLED: [JobStatus.OFFERED, JobStatus.ASSIGNED, JobStatus.CANCELLED],
  ASSIGNED: [JobStatus.AT_PICKUP, JobStatus.CANCELLED, JobStatus.OFFERED],
  AT_PICKUP: [JobStatus.PICKED_UP, JobStatus.CANCELLED, JobStatus.FAILED],
  PICKED_UP: [JobStatus.EN_ROUTE, JobStatus.ARRIVED, JobStatus.FAILED],
  EN_ROUTE: [JobStatus.ARRIVED, JobStatus.FAILED],
  ARRIVED: [JobStatus.CODE_SENT, JobStatus.FAILED],
  CODE_SENT: [JobStatus.CODE_SENT, JobStatus.CODE_VERIFIED, JobStatus.FAILED],
  CODE_VERIFIED: [JobStatus.PAYMENT_PENDING, JobStatus.DELIVERED, JobStatus.FAILED],
  PAYMENT_PENDING: [JobStatus.PAYMENT_PENDING, JobStatus.PAID, JobStatus.PAYMENT_FAILED],
  PAYMENT_FAILED: [JobStatus.PAYMENT_PENDING, JobStatus.FAILED],
  PAID: [JobStatus.DELIVERED],
  DELIVERED: [],
  FAILED: [JobStatus.RETURNED, JobStatus.EN_ROUTE],
  RETURNED: [],
  CANCELLED: [],
};

export function canTransition(from: JobStatus, to: JobStatus): boolean {
  return TRANSITIONS[from]?.includes(to) ?? false;
}

export const JobSource = {
  MARKETPLACE: 'MARKETPLACE',
  STANDALONE: 'STANDALONE',
} as const;
export type JobSource = (typeof JobSource)[keyof typeof JobSource];

export const PaymentMethod = {
  /** Already paid online. Code matched is delivered. */
  PREPAID: 'PREPAID',
  /** The buyer chose "cash on delivery". Paid on their phone at the door. No cash. */
  PAY_ON_DELIVERY: 'PAY_ON_DELIVERY',
} as const;
export type PaymentMethod = (typeof PaymentMethod)[keyof typeof PaymentMethod];

/** Who is carrying the goods — decides whether POKBON is in the money flow. PRD § 2. */
export const RiderSource = {
  POKBON: 'POKBON',
  VENDOR: 'VENDOR',
} as const;
export type RiderSource = (typeof RiderSource)[keyof typeof RiderSource];

export const VehicleClass = {
  MOTORBIKE: 'MOTORBIKE',
  TRICYCLE: 'TRICYCLE',
  CAR: 'CAR',
  PICKUP: 'PICKUP',
  CANTER: 'CANTER',
} as const;
export type VehicleClass = (typeof VehicleClass)[keyof typeof VehicleClass];

export const ParcelSize = {
  SMALL: 'SMALL',
  MEDIUM: 'MEDIUM',
  LARGE: 'LARGE',
} as const;
export type ParcelSize = (typeof ParcelSize)[keyof typeof ParcelSize];

export const RiderStatus = {
  /** Registered, documents not yet complete. */
  DRAFT: 'DRAFT',
  /** Submitted; in the admin queue. */
  APPLIED: 'APPLIED',
  APPROVED: 'APPROVED',
  SUSPENDED: 'SUSPENDED',
  REJECTED: 'REJECTED',
  /** Opted out. Contractors can leave at any time (PRD § 10). */
  LEFT: 'LEFT',
} as const;
export type RiderStatus = (typeof RiderStatus)[keyof typeof RiderStatus];

export const IdVerificationLevel = {
  /** A photograph was taken. NOT verification. */
  PHOTO: 'PHOTO',
  /** Chip read and signature checked. The card is genuine. */
  NFC: 'NFC',
  /** Checked against the national register. Needs the NIA contract. */
  NIA: 'NIA',
} as const;
export type IdVerificationLevel = (typeof IdVerificationLevel)[keyof typeof IdVerificationLevel];

/** Fixed list. Free text goes in `detail`, never in the reason. PRD § 7. */
export const FailureReason = {
  NOBODY_HOME: 'NOBODY_HOME',
  UNREACHABLE: 'UNREACHABLE',
  WRONG_ADDRESS: 'WRONG_ADDRESS',
  REFUSED: 'REFUSED',
  REFUSED_DAMAGED: 'REFUSED_DAMAGED',
  PAYMENT_FAILED: 'PAYMENT_FAILED',
  VENDOR_NOT_READY: 'VENDOR_NOT_READY',
  RIDER_INCIDENT: 'RIDER_INCIDENT',
  OTHER: 'OTHER',
} as const;
export type FailureReason = (typeof FailureReason)[keyof typeof FailureReason];

export const OfferStatus = {
  OFFERED: 'OFFERED',
  ACCEPTED: 'ACCEPTED',
  DECLINED: 'DECLINED',
  EXPIRED: 'EXPIRED',
  WITHDRAWN: 'WITHDRAWN',
} as const;
export type OfferStatus = (typeof OfferStatus)[keyof typeof OfferStatus];

export const PhotoKind = {
  PICKUP: 'PICKUP',
  DELIVERY: 'DELIVERY',
  FAILURE: 'FAILURE',
} as const;
export type PhotoKind = (typeof PhotoKind)[keyof typeof PhotoKind];
