import { BadRequestException, Body, Controller, Get, HttpCode, HttpStatus, Param, Post, Query } from '@nestjs/common';
import { riderDecisionSchema, riderUpsertSchema } from '@pokbon-delivery/shared';
import { toAdminRiderSummary } from '../jobs/serialisers';
import { PluginAuth } from '../plugin/plugin-auth.guard';
import { RidersService } from '../riders/riders.service';

/** Rider queue, roster and decisions, for the admin plugin. */
@Controller('plugin/riders')
export class PluginRidersController {
  constructor(private readonly riders: RidersService) {}

  /**
   * Payout requests waiting on the owner.
   *
   * Deliberately above the rider routes: this is the screen somebody opens
   * when a rider has asked to be paid, and the one they will open most.
   */
  @PluginAuth()
  @Get('payouts')
  payouts(@Query('status') status?: string) {
    return this.riders.listPayoutRequests(status && status !== '' ? status : 'REQUESTED');
  }

  /** The money left. Records a negative PAYOUT so the balance means something. */
  @PluginAuth()
  @Post('payouts/:id/settle')
  @HttpCode(HttpStatus.OK)
  settle(@Param('id') id: string, @Body() body: { amount?: number; actor?: string; note?: string }) {
    const amount = Number(body?.amount);
    if (!Number.isFinite(amount) || amount <= 0) {
      throw new BadRequestException('A payout amount is required');
    }
    return this.riders.settlePayout(id, Math.round(amount * 100), String(body?.actor ?? 'owner'), body?.note);
  }

  /** Turned down, with a reason the rider can read. */
  @PluginAuth()
  @Post('payouts/:id/decline')
  @HttpCode(HttpStatus.OK)
  decline(@Param('id') id: string, @Body() body: { actor?: string; note?: string }) {
    const note = String(body?.note ?? '').trim();
    if (note.length < 5) {
      throw new BadRequestException('Say why, so the rider is told something they can act on');
    }
    return this.riders.declinePayout(id, String(body?.actor ?? 'owner'), note);
  }

  @PluginAuth()
  @Get()
  async list(@Query('status') status?: string) {
    const riders = await this.riders.list(status?.toUpperCase());
    return {
      riders: riders.map((r) => ({
        ...toAdminRiderSummary(r),
        appliedAt: r.appliedAt,
        createdAt: r.createdAt,
        lastLocationAt: r.location?.updatedAt ?? null,
      })),
    };
  }

  /**
   * Create or update a rider from the admin, keyed on their phone number.
   *
   * Idempotent: running it twice on the same number updates rather than
   * duplicating, which matters because the phone number is also the login
   * identity and two riders on one number could not both sign in.
   */
  @PluginAuth()
  @Post()
  @HttpCode(HttpStatus.OK)
  upsert(@Body() body: unknown) {
    const parsed = riderUpsertSchema.safeParse(body);
    if (!parsed.success) {
      throw new BadRequestException(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`));
    }
    return this.riders.upsertFromPlugin(parsed.data);
  }

  @PluginAuth()
  @Get(':id')
  detail(@Param('id') id: string) {
    return this.riders.detail(id);
  }

  @PluginAuth()
  @Post(':id/decision')
  @HttpCode(HttpStatus.OK)
  decide(@Param('id') id: string, @Body() body: unknown) {
    const parsed = riderDecisionSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return this.riders.decide(id, parsed.data);
  }
}
