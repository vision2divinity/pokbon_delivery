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
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
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

// ── and the plugin's copy must agree, character for character ───────
//
// The plugin sends some messages directly, including text that comes back
// from Paystack, so the rules exist twice. Two copies of anything a customer
// reads is a risk; this is the containment.
{
  const harness = fileURLToPath(new URL('./sms-text-php.php', import.meta.url));
  const cases = [
    'Have GH₵150.00 ready on MoMo',
    'You earn ₵40.00',
    'ready — no cash',
    'the “blue” kiosk',
    'on the way…',
    'POKBON: approve GHS 31.00 for order #87616. Or pay here: https://checkout.paystack.com/abc123',
    'POKBON: your rider is at your door. Your delivery code is 543024. Read it to the rider only.',
    'Kwame ☺ Ø 中文 ₵5',
  ];

  let php;
  try {
    php = JSON.parse(execFileSync('php', [harness], { input: JSON.stringify(cases), encoding: 'utf8' }));
  } catch (error) {
    console.error(`FAIL could not run the PHP half: ${error.message}`);
    process.exit(1);
  }

  cases.forEach((text, i) => {
    const ts = toGsmSafe(text).text;
    if (ts === php[i]) {
      console.log(`ok   both agree: ${php[i].slice(0, 52)}`);
    } else {
      failures += 1;
      console.error(`FAIL the two folders disagree on: ${text}`);
      console.error(`     typescript: ${JSON.stringify(ts)}`);
      console.error(`     php:        ${JSON.stringify(php[i])}`);
    }
  });
}

if (failures > 0) {
  console.error(`\n${failures} problem(s).`);
  process.exit(1);
}
console.log('\nSMS text OK: both implementations agree.');
