#!/usr/bin/env node
/**
 * The plugin and the delivery service must fill a message template the same way.
 *
 * The third pair of duplicated rules in this project, after the price ladder
 * and the GSM folder, and the same containment: run both over the same inputs
 * and fail on any disagreement.
 *
 * These are customer-facing strings. A difference here does not crash
 * anything — it puts a different sentence on somebody's phone depending on
 * which side of the system happened to send it, which is worse, because
 * nothing reports it.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { renderMessage } from '../packages/shared/dist/settings.js';

const harness = fileURLToPath(new URL('./message-templates-php.php', import.meta.url));

const messages = {
  rider_otp: { enabled: true, text: 'Your POKBON Delivery code is {code}. It expires in {minutes} minutes. Never share it.' },
  assigned: { enabled: true, text: 'POKBON: {rider} is on the way with your delivery.{amount}' },
  delivery_code: { enabled: true, text: 'POKBON: your rider is at your door. Your delivery code is {code}. Read it to the rider only.{amount}' },
  payment_prompt: { enabled: false, text: 'POKBON: approve GHS {amount} for order #{order}.' },
  pay_by_link: { enabled: true, text: '' },
};

const cases = [
  { key: 'rider_otp', vars: { code: '481920', minutes: 5 }, why: 'plain substitution' },
  { key: 'assigned', vars: { rider: 'Kwame Mensah', amount: ' Have GHS 31.00 ready.' }, why: 'two variables, one with leading space' },
  { key: 'assigned', vars: { rider: 'Kwame Mensah' }, why: 'an unsupplied placeholder is dropped, not shown' },
  { key: 'delivery_code', vars: { code: '543024', amount: '' }, why: 'empty variable leaves no double space' },
  { key: 'payment_prompt', vars: { amount: '31.00', order: 87619 }, why: 'switched off returns nothing' },
  { key: 'pay_by_link', vars: { amount: '31.00', order: 87619, link: 'https://x.test/a' }, why: 'empty text falls back to the shipped default' },
  { key: 'not_a_message', vars: {}, why: 'an unknown key does not explode' },
];

// The API passes the shipped default as its fallback; the plugin reads the
// same default from its own defaults(). Both must land in the same place.
const FALLBACKS = {
  rider_otp: 'Your POKBON Delivery code is {code}. It expires in {minutes} minutes. Never share it.',
  assigned: 'POKBON: {rider} is on the way with your delivery.{amount}',
  delivery_code: 'POKBON: your rider is at your door. Your delivery code is {code}. Read it to the rider only.{amount}',
  payment_prompt: 'POKBON: approve GHS {amount} for order #{order}.',
  pay_by_link: 'POKBON: pay GHS {amount} for order #{order} here: {link}',
  not_a_message: '',
};

let php;
try {
  php = JSON.parse(
    execFileSync('php', [harness], { input: JSON.stringify({ messages, cases }), encoding: 'utf8' }),
  );
} catch (error) {
  console.error(`FAIL could not run the PHP half: ${error.message}`);
  process.exit(1);
}

let failures = 0;

cases.forEach((test, i) => {
  const ts = renderMessage(messages, test.key, FALLBACKS[test.key] ?? '', test.vars);
  if (ts === php[i]) {
    console.log(`ok   ${test.why.padEnd(48)} ${JSON.stringify(ts).slice(0, 46)}`);
  } else {
    failures += 1;
    console.error(`FAIL ${test.why}`);
    console.error(`     typescript: ${JSON.stringify(ts)}`);
    console.error(`     php:        ${JSON.stringify(php[i])}`);
  }
});

if (failures > 0) {
  console.error(`\n${failures} disagreement(s). The same message must read the same whichever side sends it.`);
  process.exit(1);
}
console.log('\nMessage templates OK: both implementations agree.');
