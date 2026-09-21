import { Injectable } from '@nestjs/common';
import {
  commissionInForce,
  haversineMetres,
  LatLng,
  PricedRoute,
  PricingTables,
  priceRoute,
  resolveZone,
} from '@pokbon-delivery/shared';
import { PrismaService } from '../prisma/prisma.service';
import { SettingsService } from '../settings/settings.service';

/**
 * What a route costs. PRD § 9, revised 2026-09-21.
 *
 * Three rungs, first match wins: an explicit zone pair, then the bands those
 * zones belong to, then how far it actually is. The rules themselves live in
 * @pokbon-delivery/shared as a pure function, so the plugin and this service
 * cannot drift and the logic is testable without a database.
 *
 * Francis sets every number in the plugin. Nothing here invents a price, and a
 * route no rung answers is reported as unserved rather than guessed at.
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

  /**
   * Price a route between two points, whatever is known about them.
   *
   * Coordinates are optional: a requester who could not get a fix, or typed an
   * address instead, still prices through the zone and band rungs. Because
   * pricing is zone-based rather than per-kilometre, that is not a degraded
   * answer — it is the same answer.
   */
  async quoteForPoints(
    pickup: Partial<LatLng> & { zoneCode?: string },
    dropoff: Partial<LatLng> & { zoneCode?: string },
  ): Promise<{ quote: (PricedRoute & { priceVersion: number }) | null; fromZoneCode: string | null; toZoneCode: string | null }> {
    const [zones, tables] = await Promise.all([this.zoneIndex(), this.tables()]);

    const from = await this.resolve(pickup, zones);
    const to = await this.resolve(dropoff, zones);

    const distanceMetres =
      typeof pickup.lat === 'number' &&
      typeof pickup.lng === 'number' &&
      typeof dropoff.lat === 'number' &&
      typeof dropoff.lng === 'number'
        ? haversineMetres({ lat: pickup.lat, lng: pickup.lng }, { lat: dropoff.lat, lng: dropoff.lng })
        : // No coordinates at either end, but both zones known: fall back to the
          // distance between the zone centres so the third rung can still
          // answer. A zone centre is a fair stand-in for "somewhere in Madina".
          from.zone && to.zone
          ? haversineMetres({ lat: from.zone.lat, lng: from.zone.lng }, { lat: to.zone.lat, lng: to.zone.lng })
          : null;

    const priced = priceRoute(
      {
        fromZoneCode: from.code,
        toZoneCode: to.code,
        fromBand: from.zone?.band ?? null,
        toBand: to.zone?.band ?? null,
        distanceMetres,
      },
      tables,
    );

    return {
      quote: priced ? { ...priced, priceVersion: this.settings.syncVersion } : null,
      fromZoneCode: from.code,
      toZoneCode: to.code,
    };
  }

  /** Straight zone-code lookup, for callers that already know both ends. */
  async quote(fromZoneCode: string, toZoneCode: string) {
    const zones = await this.zoneIndex();
    const from = zones.get(fromZoneCode) ?? null;
    const to = zones.get(toZoneCode) ?? null;

    const priced = priceRoute(
      {
        fromZoneCode,
        toZoneCode,
        fromBand: from?.band ?? null,
        toBand: to?.band ?? null,
        distanceMetres:
          from && to ? haversineMetres({ lat: from.lat, lng: from.lng }, { lat: to.lat, lng: to.lng }) : null,
      },
      await this.tables(),
    );

    return priced ? { ...priced, priceVersion: this.settings.syncVersion } : null;
  }

  /** Rider commission in force now. Zero for about six months from go-live (§ 9d). */
  commissionNow(at: Date = new Date()): { rateBps: number; flatMinor: number } {
    return commissionInForce(this.settings.get('rider_commission_schedule'), at);
  }

  commissionOn(riderFeeMinor: number, rate: { rateBps: number; flatMinor: number }): number {
    return Math.round((riderFeeMinor * rate.rateBps) / 10_000) + rate.flatMinor;
  }

  // ─── the tables the ladder walks ──────────────────────────────────────────

  private async zoneIndex() {
    const zones = await this.prisma.zone.findMany({ where: { active: true } });
    return new Map(zones.map((z) => [z.code, z]));
  }

  private async tables(): Promise<PricingTables> {
    const [pairs, bands, distances] = await Promise.all([
      this.prisma.zonePrice.findMany({ where: { active: true } }),
      this.prisma.bandPrice.findMany({ where: { active: true } }),
      this.prisma.distanceBand.findMany({ where: { active: true }, orderBy: { maxKm: 'asc' } }),
    ]);

    return {
      zonePairs: new Map(
        pairs.map((p) => [
          `${p.fromZoneCode}|${p.toZoneCode}`,
          { riderFeeMinor: p.riderFeeMinor, buyerPriceMinor: p.buyerPriceMinor },
        ]),
      ),
      bandPairs: bands.map((b) => ({
        fromBand: b.fromBand,
        toBand: b.toBand,
        riderFeeMinor: b.riderFeeMinor,
        buyerPriceMinor: b.buyerPriceMinor,
      })),
      distanceBands: distances.map((d) => ({
        maxKm: d.maxKm,
        riderFeeMinor: d.riderFeeMinor,
        buyerPriceMinor: d.buyerPriceMinor,
      })),
    };
  }

  private async resolve(
    point: Partial<LatLng> & { zoneCode?: string },
    zones: Map<string, { code: string; lat: number; lng: number; radiusMetres: number; active: boolean; band: string | null }>,
  ): Promise<{ code: string | null; zone: { lat: number; lng: number; band: string | null } | null }> {
    // An explicit zone wins over a pin: somebody chose it, and a pin near a
    // boundary should not silently overrule that choice.
    if (point.zoneCode && zones.has(point.zoneCode)) {
      const z = zones.get(point.zoneCode)!;
      return { code: z.code, zone: z };
    }
    if (typeof point.lat === 'number' && typeof point.lng === 'number') {
      const found = resolveZone({ lat: point.lat, lng: point.lng }, [...zones.values()]);
      return found ? { code: found.code, zone: found } : { code: null, zone: null };
    }
    return { code: null, zone: null };
  }
}
