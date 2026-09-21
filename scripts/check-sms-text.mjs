#!/usr/bin/env node
/**
 * The two SMS rules a customer actually feels.
 *
 * Both of these reached a real phone on 2026-09-21: a delivery code that had
 * been retried for an hour arriving next to a newer one, and "Have GH?150.00
 * ready on MoMo" at somebody's front door. Neither is visible from inside the
 * code — you only find out when a person reads the message — so they are
 * pinned here.
 */
import { toGsmSafe } from '../apps/api/dist/outbox/outbox.service.js';

let failures = 0;

function check(name, actual, expected) {
  const ok = actual === expected;
  if (!ok) {
    failures += 1;
    console.error(`FAIL ${name}`);
    console.error(`     expected: ${JSON.stringify(expected)}`);
    console.error(`     actual:   ${JSON.stringify(actual)}`);
  } else {
    console.log(`ok   ${name}`);
  }
}

// ── the cedi sign, which is what started this ────────────────────────────
check(
  'the cedi sign becomes GHS',
  toGsmSafe('Have GH₵150.00 ready on MoMo').text,
  'Have GHS 150.00 ready on MoMo',
);

check(
  'a bare cedi sign still becomes GHS',
  toGsmSafe('You earn ₵40.00').text,
  'You earn GHS 40.00',
);

// ── the other characters that quietly become question marks ─────────────
check('an em dash becomes a hyphen', toGsmSafe('ready - no cash').text, 'ready - no cash');
check('curly quotes straighten', toGsmSafe('the “blue” kiosk').text, 'the "blue" kiosk');
check('an ellipsis expands', toGsmSafe('on the way…').text, 'on the way...');

// ── a real message, end to end ──────────────────────────────────────────
check(
  'a whole delivery-code message survives intact',
  toGsmSafe(
    'POKBON: your rider is at your door. Your delivery code is 543024. Read it to the rider only. Have GH₵150.00 ready on MoMo: you will get a prompt to approve, no cash.',
  ).text,
  'POKBON: your rider is at your door. Your delivery code is 543024. Read it to the rider only. Have GHS 150.00 ready on MoMo: you will get a prompt to approve, no cash.',
);

// ── nothing a phone cannot carry may survive ────────────────────────────
{
  const { text, dropped } = toGsmSafe('Kwame ☺ Ø 中文 ₵5');
  if (/[☺中文]/.test(text)) {
    failures += 1;
    console.error('FAIL characters outside the GSM alphabet were kept');
  } else if (dropped.length === 0) {
    failures += 1;
    console.error('FAIL dropped characters were not reported');
  } else {
    console.log('ok   characters an SMS cannot carry are removed and reported');
  }
  // Ø is IN the GSM alphabet and must not be collateral damage.
  if (!text.includes('Ø')) {
    failures += 1;
    console.error('FAIL Ø is valid GSM and should have survived');
  } else {
    console.log('ok   valid GSM accents survive');
  }
}

if (failures > 0) {
  console.error(`\n${failures} problem(s).`);
  process.exit(1);
}
console.log('\nSMS text OK.');
