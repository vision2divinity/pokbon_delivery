#!/usr/bin/env node
// Start (or restart) the offer cascade on one job, from the command line.
//
// A job that finds no eligible rider at the moment it is created is never
// revisited: the scheduler's sweep expires offers that already exist and
// cascades from them, but nothing ever makes a FIRST offer to a job that had
// none. So a job created while the only rider was stale sits at CREATED
// forever, and no screen explains why.
//
// This is the manual way out, and a way to see the real reason rather than
// guess at it. It signs the same way the plugin does and calls the same route
// the plugin's re-dispatch button calls.
//
// Run: node scripts/offer-now.mjs <external_ref|job_id>
import { createHmac, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

const env = Object.fromEntries(
  readFileSync(join(root, '.env'), 'utf8')
    .split(/\r?\n/)
    .filter((l) => l && !l.startsWith('#') && l.includes('='))
    .map((l) => {
      const i = l.indexOf('=');
      return [l.slice(0, i).trim(), l.slice(i + 1).trim()];
    }),
);

const secret = env.PLUGIN_SHARED_SECRET;
const base = `http://localhost:${env.PORT || 3001}`;
if (!secret) {
  console.error('PLUGIN_SHARED_SECRET is not in .env');
  process.exit(1);
}

const ref = process.argv[2];
if (!ref) {
  console.error('usage: node scripts/offer-now.mjs <external_ref|job_id>');
  process.exit(1);
}

// An order number is friendlier to type than a UUID, so accept either.
let jobId = ref;
if (!/^[0-9a-f-]{36}$/i.test(ref)) {
  jobId = execFileSync(
    'docker',
    ['exec', 'pokbon-delivery-postgres', 'psql', '-U', 'pokbon', '-d', 'pokbon_delivery', '-tAc',
      `select id from jobs where external_ref='${ref.replace(/'/g, "''")}' order by created_at desc limit 1;`],
    { encoding: 'utf8' },
  ).trim();
  if (!jobId) {
    console.error(`No job with external_ref ${ref}`);
    process.exit(1);
  }
}

const body = '{}';
const res = await fetch(`${base}/jobs/${jobId}/offer`, {
  method: 'POST',
  headers: {
    'x-pokbon-delivery-signature': createHmac('sha256', secret).update(body).digest('hex'),
    'x-pokbon-delivery-timestamp': String(Math.floor(Date.now() / 1000)),
    'x-pokbon-delivery-event-id': randomUUID(),
    'content-type': 'application/json',
  },
  body,
});

const text = await res.text();
console.log(`HTTP ${res.status}`);
try {
  const data = JSON.parse(text);
  console.log(JSON.stringify(data, null, 2));
  if (data.offered === false) {
    console.log('\nNot offered. Nobody was eligible — almost always a rider whose');
    console.log('last position is older than location_stale_seconds (300s).');
  }
} catch {
  console.log(text);
}
