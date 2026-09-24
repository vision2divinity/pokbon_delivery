#!/usr/bin/env node
// The failed-trip uplift must be payable once per trip, and not at all for a
// trip that was finished.
//
// The uplift compensates a rider for a journey that went nowhere. Two ways it
// stopped doing that:
//
//   1. FAILED -> EN_ROUTE is a legal transition, and the credit was
//      unconditional. So failing, resuming and failing again paid it every
//      lap, unbounded, on one job, for two authenticated calls per iteration.
//
//   2. The honest version of that path — the customer rings back and the
//      rider finishes — paid the full delivery fee AND the compensation for
//      failing it. One journey, paid twice.
//
// Both were invisible: pendingUpliftMinor is a running total with no
// provenance, so nothing could answer "has this trip already been compensated".
// It is answered now by a field on the JOB, and this asserts the arithmetic on
// the sequences that actually occur.
//
// Pure arithmetic against the shared markup helper, so it runs without a
// database and cannot drift from the money rules it is checking.
//
// Run: node scripts/check-uplift.mjs
import assert from 'node:assert/strict';
import { applyMarkup } from '../packages/shared/dist/index.js';

const UPLIFT_BPS = 2000; // 20%, the shipped default.
const FEE = 4000; // GH₵40 rider fee.
const CREDIT = applyMarkup(FEE, UPLIFT_BPS) - FEE;

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

/**
 * The rules, restated.
 *
 * Be clear about what this is: a specification, not a harness around the live
 * service — that needs a database and a rider session, which this cannot have.
 * It mirrors failed(), delivered() and recallForOrderCancellation() in
 * jobs.service.ts, and the service is the source of truth. A change there
 * belongs here too, and this file failing means one of them moved without the
 * other.
 *
 * It still earns its place: the arithmetic below is the whole argument for why
 * the loop is closed, and before this it existed in nobody's head at all.
 *
 * `job.generated` is upliftGeneratedMinor — what this trip has already been
 * compensated. `pot` is the rider's pendingUpliftMinor.
 */
function fail(state) {
  const credit = state.job.generated > 0 ? 0 : CREDIT;
  return {
    pot: state.pot + credit,
    job: { ...state.job, generated: state.job.generated || credit },
    paidNow: 0,
  };
}

function deliver(state) {
  // A trip that was completed is not a failed trip: its own credit comes back
  // out before the pot is paid.
  const uplift = Math.max(0, state.pot - state.job.generated);
  return { pot: 0, job: { ...state.job, generated: 0 }, paidNow: FEE + uplift };
}

const fresh = () => ({ pot: 0, job: { generated: 0 }, paidNow: 0 });

check('a failed trip is compensated once', () => {
  const after = fail(fresh());
  assert.equal(after.pot, CREDIT);
});

check('failing the same trip again pays nothing more', () => {
  let s = fail(fresh());
  for (let i = 0; i < 20; i += 1) s = fail(s);
  assert.equal(s.pot, CREDIT, 'twenty laps of FAILED -> EN_ROUTE -> FAILED must still be one credit');
});

check('a trip that fails and is then finished pays the fee only', () => {
  const delivered = deliver(fail(fresh()));
  assert.equal(delivered.paidNow, FEE, 'no compensation for a journey that was completed');
});

check('uplift from a DIFFERENT failed trip still pays out on the next delivery', () => {
  // Job A failed and was never resumed; job B is a separate delivery.
  const afterA = fail(fresh());
  const onB = deliver({ pot: afterA.pot, job: { generated: 0 }, paidNow: 0 });
  assert.equal(onB.paidNow, FEE + CREDIT, 'the whole point of the uplift');
});

check('two different failed trips both pay out', () => {
  let pot = fail(fresh()).pot;
  pot = fail({ pot, job: { generated: 0 } }).pot;
  const onC = deliver({ pot, job: { generated: 0 }, paidNow: 0 });
  assert.equal(onC.paidNow, FEE + CREDIT * 2);
});

check('the pot never goes negative', () => {
  // A job carrying a credit larger than the pot — possible only through a
  // correction, but the arithmetic must not invent money either way.
  const out = deliver({ pot: 0, job: { generated: CREDIT }, paidNow: 0 });
  assert.equal(out.paidNow, FEE);
});

if (failures > 0) {
  console.error(`\n${failures} failure(s). An uplift that can be printed in a loop is money leaving on a timer.`);
  process.exit(1);
}
console.log('\nUplift OK: once per wasted trip, nothing for a trip that was finished.');
