import { BadRequestException, Body, Controller, Get, HttpCode, HttpStatus, Patch, Post, Put } from '@nestjs/common';
import { acceptAgreementSchema, dutySchema, locationPingSchema, updateRiderProfileSchema } from '@pokbon-delivery/shared';
import { CurrentRider } from '../auth/auth.guard';
import { RidersService } from '../riders/riders.service';

/** The rider's own account. Rider JWT. */
@Controller('rider/me')
export class RiderMeController {
  constructor(private readonly riders: RidersService) {}

  @Get()
  me(@CurrentRider('sub') riderId: string) {
    return this.riders.me(riderId);
  }

  @Patch()
  update(@CurrentRider('sub') riderId: string, @Body() body: unknown) {
    const parsed = updateRiderProfileSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`));
    return this.riders.updateProfile(riderId, parsed.data);
  }

  @Post('agreement')
  agreement(@CurrentRider('sub') riderId: string, @Body() body: unknown) {
    const parsed = acceptAgreementSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return this.riders.acceptAgreement(riderId, parsed.data.version);
  }

  @Post('apply')
  apply(@CurrentRider('sub') riderId: string) {
    return this.riders.submitApplication(riderId);
  }

  @Put('duty')
  duty(@CurrentRider('sub') riderId: string, @Body() body: unknown) {
    const parsed = dutySchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return this.riders.setDuty(riderId, parsed.data.onDuty, { lat: parsed.data.lat, lng: parsed.data.lng });
  }

  @Post('location')
  @HttpCode(HttpStatus.OK)
  location(@CurrentRider('sub') riderId: string, @Body() body: unknown) {
    const parsed = locationPingSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return this.riders.ping(riderId, parsed.data.lat, parsed.data.lng, parsed.data.accuracy);
  }

  @Get('earnings')
  earnings(@CurrentRider('sub') riderId: string) {
    return this.riders.earnings(riderId);
  }

  /**
   * What the rider is owed, and whether they may ask for it yet.
   *
   * Separate from /me so the app can poll it cheaply after a delivery closes
   * without re-fetching the whole profile.
   */
  @Get('payout')
  payoutStatus(@CurrentRider('sub') riderId: string) {
    return this.riders.payoutStatus(riderId);
  }

  /** "Please pay me." The one thing a rider could previously only do by phone. */
  @Post('payout')
  requestPayout(@CurrentRider('sub') riderId: string, @Body() body: { note?: string }) {
    return this.riders.requestPayout(riderId, typeof body?.note === 'string' ? body.note : undefined);
  }

  @Post('leave')
  leave(@CurrentRider('sub') riderId: string) {
    return this.riders.leave(riderId);
  }
}
