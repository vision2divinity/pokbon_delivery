import { BadRequestException, Body, Controller, Get, HttpCode, HttpStatus, Param, Post, Query } from '@nestjs/common';
import { riderDecisionSchema } from '@pokbon-delivery/shared';
import { toAdminRiderSummary } from '../jobs/serialisers';
import { PluginAuth } from '../plugin/plugin-auth.guard';
import { RidersService } from '../riders/riders.service';

/** Rider queue, roster and decisions, for the admin plugin. */
@Controller('plugin/riders')
export class PluginRidersController {
  constructor(private readonly riders: RidersService) {}

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
