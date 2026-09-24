import { z } from 'zod';
import {
  FailureReason,
  JobSource,
  ParcelSize,
  PaymentMethod,
  PhotoKind,
  RiderSource,
  VehicleClass,
} from './lifecycle';
import { normaliseGhanaPhone } from './phone';

/** A phone the way people type it, normalised to E.164 or rejected. */
export const ghanaPhone = z
  .string()
  .min(9)
  .transform((raw, ctx) => {
    const e164 = normaliseGhanaPhone(raw);
    if (!e164) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Enter a valid Ghana mobile number' });
      return z.NEVER;
    }
    return e164;
  });

const latLng = {
  lat: z.number().min(-90).max(90),
  lng: z.number().min(-180).max(180),
};

// ---------------------------------------------------------------------------
// Auth — riders sign in by phone OTP. PRD § 4a.
// ---------------------------------------------------------------------------

export const requestOtpSchema = z.object({ phone: ghanaPhone });
export const verifyOtpSchema = z.object({
  phone: ghanaPhone,
  code: z.string().regex(/^\d{6}$/, 'The code is six digits'),
});
export const refreshSchema = z.object({ refreshToken: z.string().min(20) });

// ---------------------------------------------------------------------------
// Rider profile and application
// ---------------------------------------------------------------------------

export const updateRiderProfileSchema = z.object({
  fullName: z.string().min(2).max(120).optional(),
  vehicleClass: z.nativeEnum(VehicleClass).optional(),
  vehicleRegistration: z.string().min(3).max(20).optional(),
  baseZoneCode: z.string().min(1).max(40).optional(),
  idType: z.enum(['GHANA_CARD', 'VOTER_ID']).optional(),
  idNumber: z.string().min(4).max(40).optional(),
  licenceNumber: z.string().min(3).max(40).optional(),
  momoNumber: ghanaPhone.optional(),
  nextOfKinName: z.string().max(120).optional(),
  nextOfKinPhone: ghanaPhone.optional(),
  photoUrl: z.string().url().optional(),
  idPhotoUrl: z.string().url().optional(),
  licencePhotoUrl: z.string().url().optional(),
});

export const acceptAgreementSchema = z.object({ version: z.string().min(1) });

export const dutySchema = z.object({
  onDuty: z.boolean(),
  lat: latLng.lat.optional(),
  lng: latLng.lng.optional(),
});

export const locationPingSchema = z.object({
  ...latLng,
  /** Metres. Skipped for matching when worse than the setting. */
  accuracy: z.number().nonnegative().optional(),
  /** Client clock, ISO. Server time is authoritative; this is for diagnosing drift. */
  at: z.string().datetime().optional(),
});

// ---------------------------------------------------------------------------
// Rider job actions. Photos arrive as base64 JSON — riders are on mobile data
// and multipart adds a second code path for no gain at this size.
// ---------------------------------------------------------------------------

const photo = z.object({
  kind: z.nativeEnum(PhotoKind),
  contentType: z.enum(['image/jpeg', 'image/png', 'image/webp']),
  base64: z.string().min(100),
});

export const declineOfferSchema = z.object({ reason: z.string().max(200).optional() });
export const pickedUpSchema = z.object({ photo: photo.optional() });
export const arrivedSchema = z.object({ ...latLng }).partial();
export const verifyCodeSchema = z.object({ code: z.string().regex(/^\d{4,8}$/) });
export const deliveredSchema = z.object({ photo: photo.optional() });
export const failedSchema = z.object({
  reason: z.nativeEnum(FailureReason),
  detail: z.string().max(500).optional(),
  photo: photo.optional(),
});
export const returnedSchema = z.object({ photo: photo.optional(), detail: z.string().max(500).optional() });

// ---------------------------------------------------------------------------
// Plugin → API. The marketplace-side contract, DELIVERY_INTEGRATION_2026-09-20.md § 4.
// ---------------------------------------------------------------------------

const contact = z.object({
  contactName: z.string().max(120).optional().default(''),
  contactPhone: ghanaPhone,
});

export const createJobSchema = z.object({
  source: z.enum(['marketplace', 'standalone']).transform((s) =>
    s === 'marketplace' ? JobSource.MARKETPLACE : JobSource.STANDALONE,
  ),
  /** Marketplace order id, or the plugin's standalone request id. */
  orderId: z.union([z.number().int(), z.string().min(1)]).transform(String),
  vendorId: z.union([z.number().int(), z.string()]).transform(String).optional(),
  riderSource: z.enum(['pokbon', 'vendor']).default('pokbon').transform((s) =>
    s === 'vendor' ? RiderSource.VENDOR : RiderSource.POKBON,
  ),
  pickup: z.object({
    ...latLng,
    address: z.string().max(300),
    zoneCode: z.string().max(40).optional(),
    /** How to find the collection point. Shown to the rider, never routed on. */
    note: z.string().max(500).optional(),
    /*
     * Did somebody point at this spot on a map, or is it a zone centre?
     *
     * A vendor can now be given a zone without a pin — which is enough to price
     * the route and offer the job, and is not enough to ride to. The same
     * distinction the drop-off already makes, and for the same reason: sending
     * a rider confidently into the middle of a suburb is worse than telling
     * them to search for the shop by name.
     *
     * Defaults true, so a pickup that really was pinned behaves as it always has.
     */
    pinned: z.boolean().default(true),
    ...contact.shape,
  }),
  dropoff: z.object({
    ...latLng,
    address: z.string().max(300),
    zoneCode: z.string().max(40).optional(),
    ghanaPost: z.string().max(40).optional(),
    /*
     * The nearest thing a stranger can find.
     *
     * Asked for on its own rather than left inside the address, because in
     * Ghana it is usually the most searchable part of a delivery: house
     * numbers are sparse, street names are inconsistent, and "opposite Melcom,
     * Sowutuom" gets a rider there when "Planet Close 44" does not. It is used
     * for exactly that — it goes first in the maps search when an order has no
     * pin — and it is shown to the rider as its own line.
     */
    landmark: z.string().max(160).optional(),
    note: z.string().max(500).optional(),
    /*
     * Are these coordinates the buyer's actual doorstep, or a stand-in?
     *
     * An order with no map pin — which is every website order today — is sent
     * with the CENTRE OF THE CHOSEN ZONE as its coordinates, because the job
     * has to be priced and routed against something. That is defensible for
     * pricing and dangerous for navigation: tapping Navigate drove riders to
     * the middle of Madina rather than to the customer.
     *
     * Defaults true so an older plugin, which sends real pins and nothing
     * else, keeps behaving exactly as it does now.
     */
    pinned: z.boolean().default(true),
    ...contact.shape,
  }),
  parcel: z.object({
    sizeClass: z.enum(['small', 'medium', 'large']).transform((s) => s.toUpperCase() as ParcelSize),
    /** What it is, in the sender's words. A rider decides how to carry it. */
    description: z.string().max(500).optional(),
    declaredValue: z.number().nonnegative().default(0),
    itemCount: z.number().int().positive().default(1),
  }),
  payment: z.object({
    method: z.enum(['prepaid', 'cod']).transform((m) =>
      m === 'cod' ? PaymentMethod.PAY_ON_DELIVERY : PaymentMethod.PREPAID,
    ),
    /** What the buyer approves at the door, GHS. Items plus delivery. Never shown to the rider. */
    codAmount: z.number().nonnegative().default(0),
    currency: z.literal('GHS').default('GHS'),
  }),
  buyerUserId: z.union([z.number().int(), z.string()]).transform(String).optional(),
  /**
   * Plugin-quoted prices, GHS. When absent the API quotes from its own matrix
   * cache; when present the plugin's numbers win, because the plugin is the
   * source of truth and already showed the buyer this price at checkout.
   */
  pricing: z
    .object({
      riderFee: z.number().nonnegative(),
      buyerPrice: z.number().nonnegative(),
      priceVersion: z.number().int().optional(),
    })
    .optional(),
});
export type CreateJobInput = z.infer<typeof createJobSchema>;

export const cancelJobSchema = z.object({ reason: z.string().min(1).max(300) });

export const paymentOutcomeSchema = z.object({
  intentId: z.string().min(1),
  status: z.enum(['pending', 'paid', 'failed', 'expired']),
  reference: z.string().max(120).optional(),
  paidAt: z.string().datetime().optional(),
  failureReason: z.string().max(300).optional(),
});

export const assignJobSchema = z.object({
  riderId: z.string().uuid(),
  actor: z.string().min(1).max(120),
});

export const bypassCodeSchema = z.object({
  reason: z.string().min(5).max(500),
  actor: z.string().min(1).max(120),
});

export const riderDecisionSchema = z.object({
  decision: z.enum(['approve', 'reject', 'suspend', 'reinstate']),
  note: z.string().max(500).optional(),
  actor: z.string().min(1).max(120),
  idVerificationLevel: z.enum(['PHOTO', 'NFC', 'NIA']).optional(),
});

export const zoneSchema = z.object({
  code: z.string().min(1).max(40),
  name: z.string().min(1).max(120),
  region: z.string().max(80).optional(),
  ...latLng,
  radiusMetres: z.number().int().positive(),
  /** Which band this zone prices under when no explicit pair exists. */
  band: z.string().max(40).optional(),
  active: z.boolean().default(true),
});

export const zonePriceSchema = z.object({
  fromZoneCode: z.string().min(1).max(40),
  toZoneCode: z.string().min(1).max(40),
  /** GHS, as entered by Francis. Converted to pesewas on store. */
  riderFee: z.number().nonnegative(),
  buyerPrice: z.number().nonnegative(),
  active: z.boolean().default(true),
});

export const bandSchema = z.object({
  code: z.string().min(1).max(40),
  name: z.string().min(1).max(120),
  active: z.boolean().default(true),
});

export const bandPriceSchema = z.object({
  fromBand: z.string().min(1).max(40),
  toBand: z.string().min(1).max(40),
  riderFee: z.number().nonnegative(),
  buyerPrice: z.number().nonnegative(),
  active: z.boolean().default(true),
});

export const distanceBandSchema = z.object({
  /** Upper bound in kilometres, inclusive. */
  maxKm: z.number().positive(),
  riderFee: z.number().nonnegative(),
  buyerPrice: z.number().nonnegative(),
  active: z.boolean().default(true),
});

export const settingsSyncSchema = z.object({
  /** Monotonic, from the plugin. Stale syncs (lower version) are ignored. */
  version: z.number().int().nonnegative(),
  zones: z.array(zoneSchema).optional(),
  prices: z.array(zonePriceSchema).optional(),
  bands: z.array(bandSchema).optional(),
  bandPrices: z.array(bandPriceSchema).optional(),
  distanceBands: z.array(distanceBandSchema).optional(),
  settings: z.record(z.string(), z.unknown()).optional(),
});

export const quoteSchema = z.object({
  pickup: z.object(latLng).partial().extend({ zoneCode: z.string().optional() }),
  dropoff: z.object(latLng).partial().extend({ zoneCode: z.string().optional() }),
});

/**
 * A rider created by POKBON rather than by the rider.
 *
 * The normal path is self-signup: a rider installs the app, signs in by SMS,
 * fills the form, and an admin approves them. That is wrong for the people
 * POKBON onboards in person — the first riders are recruited at a desk, with
 * their licence on the table, and asking them to go home and find an app
 * loses them.
 *
 * So an admin can create the record directly. The rider still signs in with
 * their own number and their own code; they simply find an account already
 * there and already approved, instead of an empty form.
 */
export const riderUpsertSchema = z.object({
  phone: ghanaPhone,
  fullName: z.string().min(2).max(120),
  vehicleClass: z.nativeEnum(VehicleClass).default(VehicleClass.MOTORBIKE),
  vehicleRegistration: z.string().max(20).optional(),
  baseZoneCode: z.string().max(40).optional(),
  momoNumber: ghanaPhone.optional(),
  licenceNumber: z.string().max(40).optional(),
  idType: z.enum(['GHANA_CARD', 'VOTER_ID']).optional(),
  idNumber: z.string().max(40).optional(),
  nextOfKinName: z.string().max(120).optional(),
  nextOfKinPhone: ghanaPhone.optional(),

  /**
   * APPROVED puts them straight to work; DRAFT leaves them to finish it
   * themselves; UNCHANGED means this call is not about their status at all.
   *
   * UNCHANGED exists because correcting a rider's phone number or base zone is
   * not a decision about whether they may work. Without it, editing an APPLIED
   * rider through this route silently approved them, and editing an APPROVED
   * one without ticking a box silently demoted them to DRAFT — an approval
   * granted or withdrawn as a side effect of fixing a typo.
   */
  status: z.enum(['DRAFT', 'APPROVED', 'UNCHANGED']).default('APPROVED'),

  /**
   * Whether the contractor agreement was signed on paper.
   *
   * Recorded with who said so, because "the rider agreed" is a claim somebody
   * made on a date, not a fact about the database. Without it the rider
   * cannot go on duty until they accept it in the app, which is the correct
   * fallback rather than a silent bypass.
   */
  agreementSignedOnPaper: z.boolean().default(false),

  /** Who created this rider. Written into the record, never inferred. */
  actor: z.string().min(1).max(120),
  note: z.string().max(500).optional(),
});
export type RiderUpsertInput = z.infer<typeof riderUpsertSchema>;
