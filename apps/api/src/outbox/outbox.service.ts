import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { PluginClient } from '../plugin/plugin.client';
import { PrismaService } from '../prisma/prisma.service';

const SWEEP_INTERVAL_MS = 3_000;
const BATCH = 20;
const MAX_BACKOFF_MS = 10 * 60_000;

export type OutboundType = 'job.status' | 'sms.send' | 'inbox.send';

/**
 * Everything the API tells the plugin, delivered with retries.
 *
 * A status callback that fails because WordPress hiccupped must not be lost:
 * the buyer's timeline, the SMS and the commission engine all hang off it.
 * Rows are written in the same transaction as the job change and delivered by
 * this sweep, so the two cannot disagree. Delivery is at-least-once; the plugin
 * is idempotent on the event id (contract § 1), which is also this row's id.
 */
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
        try {
          await this.deliver(row.id, row.type as OutboundType, row.payload as Record<string, unknown>);
          await this.prisma.outboundEvent.update({
            where: { id: row.id },
            data: { deliveredAt: new Date(), attempts: { increment: 1 }, lastError: null },
          });
          delivered++;
        } catch (error) {
          const attempts = row.attempts + 1;
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

  private async deliver(id: string, type: OutboundType, payload: Record<string, unknown>): Promise<void> {
    switch (type) {
      case 'job.status':
        return this.plugin.statusCallback(payload, id);
      case 'sms.send':
        return this.plugin.sendSms(payload.to as string, payload.message as string, {
          jobId: payload.jobId as string | undefined,
          purpose: payload.purpose as string,
        });
      case 'inbox.send':
        return this.plugin.sendInbox(payload as Parameters<PluginClient['sendInbox']>[0]);
      default:
        throw new Error(`Unknown outbound type ${type as string}`);
    }
  }
}
