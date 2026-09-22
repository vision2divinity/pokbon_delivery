/**
 * Every tunable in the product, with its launch default. PRD draft 3 § 12b.
 *
 * The WordPress plugin is the source of truth and pushes these to the API; the
 * API keeps a cache and falls back to the values here only when a key has never
 * been synced. Nothing in this list may be hardcoded anywhere else. Francis
 * changes money, timing and coverage from the plugin, without a release.
 */
export const SettingKey = {
  /** Seconds a rider has to answer an offer before it cascades. */
  OFFER_TIMEOUT_SECONDS: 'offer_timeout_seconds',
  /** How many riders a cascade tries before giving up to ops. */
  OFFER_CASCADE_DEPTH: 'offer_cascade_depth',
  /** Metres around the pickup within which riders are considered nearby. */
  OFFER_RADIUS_METRES: 'offer_radius_metres',
  /** A rider position older than this is not used for matching. */
  LOCATION_STALE_SECONDS: 'location_stale_seconds',

  CODE_LENGTH: 'code_length',
  CODE_EXPIRY_MINUTES: 'code_expiry_minutes',
  CODE_MAX_ATTEMPTS: 'code_max_attempts',
  /** How many times the rider may re-send the code to the buyer per job. */
  CODE_MAX_SENDS: 'code_max_sends',

  /** Minutes the rider waits for a doorstep payment before it may be abandoned. */
  PAYMENT_WAIT_MINUTES: 'payment_wait_minutes',
  /** How many times the rider may re-send the MoMo prompt per job. Same intent every time. */
  PAYMENT_MAX_PROMPTS: 'payment_max_prompts',

  /** Rider commission schedule: [{ effectiveFrom: 'YYYY-MM-DD', rateBps?: number, flatMinor?: number }]. Empty = zero. */
  RIDER_COMMISSION_SCHEDULE: 'rider_commission_schedule',
  /** Every message sent to a person, as editable text. Owned by the plugin. */
  MESSAGES: 'messages',
  /** Failed-trip uplift credited to the rider's next delivery. { rateBps?: number, flatMinor?: number } */
  FAILED_TRIP_UPLIFT: 'failed_trip_uplift',
  /** Default markup used to fill a buyer price from a rider fee. { rateBps?: number, flatMinor?: number } */
  DEFAULT_MARKUP: 'default_markup',

  /** Concurrency cap by tenure: [{ minCompletedJobs: 0, maxConcurrent: 1 }, ...]. */
  CONCURRENCY_CAPS: 'concurrency_caps',

  /** Operating hours: { open: 'HH:MM', close: 'HH:MM', days: [0..6] } — local time, Africa/Accra. */
  OPERATING_HOURS: 'operating_hours',

  /** Payout cycle: 'weekly' | 'daily' | 'fortnightly'. */
  PAYOUT_CYCLE: 'payout_cycle',

  /** Standalone refund on failure: { refundBps: number }. 10000 = full refund. */
  STANDALONE_REFUND_ON_FAILURE: 'standalone_refund_on_failure',

  /** Current contractor agreement version riders must have accepted. */
  AGREEMENT_VERSION: 'agreement_version',

  /** Vehicle classes currently offered work. Launch: motorbike only. */
  ACTIVE_VEHICLE_CLASSES: 'active_vehicle_classes',
} as const;
export type SettingKey = (typeof SettingKey)[keyof typeof SettingKey];

export interface CommissionScheduleRow {
  effectiveFrom: string;
  rateBps?: number;
  flatMinor?: number;
}
export interface Markup {
  rateBps?: number;
  flatMinor?: number;
}
export interface ConcurrencyCap {
  minCompletedJobs: number;
  maxConcurrent: number;
}
export interface OperatingHours {
  open: string;
  close: string;
  days: number[];
}

export interface SettingsShape {
  [SettingKey.OFFER_TIMEOUT_SECONDS]: number;
  [SettingKey.OFFER_CASCADE_DEPTH]: number;
  [SettingKey.OFFER_RADIUS_METRES]: number;
  [SettingKey.LOCATION_STALE_SECONDS]: number;
  [SettingKey.CODE_LENGTH]: number;
  [SettingKey.CODE_EXPIRY_MINUTES]: number;
  [SettingKey.CODE_MAX_ATTEMPTS]: number;
  [SettingKey.CODE_MAX_SENDS]: number;
  [SettingKey.PAYMENT_WAIT_MINUTES]: number;
  [SettingKey.PAYMENT_MAX_PROMPTS]: number;
  [SettingKey.RIDER_COMMISSION_SCHEDULE]: CommissionScheduleRow[];
  [SettingKey.FAILED_TRIP_UPLIFT]: Markup;
  [SettingKey.DEFAULT_MARKUP]: Markup;
  [SettingKey.CONCURRENCY_CAPS]: ConcurrencyCap[];
  [SettingKey.OPERATING_HOURS]: OperatingHours;
  [SettingKey.PAYOUT_CYCLE]: 'daily' | 'weekly' | 'fortnightly';
  [SettingKey.STANDALONE_REFUND_ON_FAILURE]: { refundBps: number };
  [SettingKey.AGREEMENT_VERSION]: string;
  [SettingKey.ACTIVE_VEHICLE_CLASSES]: string[];
  [SettingKey.MESSAGES]: Record<string, { enabled?: boolean; text?: string }>;
}

/**
 * Fill a message template.
 *
 * Mirrors Pokbon_Delivery_Settings::message(). A placeholder the caller did
 * not supply is removed rather than left in: "{rider} is on the way" reaching
 * a customer is worse than a sentence reading slightly short.
 */
export function renderMessage(
  messages: Record<string, { enabled?: boolean; text?: string }>,
  key: string,
  fallback: string,
  vars: Record<string, string | number> = {},
): string {
  const entry = messages?.[key];
  if (entry && entry.enabled === false) return '';

  let text = (entry?.text ?? '').trim() === '' ? fallback : (entry?.text as string);
  for (const [name, value] of Object.entries(vars)) {
    text = text.split(`{${name}}`).join(String(value));
  }
  return text
    .replace(/\{[a-z_]+\}/g, '')
    .replace(/ {2,}/g, ' ')
    .trim();
}

/** Launch values from PRD § 12b. Overridden by whatever the plugin has synced. */
export const SETTING_DEFAULTS: SettingsShape = {
  offer_timeout_seconds: 45,
  offer_cascade_depth: 5,
  offer_radius_metres: 8000,
  location_stale_seconds: 300,
  code_length: 6,
  code_expiry_minutes: 120,
  code_max_attempts: 5,
  code_max_sends: 3,
  payment_wait_minutes: 10,
  payment_max_prompts: 3,
  rider_commission_schedule: [],
  failed_trip_uplift: { rateBps: 2000 },
  default_markup: { rateBps: 3333 },
  concurrency_caps: [
    { minCompletedJobs: 0, maxConcurrent: 1 },
    { minCompletedJobs: 30, maxConcurrent: 2 },
    { minCompletedJobs: 100, maxConcurrent: 3 },
  ],
  operating_hours: { open: '06:00', close: '22:00', days: [0, 1, 2, 3, 4, 5, 6] },
  payout_cycle: 'weekly',
  standalone_refund_on_failure: { refundBps: 10000 },
  agreement_version: '2026-09-20',
  // Empty: the plugin owns the wording and syncs it. The API falls back to
  // the literal it would otherwise have hardcoded, so a service started
  // before its first sync still sends something sensible.
  messages: {},
  active_vehicle_classes: ['MOTORBIKE'],
};

/**
 * The rider commission in force on a date. Latest row whose effectiveFrom is
 * not after the date wins. Empty schedule, or no row yet in force, means zero —
 * which is the launch state for about six months (PRD § 9d).
 */
export function commissionInForce(
  schedule: CommissionScheduleRow[],
  at: Date,
): { rateBps: number; flatMinor: number } {
  const day = at.toISOString().slice(0, 10);
  const inForce = [...schedule]
    .filter((r) => r.effectiveFrom <= day)
    .sort((a, b) => (a.effectiveFrom < b.effectiveFrom ? 1 : -1))[0];
  return { rateBps: inForce?.rateBps ?? 0, flatMinor: inForce?.flatMinor ?? 0 };
}

export function applyMarkup(baseMinor: number, markup: Markup): number {
  const pct = Math.round((baseMinor * (markup.rateBps ?? 0)) / 10_000);
  return baseMinor + pct + (markup.flatMinor ?? 0);
}

export function concurrencyCapFor(caps: ConcurrencyCap[], completedJobs: number): number {
  const applicable = [...caps]
    .filter((c) => c.minCompletedJobs <= completedJobs)
    .sort((a, b) => b.minCompletedJobs - a.minCompletedJobs)[0];
  return applicable?.maxConcurrent ?? 1;
}

// ─── the price ladder ────────────────────────────────────────────────────────

/**
 * How a route is priced. PRD § 9, revised 2026-09-21.
 *
 * WHY A LADDER RATHER THAN A MATRIX. Six zones is 36 cells; twelve is 144;
 * twenty is 400. Every new area would mean pricing it against every existing
 * one, which nobody maintains — and a stale price is worse than no price. So
 * the matrix becomes the top rung, used where a route genuinely is special,
 * and two rungs underneath make sure nothing is ever unpriced.
 *
 * First match wins:
 *   1. an explicit zone pair      — Adenta → Kasoa, because you said so
 *   2. a band pair                — Inner Accra → Outer Accra
 *   3. a distance band            — anything 0–5km, 5–10km, and so on
 *
 * Adding a zone then costs one decision (which band) instead of N prices, and
 * it is priced the day it is created.
 */
export type PriceRung = 'zone-pair' | 'band-pair' | 'distance' | 'unpriced';

export interface PricedRoute {
  riderFeeMinor: number;
  buyerPriceMinor: number;
  /** Which rung answered. Shown in admin so a surprising price is explicable. */
  rung: PriceRung;
  /** What matched, for the same reason: "MADINA→CIRCLE", "INNER→OUTER", "0–5km". */
  matched: string;
  distanceMetres?: number;
}

export interface Band {
  code: string;
  name: string;
  active: boolean;
}

export interface BandPrice {
  fromBand: string;
  toBand: string;
  riderFeeMinor: number;
  buyerPriceMinor: number;
}

/** Ordered ascending by maxKm. The last one should be large enough to catch everything. */
export interface DistanceBand {
  maxKm: number;
  riderFeeMinor: number;
  buyerPriceMinor: number;
}

export interface PricingInputs {
  fromZoneCode: string | null;
  toZoneCode: string | null;
  fromBand: string | null;
  toBand: string | null;
  distanceMetres: number | null;
}

export interface PricingTables {
  zonePairs: Map<string, { riderFeeMinor: number; buyerPriceMinor: number }>;
  bandPairs: BandPrice[];
  distanceBands: DistanceBand[];
}

/**
 * Walk the ladder. Pure, so both services can agree without a round trip and
 * so it can be tested without a database.
 */
export function priceRoute(input: PricingInputs, tables: PricingTables): PricedRoute | null {
  // 1. An explicit pair. Directional on purpose: Adenta → Kasoa and Kasoa →
  //    Adenta are different journeys at different times of day.
  if (input.fromZoneCode && input.toZoneCode) {
    const exact = tables.zonePairs.get(`${input.fromZoneCode}|${input.toZoneCode}`);
    if (exact) {
      return {
        ...exact,
        rung: 'zone-pair',
        matched: `${input.fromZoneCode} → ${input.toZoneCode}`,
        distanceMetres: input.distanceMetres ?? undefined,
      };
    }
  }

  // 2. The band the zones belong to.
  if (input.fromBand && input.toBand) {
    const band = tables.bandPairs.find((b) => b.fromBand === input.fromBand && b.toBand === input.toBand);
    if (band) {
      return {
        riderFeeMinor: band.riderFeeMinor,
        buyerPriceMinor: band.buyerPriceMinor,
        rung: 'band-pair',
        matched: `${input.fromBand} → ${input.toBand}`,
        distanceMetres: input.distanceMetres ?? undefined,
      };
    }
  }

  // 3. How far it is. The catch-all, so a zone added this morning still prices.
  if (input.distanceMetres !== null) {
    const km = input.distanceMetres / 1000;
    const ordered = [...tables.distanceBands].sort((a, b) => a.maxKm - b.maxKm);
    const band = ordered.find((b) => km <= b.maxKm);
    if (band) {
      return {
        riderFeeMinor: band.riderFeeMinor,
        buyerPriceMinor: band.buyerPriceMinor,
        rung: 'distance',
        matched: `up to ${band.maxKm}km`,
        distanceMetres: input.distanceMetres,
      };
    }
  }

  // Nothing matched. The caller says "we do not serve this route yet" rather
  // than inventing a number.
  return null;
}
