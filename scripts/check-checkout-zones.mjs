#!/usr/bin/env node
// The price a buyer is quoted must not depend on which app they opened.
//
// Delivery is priced zone to zone. The website asks for that price mid-session,
// where WC()->cart exists; the mobile app asks over REST, where it does not.
// Same question, two very different callers — and the failure mode when the
// second one breaks is invisible: an empty area list is indistinguishable from
// a region nobody has zoned, so the checkout falls back to the flat regional
// rate and looks entirely normal while losing money on every order.
//
// That is not hypothetical. It is what happened on 2026-09-22, when zone
// regions were free text and WooCommerce state codes were not, every zone was
// filtered out, and the only symptom was a delivery fee that looked plausible.
//
// So: both callers, same zones, same matrix, asserted answers.
//
// Run: node scripts/check-checkout-zones.mjs
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

// Two collection points, and four places to deliver to.
const zones = [
  { code: 'SHOP', name: 'POKBON Shop', lat: 5.5717, lng: -0.2115, radiusMetres: 5000, band: 'INNER', region: 'Greater Accra', active: true },
  { code: 'SHOP2', name: 'Second Shop', lat: 5.6076, lng: -0.2436, radiusMetres: 5000, band: 'INNER', region: 'Greater Accra', active: true },
  // Region written as WooCommerce writes it.
  { code: 'JAMESTOWN', name: 'James Town', lat: 5.5320, lng: -0.2120, radiusMetres: 4000, band: 'INNER', region: 'AA', active: true },
  // Region written as a human wrote it. Both must match the same buyer.
  { code: 'MADINA', name: 'Madina', lat: 5.6689, lng: -0.1651, radiusMetres: 5000, band: 'OUTER', region: 'Greater Accra', active: true },
  // No region at all: belongs everywhere, because hiding a zone somebody
  // priced is worse than showing it in one region too many.
  { code: 'ANYWHERE', name: 'Anywhere', lat: 5.6000, lng: -0.2000, radiusMetres: 5000, band: 'INNER', region: '', active: true },
  // Another region entirely. Must never appear under Greater Accra.
  { code: 'KUMASI', name: 'Kumasi', lat: 6.6885, lng: -1.6244, radiusMetres: 8000, band: 'KUMASI', region: 'Ashanti', active: true },
  // Priced, in region, and switched off. Must not be offered.
  { code: 'CLOSED', name: 'Closed Area', lat: 5.5500, lng: -0.2200, radiusMetres: 4000, band: 'INNER', region: 'AA', active: false },
];

const zonePairs = {};
const bandPrices = {
  'INNER|INNER': { riderFeeMinor: 2000, buyerPriceMinor: 2500 },
  'INNER|OUTER': { riderFeeMinor: 3500, buyerPriceMinor: 4500 },
  'INNER|KUMASI': { riderFeeMinor: 12000, buyerPriceMinor: 16000 },
};
const distanceBands = [{ maxKm: 2000, riderFeeMinor: 15000, buyerPriceMinor: 20000 }];

// Product 101 and 103 belong to vendor 7, product 102 to vendor 9.
// Vendor 0 is the store's own default collection point, which is what the real
// pickup_zone_for_vendor() falls back to when a vendor has set no address.
// Vendor 11 shares SHOP with vendor 7 — two businesses, one building.
const productVendors = { 101: 7, 102: 9, 103: 7, 104: 11 };
const vendorPickups = { 0: 'SHOP', 7: 'SHOP', 9: 'SHOP2', 11: 'SHOP' };

const cases = [
  // 1. The website's two-argument call, on a request with no cart.
  { ask: 'areas_two_args', region: 'AA' },
  // 2. The app's call, one basket, one collection point.
  { ask: 'areas_three_args', region: 'AA', productIds: [101] },
  // 3. Two products from the same vendor is still one collection.
  { ask: 'areas_three_args', region: 'AA', productIds: [101, 103] },
  // 4. Two vendors in two places is two collections.
  { ask: 'areas_three_args', region: 'AA', productIds: [101, 102] },
  // 5. A different region.
  { ask: 'areas_three_args', region: 'AH', productIds: [101] },
  // 6. A region with nothing zoned in it.
  { ask: 'areas_three_args', region: 'CP', productIds: [101] },
  // 7. The price of one chosen area, which must equal its row above.
  { ask: 'price', zone: 'JAMESTOWN', productIds: [101] },
  // 8. Lowercase in, same answer out.
  { ask: 'price', zone: 'jamestown', productIds: [101] },
  // 9. A zone nobody has heard of.
  { ask: 'price', zone: 'NOWHERE', productIds: [101] },
  // 10. A deactivated zone.
  { ask: 'price', zone: 'CLOSED', productIds: [101] },
  // 11. No basket: the default collection point answers, so the app can show
  //     a browsable list before anyone has a cart.
  { ask: 'price', zone: 'JAMESTOWN', productIds: [] },
  // 12. Two vendors sharing ONE address. Still two legs.
  { ask: 'price', zone: 'JAMESTOWN', productIds: [101, 104] },
];

const run = spawnSync('php', [join(here, 'checkout-zones-php.php')], {
  input: JSON.stringify({ zones, zonePairs, bandPrices, distanceBands, productVendors, vendorPickups, cases }),
  encoding: 'utf8',
});

if (run.status !== 0) {
  console.error(`FAIL could not run the PHP half:\n${run.stderr || run.stdout}`);
  process.exit(1);
}

let got;
try {
  got = JSON.parse(run.stdout);
} catch {
  console.error(`FAIL the PHP half did not return JSON:\n${run.stdout}\n${run.stderr}`);
  process.exit(1);
}

const names = (rows) => rows.map((r) => r.name);
const priced = (rows) => Object.fromEntries(rows.map((r) => [r.code, r.amount]));

let failures = 0;
const check = (why, fn) => {
  try {
    fn();
    console.log(`ok   ${why}`);
  } catch (error) {
    failures += 1;
    console.error(`FAIL ${why}\n     ${error.message.split('\n')[0]}`);
  }
};

check('no cart and no basket: nothing is offered, rather than something guessed', () => {
  assert.deepEqual(got[0], []);
});

check('one basket, one collection point: the region\'s areas, cheapest first', () => {
  // Ties broken by name, so the list is stable between requests — a picker
  // that reshuffles itself makes a buyer doubt the price on it.
  assert.deepEqual(names(got[1]), ['Anywhere', 'James Town', 'POKBON Shop', 'Second Shop', 'Madina']);
  assert.deepEqual(priced(got[1]), {
    ANYWHERE: 25, JAMESTOWN: 25, SHOP: 25, SHOP2: 25, MADINA: 45,
  });
});

check('a zone with no region belongs to every region', () => {
  assert.ok(names(got[1]).includes('Anywhere'));
});

check('a deactivated zone is not offered at any price', () => {
  assert.ok(!names(got[1]).includes('Closed Area'));
});

check('a zone in another region never appears', () => {
  assert.ok(!names(got[1]).includes('Kumasi'));
});

check('two products from one vendor is one collection, so one fee', () => {
  assert.deepEqual(got[2], got[1]);
});

check('two vendors in two places is two collections, so twice the fee', () => {
  assert.deepEqual(priced(got[3]), {
    ANYWHERE: 50, JAMESTOWN: 50, SHOP: 50, SHOP2: 50, MADINA: 90,
  });
});

check('the region really does filter: Ashanti shows Kumasi, not James Town', () => {
  assert.deepEqual(names(got[4]).sort(), ['Anywhere', 'Kumasi']);
});

check('a region nobody has zoned offers only the zone that belongs everywhere', () => {
  assert.deepEqual(names(got[5]), ['Anywhere']);
});

check('the price of a chosen area is the price its row advertised', () => {
  assert.equal(got[6], priced(got[1]).JAMESTOWN);
});

check('a zone code is not case sensitive', () => {
  assert.equal(got[7], got[6]);
});

check('an unknown zone leaves the caller\'s own price alone', () => {
  assert.equal(got[8], null);
});

check('a deactivated zone leaves the caller\'s own price alone', () => {
  assert.equal(got[9], null);
});

check('no basket still prices, from the default collection point', () => {
  assert.equal(got[10], 25);
});

check('two vendors at the SAME address are two legs, not one', () => {
  // The rule Francis set on 2026-09-21 and reaffirmed on 2026-09-23: two
  // vendors is two collections however close they are. This used to key by
  // pickup zone and charge once, while dispatch created a job per vendor and
  // paid a rider fee for each — so a shared building silently ran at zero
  // margin. Order #87712 charged for two collection points and made three
  // jobs before anybody noticed.
  assert.equal(got[11], got[6] * 2);
});

if (failures > 0) {
  console.error(`\n${failures} failure(s). A wrong delivery price is not visible to anybody until the money is counted.`);
  process.exit(1);
}
console.log('\nCheckout zones OK: the website and the app are quoted the same price.');
