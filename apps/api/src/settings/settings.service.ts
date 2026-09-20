import { Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Prisma } from '@prisma/client';
import { SETTING_DEFAULTS, SettingsShape, settingsSyncSchema } from '@pokbon-delivery/shared';
import type { z } from 'zod';
import { PluginClient } from '../plugin/plugin.client';
import { PrismaService } from '../prisma/prisma.service';

const SYNC_VERSION_KEY = '_sync_version';

/**
 * The settings cache. PRD § 1a and § 12b.
 *
 * The WordPress plugin is the source of truth. It pushes on save; the API
 * pulls on boot when PLUGIN_SYNC_ON_BOOT is set. Reads come from memory,
 * refreshed on every sync, with SETTING_DEFAULTS underneath for keys that have
 * never been synced. Nothing in the product reads a tunable from anywhere else.
 */
@Injectable()
export class SettingsService implements OnModuleInit {
  private readonly logger = new Logger(SettingsService.name);
  private cache = new Map<string, unknown>();
  private version = 0;

  constructor(
    private readonly prisma: PrismaService,
    private readonly plugin: PluginClient,
    private readonly config: ConfigService,
  ) {}

  async onModuleInit(): Promise<void> {
    await this.reload();
    if (this.config.getOrThrow<boolean>('PLUGIN_SYNC_ON_BOOT')) {
      try {
        const payload = await this.plugin.pullSettings();
        if (payload) {
          // `force`, because a PULL is by definition the plugin's current
          // state. The version guard exists to stop two admin saves arriving
          // out of order on a PUSH; applying it here instead made the API
          // silently keep stale prices and log success — which is how a rider
          // ends up dispatched on a fee nobody set.
          const applied = await this.applySync(payload, { force: true });
          this.logger.log(
            `Pulled settings from the plugin: version ${applied.version}, ` +
              `${applied.zones} zone(s), ${applied.prices} price(s)`,
          );
        }
      } catch (error) {
        // Boot on the cache. A plugin that is briefly down must not take dispatch with it.
        this.logger.error(`Settings pull failed; running on cached values: ${String(error)}`);
      }
    }
    this.logger.log(`Settings loaded (sync version ${this.version}, ${this.cache.size} keys cached).`);
  }

  get<K extends keyof SettingsShape>(key: K): SettingsShape[K] {
    return (this.cache.has(key) ? this.cache.get(key) : SETTING_DEFAULTS[key]) as SettingsShape[K];
  }

  get syncVersion(): number {
    return this.version;
  }

  /** Every effective value, for the admin plugin to show what the API is actually running on. */
  effective(): { version: number; settings: SettingsShape } {
    const merged = { ...SETTING_DEFAULTS } as Record<string, unknown>;
    for (const [k, v] of this.cache) if (k !== SYNC_VERSION_KEY) merged[k] = v;
    return { version: this.version, settings: merged as unknown as SettingsShape };
  }

  /**
   * Apply a sync from the plugin. Zones and prices are replaced wholesale when
   * present; settings are merged. Amounts arrive in GHS and are stored in
   * pesewas. A sync with a lower version than the one already applied is
   * ignored — two admin tabs saving out of order must not roll prices back.
   */
  async applySync(
    raw: unknown,
    opts: { force?: boolean } = {},
  ): Promise<{ version: number; zones: number; prices: number; settings: number; ignored?: boolean }> {
    const parsed = settingsSyncSchema.safeParse(raw);
    if (!parsed.success) {
      throw new Error(`Invalid settings sync: ${parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')}`);
    }
    const payload: z.infer<typeof settingsSyncSchema> = parsed.data;

    if (!opts.force && payload.version < this.version) {
      // Deliberately a warning, not a debug line. Silently keeping old prices
      // looks identical to working correctly until somebody is paid the wrong
      // amount.
      this.logger.warn(
        `IGNORED a settings sync: it carried v${payload.version} and this service is already at ` +
          `v${this.version}, so it was treated as out of order. Prices and zones are UNCHANGED. ` +
          `If the plugin was reinstalled its counter may have restarted — push again from the ` +
          `plugin, which forces its own version.`,
      );
      return { version: this.version, zones: 0, prices: 0, settings: 0, ignored: true };
    }

    let zones = 0;
    let prices = 0;
    let settings = 0;

    await this.prisma.$transaction(async (tx) => {
      if (payload.zones) {
        const codes = payload.zones.map((z) => z.code);
        // Zones missing from the payload are deactivated, not deleted (PRD § 12c).
        await tx.zone.updateMany({ where: { code: { notIn: codes } }, data: { active: false } });
        for (const z of payload.zones) {
          await tx.zone.upsert({
            where: { code: z.code },
            create: { code: z.code, name: z.name, region: z.region ?? null, lat: z.lat, lng: z.lng, radiusMetres: z.radiusMetres, active: z.active },
            update: { name: z.name, region: z.region ?? null, lat: z.lat, lng: z.lng, radiusMetres: z.radiusMetres, active: z.active },
          });
          zones++;
        }
      }

      if (payload.prices) {
        const keys = payload.prices.map((p) => `${p.fromZoneCode}→${p.toZoneCode}`);
        const existing = await tx.zonePrice.findMany({ select: { id: true, fromZoneCode: true, toZoneCode: true } });
        const toDeactivate = existing.filter((e) => !keys.includes(`${e.fromZoneCode}→${e.toZoneCode}`)).map((e) => e.id);
        if (toDeactivate.length) await tx.zonePrice.updateMany({ where: { id: { in: toDeactivate } }, data: { active: false } });
        for (const p of payload.prices) {
          const riderFeeMinor = toMinor(p.riderFee);
          const buyerPriceMinor = toMinor(p.buyerPrice);
          await tx.zonePrice.upsert({
            where: { fromZoneCode_toZoneCode: { fromZoneCode: p.fromZoneCode, toZoneCode: p.toZoneCode } },
            create: { fromZoneCode: p.fromZoneCode, toZoneCode: p.toZoneCode, riderFeeMinor, buyerPriceMinor, version: payload.version, active: p.active },
            update: { riderFeeMinor, buyerPriceMinor, version: payload.version, active: p.active },
          });
          prices++;
        }
      }

      if (payload.settings) {
        for (const [key, value] of Object.entries(payload.settings)) {
          if (!(key in SETTING_DEFAULTS)) {
            this.logger.warn(`Settings sync carried unknown key "${key}"; stored but unused`);
          }
          await tx.setting.upsert({
            where: { key },
            create: { key, value: value as Prisma.InputJsonValue },
            update: { value: value as Prisma.InputJsonValue },
          });
          settings++;
        }
      }

      await tx.setting.upsert({
        where: { key: SYNC_VERSION_KEY },
        create: { key: SYNC_VERSION_KEY, value: payload.version },
        update: { value: payload.version },
      });
    });

    await this.reload();
    this.logger.log(`Applied settings sync v${payload.version}: ${zones} zones, ${prices} prices, ${settings} settings`);
    return { version: payload.version, zones, prices, settings };
  }

  private async reload(): Promise<void> {
    const rows = await this.prisma.setting.findMany();
    const next = new Map<string, unknown>();
    for (const row of rows) next.set(row.key, row.value);
    this.cache = next;
    this.version = Number(next.get(SYNC_VERSION_KEY) ?? 0);
  }
}

/** GHS → pesewas, rounding to the nearest pesewa. */
export function toMinor(ghs: number): number {
  return Math.round(ghs * 100);
}

export function fromMinor(minor: number): number {
  return Math.round(minor) / 100;
}
