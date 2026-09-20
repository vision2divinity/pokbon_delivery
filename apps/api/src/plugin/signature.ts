import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';

/**
 * Service-to-service signing, both directions. Marketplace contract § 1:
 *
 *   X-Pokbon-Delivery-Signature  HMAC-SHA256 of the raw body, hex
 *   X-Pokbon-Delivery-Timestamp  unix seconds; rejected when older than 5 minutes
 *   X-Pokbon-Delivery-Event-Id   UUID per event; replays return the stored answer
 *
 * The body is signed as raw bytes. Never re-serialise before verifying.
 */
export const HEADER_SIGNATURE = 'x-pokbon-delivery-signature';
export const HEADER_TIMESTAMP = 'x-pokbon-delivery-timestamp';
export const HEADER_EVENT_ID = 'x-pokbon-delivery-event-id';

export const MAX_SKEW_SECONDS = 5 * 60;

export function signBody(secret: string, rawBody: Buffer | string): string {
  return createHmac('sha256', secret).update(rawBody).digest('hex');
}

export function signedHeaders(
  secret: string,
  rawBody: Buffer | string,
  eventId: string = randomUUID(),
): Record<string, string> {
  return {
    [HEADER_SIGNATURE]: signBody(secret, rawBody),
    [HEADER_TIMESTAMP]: String(Math.floor(Date.now() / 1000)),
    [HEADER_EVENT_ID]: eventId,
    'content-type': 'application/json',
  };
}

export interface VerifyResult {
  ok: boolean;
  reason?: string;
  eventId?: string;
}

export function verifySignedRequest(
  secret: string,
  rawBody: Buffer | undefined,
  headers: Record<string, string | string[] | undefined>,
  now: number = Date.now(),
): VerifyResult {
  const sig = header(headers, HEADER_SIGNATURE);
  const ts = header(headers, HEADER_TIMESTAMP);
  const eventId = header(headers, HEADER_EVENT_ID);

  if (!sig || !ts || !eventId) return { ok: false, reason: 'missing signature headers' };

  const tsNum = Number(ts);
  if (!Number.isFinite(tsNum)) return { ok: false, reason: 'bad timestamp' };
  if (Math.abs(now / 1000 - tsNum) > MAX_SKEW_SECONDS) return { ok: false, reason: 'stale timestamp' };

  if (!/^[0-9a-f-]{16,64}$/i.test(eventId)) return { ok: false, reason: 'bad event id' };

  const expected = signBody(secret, rawBody ?? Buffer.alloc(0));
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(sig, 'utf8');
  if (a.length !== b.length || !timingSafeEqual(a, b)) return { ok: false, reason: 'bad signature' };

  return { ok: true, eventId };
}

function header(
  headers: Record<string, string | string[] | undefined>,
  name: string,
): string | undefined {
  const v = headers[name];
  return Array.isArray(v) ? v[0] : v;
}
