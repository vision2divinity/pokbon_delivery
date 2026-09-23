#!/usr/bin/env node
// The delivery fee, divided between vendors, must still add up to itself.
//
// An order with three vendors makes three jobs, and each one records what that
// leg earned. Those figures are what the reconciliation screen sums, so if the
// parts do not equal the whole, the books are wrong in a way nobody can see by
// looking at any single delivery.
//
// This is not hypothetical. buyerPrice was the ORDER's entire delivery fee
// written to every job: order #87712 collected GH¢4, recorded GH¢12 across
// three jobs, and reported a GH¢9 margin on a delivery that made GH¢1. It was
// the same overstated-revenue bug as #87619, surviving its own fix because the
// fix only ever considered one job.
//
// So: every case asserts that the shares sum to exactly what was collected,
// and that proportion is respected where there is a proportion to respect.
//
// Run: node scripts/check-fee-split.mjs
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

// Amounts are minor units — pesewas — because that is what the splitter works
// in. Floats would make this test agree with a bug it is meant to catch.
const cases = [
  { why: 'one vendor takes the whole fee', total: 250, weights: { 7: 200 } },
  { why: 'equal legs, evenly divisible', total: 600, weights: { 7: 200, 9: 200, 11: 200 } },
  { why: 'equal legs that do NOT divide evenly', total: 400, weights: { 122: 200, 27: 200, 1: 200 } },
  { why: 'unequal legs split in proportion', total: 500, weights: { 7: 200, 9: 300 } },
  { why: 'a long leg and a short one', total: 1800, weights: { 7: 200, 9: 1600 } },
  { why: 'rounding: an awkward total across three', total: 100, weights: { 7: 300, 9: 300, 11: 300 } },
  { why: 'free shipping conserves zero', total: 0, weights: { 7: 200, 9: 200 } },
  { why: 'nothing priced falls back to an even split', total: 450, weights: { 7: 0, 9: 0 } },
  { why: 'a discount below the matrix total still conserves', total: 137, weights: { 7: 500, 9: 900 } },
  { why: 'no legs at all returns nothing', total: 400, weights: {} },
];

const run = spawnSync('php', [join(here, 'fee-split-php.php')], {
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

cases.forEach((test, i) => {
  const shares = Object.values(got[i] ?? {}).map(Number);
  const legs = Object.keys(test.weights).length;

  check(`${test.why} — the parts add up to the whole`, () => {
    // An empty basket has nothing to divide, so nothing is the right answer.
    const expected = legs === 0 ? 0 : test.total;
    assert.equal(
      shares.reduce((a, b) => a + b, 0),
      expected,
      `shares ${JSON.stringify(got[i])} do not sum to ${expected}`,
    );
  });

  check(`${test.why} — one share per leg, none negative`, () => {
    assert.equal(shares.length, legs);
    assert.ok(shares.every((n) => n >= 0), `a negative share is never a real answer: ${JSON.stringify(got[i])}`);
  });
});

// Proportion, where there is one to keep. A short leg and a long leg on the
// same order must not each claim half: that is how a Kumasi run comes to look
// as profitable as a ride across Circle.
check('a leg worth eight times another is paid roughly eight times as much', () => {
  const shares = got[4];
  const [small, large] = [Number(shares[7]), Number(shares[9])];
  assert.ok(large > small * 7 && large < small * 9, `expected ~8x, got ${large} vs ${small}`);
});

if (failures > 0) {
  console.error(`\n${failures} failure(s). A split that loses money loses it quietly, one order at a time.`);
  process.exit(1);
}
console.log('\nFee split OK: every pesewa collected is accounted for against a leg.');
