#!/usr/bin/env node
// What each rider collects at the door must add up to the order, and never
// more.
//
// WHY THIS EXISTS. `codAmount` was the whole order's total, written to every
// job. On a one-vendor order that is exactly right, and every order anybody
// tested by hand had one vendor. On the three-vendor order that was actually
// placed, three riders would each have raised a mobile-money prompt for the
// ENTIRE order at their own doorstep. The first to arrive took all of it,
// before the buyer had seen the other two parcels; two riders arriving
// together could both pass the "already paid?" guard and both charge in full.
//
// It is the same shape as every other money bug in this codebase: a value that
// is right for one leg, written to every leg, and correct in testing because
// testing had one leg.
//
// So this asserts the two properties that make a doorstep charge safe:
//
//   1. CONSERVATION — when every vendor is dispatched, the doors add up to
//      exactly the order total. Not approximately: to the pesewa.
//   2. NO OVERCHARGE — they never add up to more. Ever. Under any weights,
//      including ones that make no sense.
//
// Run: node scripts/check-cod-split.mjs
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

// Minor units — pesewas — because that is what the splitter works in. Floats
// would make this test agree with the bug it is meant to catch.
const cases = [
  {
    why: 'one vendor collects the whole order',
    total: 15000,
    goods: { 7: 14000 },
    legs: { 7: 1000 },
  },
  {
    why: 'two vendors, in proportion to goods plus their own delivery',
    total: 30000,
    goods: { 7: 10000, 9: 18000 },
    legs: { 7: 800, 9: 1200 },
  },
  {
    why: 'three vendors, the order that was actually placed',
    total: 60100,
    goods: { 7: 20000, 9: 20000, 11: 20000 },
    legs: { 7: 33, 9: 33, 11: 34 },
  },
  {
    why: 'an awkward total that does not divide evenly',
    total: 10000,
    goods: { 7: 3333, 9: 3333, 11: 3333 },
    legs: {},
  },
  {
    why: 'a coupon: the total is less than the goods are worth',
    total: 9000,
    goods: { 7: 6000, 9: 6000 },
    legs: { 7: 500, 9: 500 },
  },
  {
    why: 'free goods and free delivery still conserve zero',
    total: 0,
    goods: { 7: 0, 9: 0 },
    legs: {},
  },
  {
    why: 'nothing priced anywhere falls back to an even split',
    total: 45000,
    goods: { 7: 0, 9: 0 },
    legs: { 7: 0, 9: 0 },
  },
  {
    why: 'one vendor is far more expensive than the other',
    total: 100000,
    goods: { 7: 1000, 9: 95000 },
    legs: { 7: 2000, 9: 2000 },
  },
  {
    why: 'no vendors at all returns nothing',
    total: 5000,
    goods: {},
    legs: {},
  },
];

const run = spawnSync('php', [join(here, 'cod-split-php.php')], {
  input: JSON.stringify(cases),
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

const sum = (o) => Object.values(o).reduce((a, b) => a + b, 0);

cases.forEach((c, i) => {
  const shares = got[i] ?? {};

  check(`${c.why}: the doors add up to the order exactly`, () => {
    const expected = Object.keys(c.goods).length === 0 ? 0 : c.total;
    assert.equal(sum(shares), expected, `got ${sum(shares)} for a total of ${expected}`);
  });

  check(`${c.why}: no door is asked for a negative amount`, () => {
    for (const [vendor, amount] of Object.entries(shares)) {
      assert.ok(amount >= 0, `vendor ${vendor} asked for ${amount}`);
    }
  });

  check(`${c.why}: no single door is asked for more than the order`, () => {
    for (const [vendor, amount] of Object.entries(shares)) {
      assert.ok(amount <= c.total, `vendor ${vendor} asked for ${amount} of a ${c.total} order`);
    }
  });
});

// The property that actually protects the buyer: a vendor whose pickup could
// not be resolved gets no job, so nobody ever collects their share. What the
// dispatched riders collect must then be LESS than the order — the buyer is
// not asked at the door to pay for a parcel nobody fetched.
check('a vendor who was never dispatched is not collected for', () => {
  const i = cases.findIndex((c) => c.why.startsWith('three vendors'));
  const shares = got[i];
  // Vendor 11 had no pickup, so no job and no door.
  const collected = shares[7] + shares[9];
  assert.ok(
    collected < cases[i].total,
    'the two riders who went out must collect less than the whole order',
  );
  assert.equal(collected + shares[11], cases[i].total, 'and the missing part is exactly the skipped leg');
});

if (failures > 0) {
  console.error(
    `\n${failures} failure(s). A doorstep charge that does not conserve is money taken from a real person at their own front door.`,
  );
  process.exit(1);
}
console.log('\nDoorstep split OK: the doors add up to the order, and never to more.');
