// Prove the plugin and the delivery service price identically.
//
// WHY TWO COPIES EXIST. The plugin must quote at checkout without calling the
// delivery service: a slow quote is a lost sale, and the service may not be
// reachable at all. So the ladder is implemented in PHP and in TypeScript.
// Two copies of pricing logic is exactly the kind of thing that drifts and
// costs money on every job, so this runs the same routes through both and
// fails on any disagreement — in the fee, in which rung answered, or in what
// it matched.
//
// Run: node scripts/check-price-parity.mjs
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { priceRoute, haversineMetres, resolveZone } from '../packages/shared/dist/index.js';

const here = dirname(fileURLToPath(import.meta.url));

// Zone shapes as the plugin stores them.
const zones = [
  { code: 'MADINA', name: 'Madina', lat: 5.6689, lng: -0.1651, radiusMetres: 5000, band: 'OUTER', active: true },
  { code: 'CIRCLE', name: 'Circle', lat: 5.5717, lng: -0.2115, radiusMetres: 5000, band: 'INNER', active: true },
  { code: 'LAPAZ', name: 'Lapaz', lat: 5.6076, lng: -0.2436, radiusMetres: 5000, band: 'INNER', active: true },
  { code: 'KUMASI', name: 'Kumasi', lat: 6.6885, lng: -1.6244, radiusMetres: 8000, band: 'KUMASI', active: true },
  // Deliberately bandless: it must fall all the way to distance.
  { code: 'KASOA', name: 'Kasoa', lat: 5.5343, lng: -0.4162, radiusMetres: 6000, band: '', active: true },
];

const zonePairs = {
  'MADINA|CIRCLE': { riderFeeMinor: 3000, buyerPriceMinor: 4000 },
};

const bandPrices = {
  'INNER|INNER': { riderFeeMinor: 2000, buyerPriceMinor: 2500 },
  'OUTER|INNER': { riderFeeMinor: 3500, buyerPriceMinor: 4500 },
  'INNER|KUMASI': { riderFeeMinor: 12000, buyerPriceMinor: 16000 },
};

const distanceBands = [
  { maxKm: 5, riderFeeMinor: 1500, buyerPriceMinor: 2000 },
  { maxKm: 20, riderFeeMinor: 4000, buyerPriceMinor: 5500 },
  { maxKm: 2000, riderFeeMinor: 15000, buyerPriceMinor: 20000 },
];

const routes = [
  // rung 1
  { from: { zoneCode: 'MADINA' }, to: { zoneCode: 'CIRCLE' } },
  // rung 2, and the reverse which is NOT priced as a pair
  { from: { zoneCode: 'CIRCLE' }, to: { zoneCode: 'LAPAZ' } },
  { from: { zoneCode: 'MADINA' }, to: { zoneCode: 'LAPAZ' } },
  // rung 3: a zone with no band
  { from: { zoneCode: 'CIRCLE' }, to: { zoneCode: 'KASOA' } },
  // rung 3 by raw coordinates, no zone at either end
  { from: { lat: 5.55, lng: -0.22 }, to: { lat: 5.56, lng: -0.23 } },
  // long haul, must hit the final catch-all band
  { from: { zoneCode: 'CIRCLE' }, to: { zoneCode: 'KUMASI' } },
  // a pin inside a zone should resolve to that zone, same as naming it
  { from: { lat: 5.6689, lng: -0.1651 }, to: { lat: 5.5717, lng: -0.2115 } },
];

// ── the TypeScript side ─────────────────────────────────────────────────────

const byCode = new Map(zones.map((z) => [z.code, z]));

const tables = {
  zonePairs: new Map(Object.entries(zonePairs)),
  bandPairs: Object.entries(bandPrices).map(([key, v]) => {
    const [fromBand, toBand] = key.split('|');
    return { fromBand, toBand, ...v };
  }),
  distanceBands,
};

function resolve(point) {
  if (point.zoneCode && byCode.has(point.zoneCode)) return byCode.get(point.zoneCode);
  if (typeof point.lat === 'number') return resolveZone({ lat: point.lat, lng: point.lng }, zones);
  return null;
}

const jsResults = routes.map((r) => {
  const from = resolve(r.from);
  const to = resolve(r.to);

  const distanceMetres =
    typeof r.from.lat === 'number' && typeof r.to.lat === 'number'
      ? haversineMetres({ lat: r.from.lat, lng: r.from.lng }, { lat: r.to.lat, lng: r.to.lng })
      : from && to
        ? haversineMetres({ lat: from.lat, lng: from.lng }, { lat: to.lat, lng: to.lng })
        : null;

  return priceRoute(
    {
      fromZoneCode: from?.code ?? null,
      toZoneCode: to?.code ?? null,
      fromBand: from?.band || null,
      toBand: to?.band || null,
      distanceMetres,
    },
    tables,
  );
});

// ── the PHP side ────────────────────────────────────────────────────────────

const php = spawnSync('php', [join(here, 'price-parity-php.php')], {
  input: JSON.stringify({ zones, zonePairs, bandPrices, distanceBands, routes }),
  encoding: 'utf8',
});

if (php.status !== 0) {
  console.error('The PHP side failed to run:\n' + (php.stderr || php.stdout));
  process.exit(1);
}

let phpResults;
try {
  phpResults = JSON.parse(php.stdout);
} catch {
  console.error('The PHP side did not return JSON:\n' + php.stdout.slice(0, 600));
  process.exit(1);
}

// ── compare ─────────────────────────────────────────────────────────────────

let failed = 0;
const label = (r) => `${r.from.zoneCode ?? `${r.from.lat},${r.from.lng}`} → ${r.to.zoneCode ?? `${r.to.lat},${r.to.lng}`}`;

routes.forEach((route, i) => {
  const js = jsResults[i];
  const ph = phpResults[i];
  const name = label(route);

  try {
    if (js === null || ph === null) {
      assert.equal(js, null, `${name}: TypeScript priced it but PHP did not`);
      assert.equal(ph, null, `${name}: PHP priced it but TypeScript did not`);
      console.log(`  ok   ${name} — both say not served`);
      return;
    }
    assert.equal(ph.riderFeeMinor, js.riderFeeMinor, `${name}: rider fee differs`);
    assert.equal(ph.buyerPriceMinor, js.buyerPriceMinor, `${name}: buyer price differs`);
    assert.equal(ph.rung, js.rung, `${name}: a different rung answered`);
    assert.equal(ph.matched, js.matched, `${name}: matched a different thing`);
    console.log(`  ok   ${name} — ${js.rung} (${js.matched}), rider ${js.riderFeeMinor / 100}, buyer ${js.buyerPriceMinor / 100}`);
  } catch (error) {
    failed++;
    console.error(`  FAIL ${name}\n       ${error.message}`);
    console.error(`       php: ${JSON.stringify(ph)}\n       ts : ${JSON.stringify(js)}`);
  }
});

console.log(`\n${routes.length - failed}/${routes.length} routes agree.`);
process.exit(failed ? 1 : 0);
