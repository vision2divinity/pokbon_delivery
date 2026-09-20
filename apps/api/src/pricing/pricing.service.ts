import { Injectable } from '@nestjs/common';
import { commissionInForce, LatLng, resolveZone } from '@pokbon-delivery/shared';
import { PrismaService } from '../prisma/prisma.service';
import { SettingsService } from '../settings/settings.service';

export interface Quote {
  fromZoneCode: string;
  toZoneCode: string;
  riderFeeMinor: number;
  /** Never serialised to a rider. */
  buyerPriceMinor: number;
  priceVersion: number;
}

/**
 * The zone matrix. PRD § 9. Francis sets the prices in the plugin; this reads
 * the cache. No formula, no routing engine.
 */
@Injectable()
export class PricingService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
  ) {}

  async zoneFor(point: LatLng): Promise<string | null> {
    const zones = await this.prisma.zone.findMany({ where: { active: true } });
    return resolveZone(point, zones)?.code ?? null;
  }

  async zoneExists(code: string): Promise<boolean> {
    return (await this.prisma.zone.count({ where: { code, active: true } })) > 0;
  }

  /** The price for an ordered pair, or null when POKBON does not serve that route yet. */
  async quote(fromZoneCode: string, toZoneCode: string): Promise<Quote | null> {
    const row = await this.prisma.zonePrice.findUnique({
      where: { fromZoneCode_toZoneCode: { fromZoneCode, toZoneCode } },
    });
    if (!row || !row.active) return null;
    return {
      fromZoneCode,
      toZoneCode,
      riderFeeMinor: row.riderFeeMinor,
      buyerPriceMinor: row.buyerPriceMinor,
      priceVersion: row.version,
    };
  }

  /** Resolve both ends to zones (explicit code wins over the pin) and quote. */
  async quoteForPoints(
    pickup: Partial<LatLng> & { zoneCode?: string },
    dropoff: Partial<LatLng> & { zoneCode?: string },
  ): Promise<{ quote: Quote | null; fromZoneCode: string | null; toZoneCode: string | null }> {
    const fromZoneCode = await this.resolve(pickup);
    const toZoneCode = await this.resolve(dropoff);
    if (!fromZoneCode || !toZoneCode) return { quote: null, fromZoneCode, toZoneCode };
    return { quote: await this.quote(fromZoneCode, toZoneCode), fromZoneCode, toZoneCode };
  }

  /** Rider commission in force now. Zero for about six months from go-live (§ 9d). */
  commissionNow(at: Date = new Date()): { rateBps: number; flatMinor: number } {
    return commissionInForce(this.settings.get('rider_commission_schedule'), at);
  }

  commissionOn(riderFeeMinor: number, rate: { rateBps: number; flatMinor: number }): number {
    return Math.round((riderFeeMinor * rate.rateBps) / 10_000) + rate.flatMinor;
  }

  private async resolve(p: Partial<LatLng> & { zoneCode?: string }): Promise<string | null> {
    if (p.zoneCode && (await this.zoneExists(p.zoneCode))) return p.zoneCode;
    if (typeof p.lat === 'number' && typeof p.lng === 'number') return this.zoneFor({ lat: p.lat, lng: p.lng });
    return null;
  }
}
