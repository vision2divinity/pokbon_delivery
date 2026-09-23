import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { JobsService } from '../jobs/jobs.service';
import { DispatchService } from './dispatch.service';

/** Offer expiry. A timeout means nothing unless something enforces it. */
const OFFER_SWEEP_MS = 5_000;
/** Doorstep payment windows are minutes long; a minute is often enough. */
const PAYMENT_SWEEP_MS = 60_000;
/**
 * Jobs nobody could take when they arrived. A minute is soon enough to catch a
 * rider coming on duty or riding into range, and slow enough to cost nothing.
 */
const RETRY_SWEEP_MS = 60_000;

@Injectable()
export class DispatchScheduler implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(DispatchScheduler.name);
  private offerTimer: NodeJS.Timeout | null = null;
  private paymentTimer: NodeJS.Timeout | null = null;
  private retryTimer: NodeJS.Timeout | null = null;
  private offerRunning = false;
  private paymentRunning = false;
  private retryRunning = false;

  constructor(
    private readonly dispatch: DispatchService,
    private readonly jobs: JobsService,
  ) {}

  onModuleInit(): void {
    this.offerTimer = setInterval(() => void this.offerSweep(), OFFER_SWEEP_MS);
    this.paymentTimer = setInterval(() => void this.paymentSweep(), PAYMENT_SWEEP_MS);
    this.retryTimer = setInterval(() => void this.retrySweep(), RETRY_SWEEP_MS);
    this.offerTimer.unref();
    this.paymentTimer.unref();
    this.retryTimer.unref();
    this.logger.log(
      `Offer-expiry sweep every ${OFFER_SWEEP_MS / 1000}s; payment-window sweep every ${PAYMENT_SWEEP_MS / 1000}s; stranded-job retry every ${RETRY_SWEEP_MS / 1000}s`,
    );
  }

  onModuleDestroy(): void {
    if (this.offerTimer) clearInterval(this.offerTimer);
    if (this.paymentTimer) clearInterval(this.paymentTimer);
    if (this.retryTimer) clearInterval(this.retryTimer);
  }

  private async offerSweep(): Promise<void> {
    if (this.offerRunning) return;
    this.offerRunning = true;
    try {
      const n = await this.dispatch.expireOverdueOffers();
      if (n > 0) this.logger.log(`Expired ${n} offer(s) and cascaded`);
    } catch (error) {
      // Never let a failed sweep kill the timer.
      this.logger.error(`Offer sweep failed: ${String(error)}`);
    } finally {
      this.offerRunning = false;
    }
  }

  /**
   * Ask again for jobs nobody could take when they were created.
   *
   * The offer sweep above only cascades from an offer that already exists, so
   * a job that never reached anybody was never revisited by anything.
   */
  private async retrySweep(): Promise<void> {
    if (this.retryRunning) return;
    this.retryRunning = true;
    try {
      const n = await this.dispatch.retryStranded();
      if (n > 0) this.logger.log(`Offered ${n} stranded job(s) that nobody could take earlier`);
    } catch (error) {
      this.logger.error(`Retry sweep failed: ${String(error)}`);
    } finally {
      this.retryRunning = false;
    }
  }

  private async paymentSweep(): Promise<void> {
    if (this.paymentRunning) return;
    this.paymentRunning = true;
    try {
      const n = await this.jobs.expireStalePayments();
      if (n > 0) this.logger.log(`Closed ${n} doorstep payment window(s)`);
    } catch (error) {
      this.logger.error(`Payment sweep failed: ${String(error)}`);
    } finally {
      this.paymentRunning = false;
    }
  }
}
