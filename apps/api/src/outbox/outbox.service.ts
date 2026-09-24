import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { PluginClient } from '../plugin/plugin.client';
import { PrismaService } from '../prisma/prisma.service';

const SWEEP_INTERVAL_MS = 3_000;
const BATCH = 20;
const MAX_BACKOFF_MS = 10 * 60_000;

export type OutboundType = 'job.status' | 'sms.send' | 'inbox.send' | 'payout.requested';

/**
 * How long a perishable message stays worth sending.
 *
 * A delivery code is a secret about this minute. Retrying one is not
 * resilience, it is a second envelope arriving after the lock has changed:
 * on 2026-09-21 a code SMS retried for an hour against a broken gateway and
 * finally arrived alongside a newer one, leaving the customer holding two
 * codes and no way to tell which the rider would accept.
 *
 * So perishable rows are abandoned rather than delivered late. The rider's
 * "send the code again" is the retry, and it issues a fresh code, which is
 * the only kind of retry that can be correct here.
 */
const PERISHABLE_AFTER_MS = 3 * 60_000;

/** Purposes whose value expires. Everything else retries as before. */
const PERISHABLE_PURPOSES = new Set(['delivery_code', 'payment_prompt', 'rider_otp']);

/**
 * Characters an SMS can actually carry, and what to use instead.
 *
 * Messages go out as GSM 7-bit, so anything outside that alphabet is
 * substituted by the network — usually with a question mark. A customer at
 * their door was asked to approve "GH?150.00" because the cedi sign cannot
 * survive the trip. Sending Unicode instead would carry the glyph but halve
 * the characters per segment and double the cost of every message, for
 * decoration.
 *
 * So the text is folded here, in the one place every SMS passes through,
 * rather than trusting each author to remember. Anything still outside the
 * alphabet after folding is dropped with a warning: a message with a missing
 * character is better than one with a question mark where money should be.
 */
const GSM_SUBSTITUTIONS: Array<[RegExp, string]> = [
  [/₵/g, 'GHS '],
  [/[—–]/g, '-'],
  [/[“”]/g, '"'],
  [/[‘’]/g, "'"],
  [/…/g, '...'],
  // A non-breaking space, which some editors insert invisibly.
  [/ /g, ' '],
];

// The GSM 03.38 basic set. Held as a string rather than a character class
// because half of these need escaping in a regex and the escaping is where
// mistakes hide.
const GSM_ALLOWED = new Set(
  Array.from(
    'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' +
      ' \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ' +
      '!"#¤%&\'()*+,-./:;<=>?¡ÄÖÑÜ§¿äöñüà' +
      // GSM extension table, each billed as two characters but valid.
      '^{}[~]|\\',
  ),
);

export function toGsmSafe(text: string): { text: string; dropped: string[] } {
  let out = text;
  for (const [pattern, replacement] of GSM_SUBSTITUTIONS) out = out.replace(pattern, replacement);

  const dropped: string[] = [];
  out = Array.from(out)
    .filter((ch) => {
      if (GSM_ALLOWED.has(ch)) return true;
      dropped.push(ch);
      return false;
    })
    .join('');

  // 'GHS ' for '₵' can leave 'GH GHS 150.00' where the text already said GH.
  out = out.replace(/GH\s*GHS\s*/g, 'GHS ').replace(/ {2,}/g, ' ');
  return { text: out, dropped };
}

/**
 * Everything the API tells the plugin, delivered with retries.
 *
 * A status callback that fails because WordPress hiccupped must not be lost:
 * the buyer's timeline, the SMS and the commission engine all hang off it.
 * Rows are written in the same transaction as the job change and delivered by
 * this sweep, so the two cannot disagree. Delivery is at-least-once; the plugin
 * is idempotent on the event id (contract § 1), which is also this row's id.
 */
/**
 * Did the plugin refuse this message, as opposed to failing to receive it?
 *
 * 4xx means the request was understood and rejected: the same bytes will be
 * rejected again, for ever. 408 and 429 are the exceptions — "you were too
 * slow" and "you are going too fast" both get better by waiting — and 5xx is
 * the plugin having a bad day, which is precisely what retrying is for.
 *
 * Read off the message because that is what the client throws; a structured
 * status would be better and is worth doing when PluginClient next changes.
 */
export function isPermanentRefusal(error: unknown): boolean {
  const status = /failed with (\d{3})/.exec(String(error))?.[1];
  if (!status) return false;
  const code = Number(status);
  return code >= 400 && code < 500 && code !== 408 && code !== 429;
}

@Injectable()
export class OutboxService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(OutboxService.name);
  private timer: NodeJS.Timeout | null = null;
  private running = false;

  constructor(
    private readonly prisma: PrismaService,
    private readonly plugin: PluginClient,
  ) {}

  onModuleInit(): void {
    this.timer = setInterval(() => void this.sweep(), SWEEP_INTERVAL_MS);
    this.timer.unref();
  }

  onModuleDestroy(): void {
    if (this.timer) clearInterval(this.timer);
  }

  /** Enqueue inside the caller's transaction when one is passed. */
  async enqueue(
    type: OutboundType,
    payload: Record<string, unknown>,
    tx: Prisma.TransactionClient | PrismaService = this.prisma,
  ): Promise<string> {
    const row = await tx.outboundEvent.create({ data: { type, payload: payload as Prisma.InputJsonValue } });
    return row.id;
  }

  /** Deliver now, for tests and for the sweep. Returns how many were delivered. */
  async sweep(): Promise<number> {
    if (this.running) return 0;
    this.running = true;
    let delivered = 0;
    try {
      const due = await this.prisma.outboundEvent.findMany({
        where: { deliveredAt: null, nextAttemptAt: { lte: new Date() } },
        orderBy: { createdAt: 'asc' },
        take: BATCH,
      });
      for (const row of due) {
        const stale = this.isStale(row.type as OutboundType, row.payload as Record<string, unknown>, row.createdAt);
        if (stale) {
          // Marked delivered so the sweep lets it go, with the reason kept.
          await this.prisma.outboundEvent.update({
            where: { id: row.id },
            data: { deliveredAt: new Date(), lastError: stale },
          });
          this.logger.warn(`Outbound ${row.type} ${row.id} abandoned: ${stale}`);
          continue;
        }
        try {
          await this.deliver(row.id, row.type as OutboundType, row.payload as Record<string, unknown>);
          await this.prisma.outboundEvent.update({
            where: { id: row.id },
            data: { deliveredAt: new Date(), attempts: { increment: 1 }, lastError: null },
          });
          delivered++;
        } catch (error) {
          const attempts = row.attempts + 1;

          /*
           * A refusal is not a failure to be retried.
           *
           * Retrying exists for the things that get better on their own: the
           * plugin was restarting, the tunnel dropped, WordPress was slow.
           * A 400 is the other kind — the plugin read the message, understood
           * it, and said it is wrong. Sending the identical bytes again cannot
           * change that answer, and the backoff caps out, so the row retries
           * for ever at a fixed interval.
           *
           * Five of them were doing exactly that: a guest order's inbox
           * message, refused since the 22nd, on attempt 130, every fifteen
           * seconds, filling the log so thoroughly that a real failure would
           * have been invisible in it.
           *
           * So a rejection is recorded and let go, with the reason kept on the
           * row. Nothing is lost that was ever going to be delivered.
           */
          const refused = isPermanentRefusal(error);
          if (refused) {
            await this.prisma.outboundEvent.update({
              where: { id: row.id },
              data: {
                deliveredAt: new Date(),
                attempts,
                lastError: `Refused, not retried: ${String(error).slice(0, 400)}`,
              },
            });
            this.logger.error(
              `Outbound ${row.type} ${row.id} was REFUSED by the plugin and will not be retried: ${String(error)}`,
            );
            continue;
          }

          const backoff = Math.min(MAX_BACKOFF_MS, 5_000 * attempts * attempts);
          await this.prisma.outboundEvent.update({
            where: { id: row.id },
            data: { attempts, nextAttemptAt: new Date(Date.now() + backoff), lastError: String(error).slice(0, 500) },
          });
          if (attempts === 1 || attempts % 10 === 0) {
            this.logger.error(`Outbound ${row.type} ${row.id} failed (attempt ${attempts}): ${String(error)}`);
          }
        }
      }
    } catch (error) {
      this.logger.error(`Outbox sweep failed: ${String(error)}`);
    } finally {
      this.running = false;
    }
    return delivered;
  }

  /**
   * Why this row is no longer worth sending, or null if it still is.
   *
   * Deliberately based on when the row was written rather than on attempts:
   * the question is whether the content is still true, and an hour-old code
   * is wrong after one failed attempt just as surely as after thirteen.
   */
  private isStale(type: OutboundType, payload: Record<string, unknown>, createdAt: Date): string | null {
    if (type !== 'sms.send') return null;
    const purpose = String(payload.purpose ?? '');
    if (!PERISHABLE_PURPOSES.has(purpose)) return null;

    const age = Date.now() - createdAt.getTime();
    if (age <= PERISHABLE_AFTER_MS) return null;

    return `A ${purpose} message is only good for ${PERISHABLE_AFTER_MS / 60_000} minutes; this one was ${Math.round(age / 60_000)} minutes old. Send a fresh one instead.`;
  }

  private async deliver(id: string, type: OutboundType, payload: Record<string, unknown>): Promise<void> {
    switch (type) {
      case 'job.status':
        return this.plugin.statusCallback(payload, id);
      case 'sms.send': {
        const safe = toGsmSafe(payload.message as string);
        if (safe.dropped.length > 0) {
          this.logger.warn(
            `Outbound sms ${id} had ${safe.dropped.length} character(s) an SMS cannot carry (${[...new Set(safe.dropped)].join('')}); they were removed.`,
          );
        }
        return this.plugin.sendSms(payload.to as string, safe.text, {
          jobId: payload.jobId as string | undefined,
          purpose: payload.purpose as string,
        });
      }
      case 'inbox.send':
        return this.plugin.sendInbox(payload as Parameters<PluginClient['sendInbox']>[0]);
      case 'payout.requested':
        return this.plugin.payoutRequested(payload);
      default:
        throw new Error(`Unknown outbound type ${type as string}`);
    }
  }
}
