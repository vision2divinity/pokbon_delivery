/**
 * Ghana phone numbers, normalised to E.164.
 *
 * Accepts the forms people actually type: 0241234567, 241234567, +233241234567,
 * 233241234567, with spaces or dashes. Anything else is rejected rather than
 * guessed at — an OTP sent to a mis-normalised number is a code that never
 * arrives and a support call.
 */
export function normaliseGhanaPhone(raw: string): string | null {
  const digits = raw.replace(/[^\d+]/g, '');
  let national: string;

  if (digits.startsWith('+233')) national = digits.slice(4);
  else if (digits.startsWith('233')) national = digits.slice(3);
  else if (digits.startsWith('0')) national = digits.slice(1);
  else national = digits;

  // Ghana mobile numbers are nine digits after the country code and start
  // with 2 or 5 (MTN 24/54/55/59, Telecel 20/50, AirtelTigo 26/27/56/57).
  if (!/^[25]\d{8}$/.test(national)) return null;
  return `+233${national}`;
}

/** +233241234567 → 024 *** 4567. For rider-facing views of a buyer's number. */
export function maskGhanaPhone(e164: string): string {
  const m = /^\+233(\d)(\d{2})(\d{2})(\d{4})$/.exec(e164);
  if (!m) return '***';
  return `0${m[1]}${m[2]} *** ${m[4]}`;
}

/**
 * Mobile-money network from the prefix, for the Paystack charge payload.
 * Returns null where the prefix is not confidently one network; the plugin
 * then asks the buyer rather than guessing.
 */
export function momoProviderFor(e164: string): 'mtn' | 'vod' | 'atl' | null {
  const p = e164.replace('+233', '').slice(0, 2);
  if (['24', '54', '55', '59', '25'].includes(p)) return 'mtn';
  if (['20', '50'].includes(p)) return 'vod';
  if (['26', '27', '56', '57'].includes(p)) return 'atl';
  return null;
}
