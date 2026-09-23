import { BadRequestException, Body, Controller, Get, HttpCode, HttpStatus, Param, Post, Query } from '@nestjs/common';
import {
  assignJobSchema,
  bypassCodeSchema,
  cancelJobSchema,
  createJobSchema,
  paymentOutcomeSchema,
  quoteSchema,
} from '@pokbon-delivery/shared';
import { DispatchService } from '../dispatch/dispatch.service';
import { JobsService } from '../jobs/jobs.service';
import { toAdminJobView, toTrackingView } from '../jobs/serialisers';
import { PluginAuth } from '../plugin/plugin-auth.guard';
import { PricingService } from '../pricing/pricing.service';
import { PrismaService } from '../prisma/prisma.service';
import { fromMinor } from '../settings/settings.service';

/**
 * What the WordPress plugin calls. Service signature on every route.
 * Job creation and cancellation follow the marketplace contract § 4; the rest
 * is the admin console: board, detail, manual assign, bypass, payment outcome.
 */
@Controller()
export class PluginJobsController {
  constructor(
    private readonly jobs: JobsService,
    private readonly dispatch: DispatchService,
    private readonly pricing: PricingService,
    private readonly prisma: PrismaService,
  ) {}

  /** Contract § 4: POST {delivery}/jobs. 201 with the job; 200 when replayed. */
  @PluginAuth()
  @Post('jobs')
  @HttpCode(HttpStatus.CREATED)
  async create(@Body() body: unknown) {
    const parsed = createJobSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`));
    const { job, created } = await this.jobs.createFromPlugin(parsed.data);
    return {
      jobId: job.id,
      created,
      status: job.status,
      quotedFee: fromMinor(job.buyerPriceMinor),
      riderFee: fromMinor(job.riderFeeMinor),
      pickupZoneCode: job.pickupZoneCode,
      dropoffZoneCode: job.dropoffZoneCode,
      etaMinutes: null,
    };
  }

  /** Price a route before creating anything. For checkout and for requester quotes. */
  @PluginAuth()
  @Get('quote')
  async quote(@Query() query: Record<string, string>) {
    const parsed = quoteSchema.safeParse({
      pickup: { lat: num(query['pickup.lat']), lng: num(query['pickup.lng']), zoneCode: query['pickup.zoneCode'] },
      dropoff: { lat: num(query['dropoff.lat']), lng: num(query['dropoff.lng']), zoneCode: query['dropoff.zoneCode'] },
    });
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`));
    const { quote, fromZoneCode, toZoneCode } = await this.pricing.quoteForPoints(parsed.data.pickup, parsed.data.dropoff);
    return {
      served: Boolean(quote),
      fromZoneCode,
      toZoneCode,
      buyerPrice: quote ? fromMinor(quote.buyerPriceMinor) : null,
      riderFee: quote ? fromMinor(quote.riderFeeMinor) : null,
      priceVersion: quote?.priceVersion ?? null,
      currency: 'GHS',
    };
  }

  @PluginAuth()
  @Post('jobs/:id/cancel')
  @HttpCode(HttpStatus.OK)
  async cancel(@Param('id') id: string, @Body() body: unknown) {
    const parsed = cancelJobSchema.extend({ actor: cancelJobSchema.shape.reason.optional() }).safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toAdminJobView(await this.jobs.cancelFromPlugin(id, parsed.data.reason, parsed.data.actor ?? 'marketplace'));
  }

  /** Contract § 4b: the doorstep payment outcome, from the Paystack webhook via the plugin. */
  @PluginAuth()
  @Post('jobs/:id/payment')
  @HttpCode(HttpStatus.OK)
  async payment(@Param('id') id: string, @Body() body: unknown) {
    const parsed = paymentOutcomeSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toAdminJobView(await this.jobs.paymentOutcome(id, parsed.data));
  }

  /** Dispatcher assigns a rider by hand. */
  @PluginAuth()
  @Post('jobs/:id/assign')
  @HttpCode(HttpStatus.OK)
  async assign(@Param('id') id: string, @Body() body: unknown) {
    const parsed = assignJobSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toAdminJobView(await this.jobs.assignManually(id, parsed.data.riderId, parsed.data.actor));
  }

  /** Start (or restart) the offer cascade. */
  @PluginAuth()
  @Post('jobs/:id/offer')
  @HttpCode(HttpStatus.OK)
  async offer(@Param('id') id: string) {
    const offer = await this.dispatch.offerNext(id, 'plugin');
    return { offered: Boolean(offer), offer };
  }

  /**
   * The order behind this job was cancelled. The API decides what that means:
   * called off before collection, recalled for return after it.
   */
  @PluginAuth()
  @Post('jobs/:id/recall')
  @HttpCode(HttpStatus.OK)
  async recall(@Param('id') id: string, @Body() body: unknown) {
    const parsed = cancelJobSchema.extend({ actor: cancelJobSchema.shape.reason.optional() }).safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    const { job, outcome } = await this.jobs.recallForOrderCancellation(id, parsed.data.reason, parsed.data.actor ?? 'marketplace');
    // The outcome matters to the caller: "recalled" means a rider is still
    // holding goods that now have to come back, which is not a closed job.
    return { outcome, job: toAdminJobView(job) };
  }

  /** Dispatcher closes a failed job: the goods are back with the sender. */
  @PluginAuth()
  @Post('jobs/:id/returned')
  @HttpCode(HttpStatus.OK)
  async markReturned(@Param('id') id: string, @Body() body: unknown) {
    // Same shape as cancel: a reason, and who decided it.
    const parsed = cancelJobSchema.extend({ actor: cancelJobSchema.shape.reason.optional() }).safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toAdminJobView(await this.jobs.returnedFromPlugin(id, parsed.data.reason, parsed.data.actor ?? 'marketplace'));
  }

  /** Audited code bypass with a mandatory reason. PRD § 7. */
  @PluginAuth()
  @Post('jobs/:id/bypass-code')
  @HttpCode(HttpStatus.OK)
  async bypass(@Param('id') id: string, @Body() body: unknown) {
    const parsed = bypassCodeSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toAdminJobView(await this.jobs.bypassCode(id, parsed.data.reason, parsed.data.actor));
  }

  /** The live board. */
  @PluginAuth()
  @Get('jobs')
  async list(
    @Query('status') status?: string,
    @Query('source') source?: string,
    @Query('limit') limit?: string,
    @Query('from') from?: string,
    @Query('to') to?: string,
  ) {
    /*
     * from/to are for reconciliation, which is always "this week" or "last
     * month" rather than "the last hundred". They bound createdAt because
     * that is when the money was committed to — a job created on Friday and
     * delivered on Monday belongs to Friday's ladder and Friday's commission
     * rate, both of which are frozen on the job.
     */
    const since = from ? new Date(`${from}T00:00:00.000Z`) : null;
    const until = to ? new Date(`${to}T23:59:59.999Z`) : null;
    const range =
      since || until
        ? {
            createdAt: {
              ...(since && !Number.isNaN(since.valueOf()) ? { gte: since } : {}),
              ...(until && !Number.isNaN(until.valueOf()) ? { lte: until } : {}),
            },
          }
        : {};

    const jobs = await this.prisma.job.findMany({
      where: {
        ...(status ? { status: { in: status.split(',').map((s) => s.trim().toUpperCase()) } } : {}),
        ...(source ? { source: source.toUpperCase() } : {}),
        ...range,
      },
      include: { rider: true },
      orderBy: { createdAt: 'desc' },
      take: Math.min(Number(limit) || 100, 1000),
    });
    return { jobs: jobs.map((j) => toAdminJobView(j)) };
  }

  @PluginAuth()
  @Get('jobs/:id')
  async one(@Param('id') id: string) {
    const job = await this.prisma.job.findUnique({
      where: { id },
      include: {
        rider: true,
        events: { orderBy: { occurredAt: 'asc' } },
        photos: { orderBy: { createdAt: 'asc' } },
        offers: { include: { rider: true }, orderBy: { sequence: 'asc' } },
      },
    });
    if (!job) throw new BadRequestException('Job not found');
    return toAdminJobView(job);
  }

  /** Contract § 5: live position for the buyer's map, proxied by the plugin. */
  @PluginAuth()
  @Get('jobs/:id/tracking')
  async tracking(@Param('id') id: string) {
    const job = await this.prisma.job.findUnique({ where: { id }, include: { rider: { include: { location: true } } } });
    if (!job) throw new BadRequestException('Job not found');
    return toTrackingView(job);
  }

  /** Same, by marketplace order. */
  @PluginAuth()
  @Get('orders/:orderId/tracking')
  async trackingByOrder(@Param('orderId') orderId: string) {
    const jobs = await this.prisma.job.findMany({
      where: { source: 'MARKETPLACE', externalRef: orderId },
      include: { rider: { include: { location: true } } },
      orderBy: { createdAt: 'asc' },
    });
    return { deliveries: jobs.map((j) => ({ jobId: j.id, vendorId: j.vendorId, ...toTrackingView(j) })) };
  }
}

function num(v: string | undefined): number | undefined {
  if (v === undefined || v === '') return undefined;
  const n = Number(v);
  return Number.isFinite(n) ? n : undefined;
}
