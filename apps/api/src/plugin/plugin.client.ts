import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { randomUUID } from 'node:crypto';
import { signedHeaders } from './signature';

/**
 * Everything the API asks the WordPress plugin to do. PRD § 1a: the API never
 * touches money and never sends a message itself.
 *
 * Endpoints, under PLUGIN_BASE_URL (…/wp-json/pokbon/v1):
 *
 *   POST /delivery/messages/sms          { to, message, jobId?, purpose }
 *   POST /delivery/messages/inbox        { buyerUserId, title, body, jobId?, orderId? }
 *   POST /delivery/payment/prompt        { eventId, jobId, orderId, reason }    → intent
 *   GET  /delivery/payment/{intentId}                                            → status
 *   POST /delivery/payment/{intentId}/link { phone }                             → pay-by-link SMS sent
 *   POST /delivery/callback              status change, contract § 5
 *   GET  /delivery/settings                                                      → { version, zones, prices, settings }
 *
 * 'console' mode logs each call and keeps the last 200 in memory for
 * GET /dev/outbox, so the lifecycle runs end to end with no WordPress.
 */

export interface PaymentIntent {
  intentId: string;
  status: 'pending' | 'paid' | 'failed' | 'expired';
  /** GHS */
  amount: number;
  currency: 'GHS';
  expiresAt: string;
  reference?: string;
  paidAt?: string;
  /**
   * What Paystack asked the customer to do: `pay_offline` (approve on the
   * handset) or `send_otp` (a code that has to go back to Paystack, which a
   * doorstep cannot do). Recorded because "pending" on its own never said
   * whether the customer had been given something they could act on.
   */
  stage?: string;
  /** Paystack's own wording for the customer, kept verbatim. */
  instruction?: string;
  /** A checkout link, when the handset request cannot finish by itself. */
  payUrl?: string;
}

export interface SettingsPayload {
  version: number;
  zones?: unknown[];
  prices?: unknown[];
  settings?: Record<string, unknown>;
}

export interface OutboxEntry {
  at: string;
  method: string;
  path: string;
  body: unknown;
}

@Injectable()
export class PluginClient {
  private readonly logger = new Logger(PluginClient.name);
  private readonly mode: 'console' | 'live';
  private readonly baseUrl: string;
  private readonly secret: string;
  private readonly outbox: OutboxEntry[] = [];

  constructor(config: ConfigService) {
    this.mode = config.getOrThrow<'console' | 'live'>('PLUGIN_MODE');
    this.baseUrl = config.getOrThrow<string>('PLUGIN_BASE_URL').replace(/\/$/, '');
    this.secret = config.getOrThrow<string>('PLUGIN_SHARED_SECRET');
  }

  get isConsole(): boolean {
    return this.mode === 'console';
  }

  /** Development only. Guarded by the controller that exposes it. */
  recentOutbox(): OutboxEntry[] {
    return [...this.outbox].reverse();
  }

  async sendSms(to: string, message: string, meta: { jobId?: string; purpose: string }): Promise<void> {
    await this.post('/delivery/messages/sms', { to, message, ...meta });
  }

  async sendInbox(input: {
    buyerUserId: string;
    title: string;
    body: string;
    jobId?: string;
    orderId?: string;
  }): Promise<void> {
    await this.post('/delivery/messages/inbox', input);
  }

  /**
   * A rider has asked to be paid. Tell the plugin; it decides who hears about it.
   *
   * Deliberately not an sms.send with the words already chosen. The plugin owns
   * money and messages, so it decides whether this becomes an SMS, an email,
   * both or neither, and who it reaches — the same rule that keeps every other
   * business decision out of this service.
   */
  async payoutRequested(input: Record<string, unknown>): Promise<void> {
    await this.post('/delivery/payout-request', input);
  }

  /**
   * Ask the plugin to push the MoMo prompt. One intent per job: the plugin
   * keys on jobId and returns the same intent on a retry (contract § 4b).
   */
  async paymentPrompt(input: {
    jobId: string;
    orderId: string;
    reason: 'arrival' | 'retry';
    /**
     * Other legs of this same order that THIS rider is also carrying.
     *
     * One rider at one door with three parcels is one payment, not three
     * mobile-money prompts in a row for a customer standing in front of them.
     * The plugin adds those legs to this charge and marks them all settled
     * against the one reference.
     *
     * The API cannot decide the amount — it never handles money — so it says
     * only which legs are in the same rider's hands and leaves the arithmetic
     * where the arithmetic lives.
     */
    alsoJobIds?: string[];
    eventId?: string;
  }): Promise<PaymentIntent> {
    const eventId = input.eventId ?? randomUUID();
    if (this.isConsole) {
      this.record('POST', '/delivery/payment/prompt', { eventId, ...input });
      return {
        intentId: `dev_intent_${input.jobId}`,
        status: 'pending',
        amount: 0,
        currency: 'GHS',
        expiresAt: new Date(Date.now() + 10 * 60_000).toISOString(),
      };
    }
    return this.post<PaymentIntent>('/delivery/payment/prompt', { eventId, ...input }, eventId);
  }

  async paymentStatus(intentId: string): Promise<PaymentIntent> {
    if (this.isConsole) {
      this.record('GET', `/delivery/payment/${intentId}`, null);
      return {
        intentId,
        status: 'pending',
        amount: 0,
        currency: 'GHS',
        expiresAt: new Date(Date.now() + 10 * 60_000).toISOString(),
      };
    }
    return this.get<PaymentIntent>(`/delivery/payment/${encodeURIComponent(intentId)}`);
  }

  async paymentLink(intentId: string, phone: string): Promise<void> {
    await this.post(`/delivery/payment/${encodeURIComponent(intentId)}/link`, { phone });
  }

  /** Contract § 5. Retried by the outbox, so a failure here is not final. */
  async statusCallback(payload: Record<string, unknown>, eventId: string): Promise<void> {
    await this.post('/delivery/callback', { eventId, ...payload }, eventId);
  }

  async pullSettings(): Promise<SettingsPayload | null> {
    if (this.isConsole) {
      this.record('GET', '/delivery/settings', null);
      return null;
    }
    return this.get<SettingsPayload>('/delivery/settings');
  }

  // ---------------------------------------------------------------------------

  private async post<T = unknown>(path: string, body: unknown, eventId?: string): Promise<T> {
    if (this.isConsole) {
      this.record('POST', path, body);
      return { ok: true } as T;
    }
    const raw = JSON.stringify(body);
    const res = await fetch(this.baseUrl + path, {
      method: 'POST',
      headers: signedHeaders(this.secret, raw, eventId),
      body: raw,
      signal: AbortSignal.timeout(15_000),
    });
    return this.parse<T>(res, 'POST', path);
  }

  private async get<T>(path: string): Promise<T> {
    const res = await fetch(this.baseUrl + path, {
      method: 'GET',
      headers: signedHeaders(this.secret, ''),
      signal: AbortSignal.timeout(15_000),
    });
    return this.parse<T>(res, 'GET', path);
  }

  private async parse<T>(res: Response, method: string, path: string): Promise<T> {
    const text = await res.text();
    if (!res.ok) {
      this.logger.error(`Plugin ${method} ${path} → HTTP ${res.status}: ${text.slice(0, 300)}`);
      /*
       * Carry the plugin's own words, not just the number.
       *
       * This used to throw "failed with 409" and nothing else, so a payment
       * prompt refused because a phone prefix was missing from the network
       * table was recorded on the job as a bare status code. The plugin had
       * said exactly what was wrong — "that number does not map to a known
       * mobile-money network" — and the API dropped it on the floor. The
       * status codes here are deliberately all 409, which makes the number
       * carry no information at all on its own.
       */
      let reason = text.slice(0, 200);
      try {
        const body = JSON.parse(text) as { message?: unknown; code?: unknown };
        if (typeof body.message === 'string' && body.message !== '') {
          reason = typeof body.code === 'string' ? `${body.message} [${body.code}]` : body.message;
        }
      } catch {
        // Not JSON — the snippet above is the best there is.
      }
      throw new PluginCallError(res.status, `${method} ${path} failed with ${res.status}: ${reason}`);
    }
    try {
      const json = JSON.parse(text) as { success?: boolean; data?: T } | T;
      // The marketplace plugin wraps responses as { success, data }. Unwrap when present.
      if (json && typeof json === 'object' && 'data' in (json as object) && 'success' in (json as object)) {
        return (json as { data: T }).data;
      }
      return json as T;
    } catch {
      throw new PluginCallError(res.status, `${method} ${path} returned non-JSON`);
    }
  }

  private record(method: string, path: string, body: unknown): void {
    this.logger.warn(`[PLUGIN NOT CALLED — console mode] ${method} ${path} ${body ? JSON.stringify(body) : ''}`);
    this.outbox.push({ at: new Date().toISOString(), method, path, body });
    if (this.outbox.length > 200) this.outbox.shift();
  }
}

export class PluginCallError extends Error {
  constructor(
    readonly status: number,
    message: string,
  ) {
    super(message);
  }
}
