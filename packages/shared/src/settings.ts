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
