// The price ladder, tested without a database or a network.
//
// Pricing is the one piece where a quiet mistake costs money on every job, so
// the rules are a pure function and this exercises them directly.
//
// Run: node scripts/check-price-ladder.mjs
import assert from 'node:assert/strict';
import { priceRoute } from '../packages/shared/dist/index.js';

const tables = {
  zonePairs: new Map([
    // Francis priced this one by hand.
    ['ADENTA|KASOA', { riderFeeMinor: 6000, buyerPriceMinor: 8000 }],
    ['MADINA|CIRCLE', { riderFeeMinor: 3000, buyerPriceMinor: 4000 }],
  ]),
  bandPairs: [
    { fromBand: 'INNER', toBand: 'INNER', riderFeeMinor: 2000, buyerPriceMinor: 2500 },
    { fromBand: 'INNER', toBand: 'OUTER', riderFeeMinor: 3500, buyerPriceMinor: 4500 },
  ],
  distanceBands: [
    { maxKm: 5, riderFeeMinor: 1500, buyerPriceMinor: 2000 },
    { maxKm: 15, riderFeeMinor: 2500, buyerPriceMinor: 3500 },
    { maxKm: 10000, riderFeeMinor: 9000, buyerPriceMinor: 12000 },
  ],
};

const checks = [];
const check = (name, fn) => checks.push([name, fn]);

check('an explicit pair wins over everything below it', () => {
  const r = priceRoute(
    { fromZoneCode: 'ADENTA', toZoneCode: 'KASOA', fromBand: 'INNER', toBand: 'INNER', distanceMetres: 1000 },
    tables,
  );
  assert.equal(r.rung, 'zone-pair');
  assert.equal(r.riderFeeMinor, 6000);
  assert.equal(r.buyerPriceMinor, 8000);
});

check('pairs are directional — the return leg is its own price', () => {
  const there = priceRoute(
    { fromZoneCode: 'ADENTA', toZoneCode: 'KASOA', fromBand: null, toBand: null, distanceMetres: null },
    tables,
  );
  const back = priceRoute(
    { fromZoneCode: 'KASOA', toZoneCode: 'ADENTA', fromBand: null, toBand: null, distanceMetres: null },
    tables,
  );
  assert.equal(there.rung, 'zone-pair');
  assert.equal(back, null, 'the unpriced return leg must not silently borrow the outbound price');
});

check('the band answers when the pair is not priced', () => {
  const r = priceRoute(
    { fromZoneCode: 'LAPAZ', toZoneCode: 'ASHAIMAN', fromBand: 'INNER', toBand: 'OUTER', distanceMetres: 3000 },
    tables,
  );
  assert.equal(r.rung, 'band-pair');
  assert.equal(r.riderFeeMinor, 3500);
});

check('distance catches a zone nobody has priced yet', () => {
  const r = priceRoute(
    { fromZoneCode: 'NEWTOWN', toZoneCode: 'CIRCLE', fromBand: null, toBand: null, distanceMetres: 4200 },
    tables,
  );
  assert.equal(r.rung, 'distance');
  assert.equal(r.matched, 'up to 5km');
  assert.equal(r.riderFeeMinor, 1500);
});

check('distance bands are inclusive at the boundary', () => {
  const exactly5 = priceRoute(
    { fromZoneCode: null, toZoneCode: null, fromBand: null, toBand: null, distanceMetres: 5000 },
    tables,
  );
  const justOver = priceRoute(
    { fromZoneCode: null, toZoneCode: null, fromBand: null, toBand: null, distanceMetres: 5001 },
    tables,
  );
  assert.equal(exactly5.matched, 'up to 5km');
  assert.equal(justOver.matched, 'up to 15km');
});

check('bands are tried in ascending order regardless of how they were stored', () => {
  const shuffled = { ...tables, distanceBands: [...tables.distanceBands].reverse() };
  const r = priceRoute(
    { fromZoneCode: null, toZoneCode: null, fromBand: null, toBand: null, distanceMetres: 2000 },
    shuffled,
  );
  assert.equal(r.matched, 'up to 5km', 'a reversed table must not price a 2km trip as the longest band');
});

check('nothing matched means not served, never a guess', () => {
  const bare = { zonePairs: new Map(), bandPairs: [], distanceBands: [] };
  const r = priceRoute(
    { fromZoneCode: 'A', toZoneCode: 'B', fromBand: 'X', toBand: 'Y', distanceMetres: 3000 },
    bare,
  );
  assert.equal(r, null);
});

check('a half-known route falls through rather than half-pricing', () => {
  // Only one end resolved to a zone: the pair cannot match, and with no
  // distance there is nothing honest to say.
  const r = priceRoute(
    { fromZoneCode: 'MADINA', toZoneCode: null, fromBand: 'INNER', toBand: null, distanceMetres: null },
    tables,
  );
  assert.equal(r, null);
});

let failed = 0;
for (const [name, fn] of checks) {
  try {
    fn();
    console.log('  ok   ' + name);
  } catch (error) {
    failed++;
    console.error('  FAIL ' + name + '\n       ' + error.message);
  }
}

console.log(`\n${checks.length - failed}/${checks.length} passed.`);
process.exit(failed ? 1 : 0);
