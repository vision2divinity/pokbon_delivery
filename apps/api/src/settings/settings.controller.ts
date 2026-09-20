import { BadRequestException, Body, Controller, Get, HttpCode, HttpStatus, Post } from '@nestjs/common';
import { PluginAuth } from '../plugin/plugin-auth.guard';
import { PrismaService } from '../prisma/prisma.service';
import { fromMinor, SettingsService } from './settings.service';

/**
 * The plugin pushes zones, prices and settings here on save. PRD § 1a.
 */
@Controller('plugin/settings')
export class SettingsController {
  constructor(
    private readonly settings: SettingsService,
    private readonly prisma: PrismaService,
  ) {}

  @PluginAuth()
  @Post('sync')
  @HttpCode(HttpStatus.OK)
  async sync(@Body() body: unknown) {
    try {
      return await this.settings.applySync(body);
    } catch (error) {
      throw new BadRequestException(String((error as Error).message ?? error));
    }
  }

  /** What the API is actually running on, for the plugin's settings page to show. */
  @PluginAuth()
  @Get()
  async effective() {
    const [zones, prices] = await Promise.all([
      this.prisma.zone.findMany({ orderBy: { name: 'asc' } }),
      this.prisma.zonePrice.findMany({ where: { active: true } }),
    ]);
    return {
      ...this.settings.effective(),
      zones,
      prices: prices.map((p) => ({
        fromZoneCode: p.fromZoneCode,
        toZoneCode: p.toZoneCode,
        riderFee: fromMinor(p.riderFeeMinor),
        buyerPrice: fromMinor(p.buyerPriceMinor),
        margin: fromMinor(p.buyerPriceMinor - p.riderFeeMinor),
        version: p.version,
      })),
    };
  }
}
