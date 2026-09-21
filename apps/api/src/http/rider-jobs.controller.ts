import { BadRequestException, Body, Controller, Get, HttpCode, HttpStatus, Param, Post } from '@nestjs/common';
import {
  arrivedSchema,
  declineOfferSchema,
  deliveredSchema,
  failedSchema,
  ghanaPhone,
  pickedUpSchema,
  returnedSchema,
  verifyCodeSchema,
} from '@pokbon-delivery/shared';
import { z } from 'zod';
import { CurrentRider } from '../auth/auth.guard';
import { DispatchService } from '../dispatch/dispatch.service';
import { JobsService } from '../jobs/jobs.service';
import { toRiderJobView, toRiderOfferView } from '../jobs/serialisers';
import { PrismaService } from '../prisma/prisma.service';

/**
 * Rider-facing job endpoints. Every response goes through the rider
 * serialiser, which has no field for the buyer's price or the order amount.
 */
@Controller('rider/jobs')
export class RiderJobsController {
  constructor(
    private readonly jobs: JobsService,
    private readonly dispatch: DispatchService,
    private readonly prisma: PrismaService,
  ) {}

  @Get('offers')
  async offers(@CurrentRider('sub') riderId: string) {
    const offers = await this.dispatch.openOffersFor(riderId);
    return { offers: offers.map(toRiderOfferView) };
  }

  @Post('offers/:offerId/accept')
  @HttpCode(HttpStatus.OK)
  async accept(@CurrentRider('sub') riderId: string, @Param('offerId') offerId: string) {
    const job = await this.dispatch.accept(offerId, riderId);
    return toRiderJobView(job);
  }

  @Post('offers/:offerId/decline')
  @HttpCode(HttpStatus.NO_CONTENT)
  async decline(@CurrentRider('sub') riderId: string, @Param('offerId') offerId: string, @Body() body: unknown) {
    const parsed = declineOfferSchema.safeParse(body ?? {});
    await this.dispatch.decline(offerId, riderId, parsed.success ? parsed.data.reason : undefined);
  }

  @Get('active')
  async active(@CurrentRider('sub') riderId: string) {
    const jobs = await this.jobs.activeForRider(riderId);
    return { jobs: jobs.map((j) => toRiderJobView(j)) };
  }

  @Get('history')
  async history(@CurrentRider('sub') riderId: string) {
    const jobs = await this.prisma.job.findMany({
      where: { riderId, status: { in: ['DELIVERED', 'RETURNED', 'CANCELLED'] } },
      orderBy: { updatedAt: 'desc' },
      take: 100,
    });
    return { jobs: jobs.map((j) => toRiderJobView(j)) };
  }

  @Get(':id')
  async one(@CurrentRider('sub') riderId: string, @Param('id') id: string) {
    // A rider refreshing a job whose payment is pending is asking the one
    // question this endpoint can answer authoritatively, so ask it.
    const job = await this.jobs.refreshPaymentIfPending(await this.jobs.mustOwn(id, riderId));
    const photos = await this.prisma.jobPhoto.findMany({ where: { jobId: id }, orderBy: { createdAt: 'asc' } });
    return toRiderJobView(job, { photos });
  }

  @Post(':id/at-pickup')
  @HttpCode(HttpStatus.OK)
  async atPickup(@CurrentRider('sub') riderId: string, @Param('id') id: string) {
    return toRiderJobView(await this.jobs.atPickup(id, riderId));
  }

  @Post(':id/picked-up')
  @HttpCode(HttpStatus.OK)
  async pickedUp(@CurrentRider('sub') riderId: string, @Param('id') id: string, @Body() body: unknown) {
    const parsed = pickedUpSchema.safeParse(body ?? {});
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toRiderJobView(await this.jobs.pickedUp(id, riderId, parsed.data.photo));
  }

  @Post(':id/en-route')
  @HttpCode(HttpStatus.OK)
  async enRoute(@CurrentRider('sub') riderId: string, @Param('id') id: string) {
    return toRiderJobView(await this.jobs.enRoute(id, riderId));
  }

  @Post(':id/arrived')
  @HttpCode(HttpStatus.OK)
  async arrived(@CurrentRider('sub') riderId: string, @Param('id') id: string, @Body() body: unknown) {
    const parsed = arrivedSchema.safeParse(body ?? {});
    return toRiderJobView(await this.jobs.arrived(id, riderId, parsed.success ? parsed.data : undefined));
  }

  /** Sends the code to the buyer. The response never contains it. */
  @Post(':id/send-code')
  @HttpCode(HttpStatus.OK)
  async sendCode(@CurrentRider('sub') riderId: string, @Param('id') id: string) {
    return toRiderJobView(await this.jobs.sendCode(id, riderId));
  }

  /** Matched or not. On a match, pay-on-delivery jobs move to PAYMENT_PENDING. */
  @Post(':id/verify-code')
  @HttpCode(HttpStatus.OK)
  async verifyCode(@CurrentRider('sub') riderId: string, @Param('id') id: string, @Body() body: unknown) {
    const parsed = verifyCodeSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    const { job, matched, outcome } = await this.jobs.verifyCode(id, riderId, parsed.data.code);
    return { matched, outcome, job: toRiderJobView(job) };
  }

  @Post(':id/prompt-again')
  @HttpCode(HttpStatus.OK)
  async promptAgain(@CurrentRider('sub') riderId: string, @Param('id') id: string) {
    return toRiderJobView(await this.jobs.promptAgain(id, riderId));
  }

  @Post(':id/pay-by-link')
  @HttpCode(HttpStatus.OK)
  async payByLink(@CurrentRider('sub') riderId: string, @Param('id') id: string, @Body() body: unknown) {
    const parsed = z.object({ phone: ghanaPhone }).safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toRiderJobView(await this.jobs.payByLink(id, riderId, parsed.data.phone));
  }

  @Post(':id/delivered')
  @HttpCode(HttpStatus.OK)
  async delivered(@CurrentRider('sub') riderId: string, @Param('id') id: string, @Body() body: unknown) {
    const parsed = deliveredSchema.safeParse(body ?? {});
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toRiderJobView(await this.jobs.delivered(id, riderId, parsed.data.photo));
  }

  @Post(':id/failed')
  @HttpCode(HttpStatus.OK)
  async failed(@CurrentRider('sub') riderId: string, @Param('id') id: string, @Body() body: unknown) {
    const parsed = failedSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toRiderJobView(await this.jobs.failed(id, riderId, parsed.data));
  }

  @Post(':id/returned')
  @HttpCode(HttpStatus.OK)
  async returned(@CurrentRider('sub') riderId: string, @Param('id') id: string, @Body() body: unknown) {
    const parsed = returnedSchema.safeParse(body ?? {});
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return toRiderJobView(await this.jobs.returned(id, riderId, parsed.data));
  }
}
