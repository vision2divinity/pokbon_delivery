// Pushes the six launch zones and a starter price matrix into the API the way
// the plugin will: a signed POST to /plugin/settings/sync. Requires the API
// running. Prices here are placeholders for development — Francis sets the
// real ones in the plugin (PRD § 9).
import { pluginCall, readEnv } from './lib/plugin-sign.mjs';

const { base, secret } = readEnv();

const zones = [
  { code: 'MADINA', name: 'Madina & environs', region: 'Greater Accra', lat: 5.6689, lng: -0.1651, radiusMetres: 5000, active: true },
  { code: 'CIRCLE', name: 'Circle & environs', region: 'Greater Accra', lat: 5.5717, lng: -0.2115, radiusMetres: 5000, active: true },
  { code: 'ASHAIMAN', name: 'Ashaiman & environs', region: 'Greater Accra', lat: 5.6928, lng: -0.0341, radiusMetres: 5000, active: true },
  { code: 'LAPAZ', name: 'Lapaz & environs', region: 'Greater Accra', lat: 5.6076, lng: -0.2436, radiusMetres: 5000, active: true },
  { code: 'KUMASI', name: 'Kumasi & environs', region: 'Ashanti', lat: 6.6885, lng: -1.6244, radiusMetres: 8000, active: true },
  { code: 'SANTASI', name: 'Santasi & environs', region: 'Ashanti', lat: 6.6585, lng: -1.6553, radiusMetres: 5000, active: true },
];

// Francis's example: Madina → Circle, rider 30, buyer 40. The rest are dev
// placeholders derived from it so every Accra pair and every Kumasi pair prices.
const accra = ['MADINA', 'CIRCLE', 'ASHAIMAN', 'LAPAZ'];
const kumasi = ['KUMASI', 'SANTASI'];
const prices = [];
for (const group of [accra, kumasi]) {
  for (const from of group) {
    for (const to of group) {
      const riderFee = from === to ? 15 : 30;
      prices.push({ fromZoneCode: from, toZoneCode: to, riderFee, buyerPrice: from === to ? 20 : 40, active: true });
    }
  }
}

const settings = {
  agreement_version: '2026-09-20',
  active_vehicle_classes: ['MOTORBIKE'],
  rider_commission_schedule: [],
  failed_trip_uplift: { rateBps: 2000 },
  operating_hours: { open: '06:00', close: '22:00', days: [0, 1, 2, 3, 4, 5, 6] },
};

const version = Number(process.env.SYNC_VERSION ?? Math.floor(Date.now() / 1000));
const result = await pluginCall(base, secret, 'POST', '/plugin/settings/sync', { version, zones, prices, settings });
console.log('Settings sync applied:', result);
