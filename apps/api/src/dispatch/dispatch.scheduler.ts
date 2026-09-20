import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { JobsService } from '../jobs/jobs.service';
import { DispatchService } from './dispatch.service';

/** Offer expiry. A timeout means nothing unless something enforces it. */
const OFFER_SWEEP_MS = 5_000;
/** Doorstep payment windows are minutes long; a minute is often enough. */
const PAYMENT_SWEEP_MS = 60_000;

@Injectable()
export class DispatchScheduler implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(DispatchScheduler.name);
  private offerTimer: NodeJS.Timeout | null = null;
  private paymentTimer: NodeJS.Timeout | null = null;
  private offerRunning = false;
  private paymentRunning = false;

  constructor(
    private readonly dispatch: DispatchService,
    private readonly jobs: JobsService,
  ) {}

  onModuleInit(): void {
    this.offerTimer = setInterval(() => void this.offerSweep(), OFFER_SWEEP_MS);
    this.paymentTimer = setInterval(() => void this.paymentSweep(), PAYMENT_SWEEP_MS);
    this.offerTimer.unref();
    this.paymentTimer.unref();
    this.logger.log(`Offer-expiry sweep every ${OFFER_SWEEP_MS / 1000}s; payment-window sweep every ${PAYMENT_SWEEP_MS / 1000}s`);
  }

  onModuleDestroy(): void {
    if (this.offerTimer) clearInterval(this.offerTimer);
    if (this.paymentTimer) clearInterval(this.paymentTimer);
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
