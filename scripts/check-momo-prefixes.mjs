#!/usr/bin/env node
/**
 * The mobile-money prefix table exists twice, and must agree.
 *
 * PHP cannot call TypeScript, so the plugin and the shared package each carry
 * their own copy. On 2026-09-21 they agreed with each other and both were
 * wrong: 053 is MTN and neither knew it, so a pay-on-delivery prompt to an
 * 053 number was refused as an unknown network while a rider waited at the
 * door. Nothing crashed. Nothing was logged as broken.
 *
 * So this checks two separate things:
 *   1. the two copies match each other, and
 *   2. both match the allocations below, which are the thing that is actually
 *      true and the only defence against them being consistently wrong.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));

/**
 * Ghana mobile-money networks, confirmed by the owner on 2026-09-21.
 * Update here first, then both implementations.
 */
const TRUTH = {
  mtn: ['024', '025', '053', '054', '055', '059'],
  atl: ['026', '027', '056', '057'],
  vod: ['020', '050'],
};

const NETWORK_NAMES = { mtn: 'MTN', atl: 'AirtelTigo', vod: 'Telecel' };

function fromTypeScript() {
  const src = readFileSync(root + 'packages/shared/src/phone.ts', 'utf8');
  const out = {};
  for (const network of Object.keys(TRUTH)) {
    const m = new RegExp(`${network}:\\s*\\[([^\\]]*)\\]`).exec(src);
    if (!m) throw new Error(`no ${network} row in phone.ts`);
    out[network] = m[1]
      .split(',')
      .map((s) => s.trim().replace(/['"]/g, ''))
      .filter(Boolean)
      .map((s) => '0' + s)
      .sort();
  }
  return out;
}

function fromPhp() {
  const src = readFileSync(root + 'plugin/pokbon-delivery/includes/class-messages.php', 'utf8');
  const out = {};
  for (const network of Object.keys(TRUTH)) {
    const m = new RegExp(`\\[([^\\]]*)\\],\\s*true\\s*\\)\\s*\\)\\s*\\{\\s*return '${network}'`).exec(src);
    if (!m) throw new Error(`no ${network} row in class-messages.php`);
    out[network] = m[1]
      .split(',')
      .map((s) => s.trim().replace(/['"]/g, ''))
      .filter(Boolean)
      .map((s) => '0' + s)
      .sort();
  }
  return out;
}

const ts = fromTypeScript();
const php = fromPhp();
let failures = 0;

for (const [network, want] of Object.entries(TRUTH)) {
  const expected = [...want].sort();
  const name = NETWORK_NAMES[network];

  if (ts[network].join() !== expected.join()) {
    failures += 1;
    console.error(`${name}: phone.ts has ${ts[network].join(', ')} — should be ${expected.join(', ')}`);
  }
  if (php[network].join() !== expected.join()) {
    failures += 1;
    console.error(`${name}: class-messages.php has ${php[network].join(', ')} — should be ${expected.join(', ')}`);
  }
  if (failures === 0) console.log(`ok   ${name.padEnd(10)} ${expected.join(' ')}`);
}

// No prefix may belong to two networks: a prompt would go to the wrong one.
const seen = new Map();
for (const [network, prefixes] of Object.entries(TRUTH)) {
  for (const prefix of prefixes) {
    if (seen.has(prefix)) {
      failures += 1;
      console.error(`${prefix} is claimed by both ${seen.get(prefix)} and ${network}`);
    }
    seen.set(prefix, network);
  }
}

if (failures > 0) {
  console.error(`\n${failures} problem(s). A wrong prefix here fails at somebody's gate, not in a log.`);
  process.exit(1);
}
console.log('\nMobile-money prefixes OK: both copies agree, and agree with the allocations.');
