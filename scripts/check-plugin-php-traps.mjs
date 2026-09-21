#!/usr/bin/env node
/**
 * Static checks for PHP idioms that are silently wrong in this plugin.
 *
 * These are not style rules. Each one shipped a real bug to a live site and
 * cost time to find, and each is invisible at the call site — the code reads
 * as though it does the obvious thing.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join, relative } from 'node:path';

// fileURLToPath, not URL.pathname: this repo lives under a directory with a
// space in its name and pathname hands back %20.
const ROOT = fileURLToPath(new URL('../plugin/pokbon-delivery/', import.meta.url));

const TRAPS = [
  {
    name: 'casting meta straight to an array',
    // (array) $order->get_meta(...)  /  (array) get_post_meta(...)
    pattern: /\(array\)\s*(\$\w+->get_meta\s*\(|get_post_meta\s*\(|get_option\s*\()/g,
    why:
      "an absent meta value is '', and (array) '' is [''] — one element, not none.\n" +
      '      `empty()` then says false and `count()` says 1, so every order looks dispatched.\n' +
      '      Use Pokbon_Delivery_Orders::job_ids_for(), or filter the array yourself.',
  },
  {
    name: 'array_is_list()',
    pattern: /\barray_is_list\s*\(/g,
    why: 'PHP 8.1 only; this plugin declares 7.4 and would fatal on activation.',
  },
  {
    name: 'str_contains()',
    pattern: /\bstr_contains\s*\(/g,
    why: 'PHP 8.0 only; this plugin declares 7.4. Use strpos() !== false.',
  },
  {
    name: 'number_format() on a distance or price label',
    pattern: /number_format\s*\([^)]*\b(km|metre|distance)\b/gi,
    why:
      'the thousands separator makes the PHP label diverge from the TypeScript one\n' +
      '      ("up to 2,000km" vs "up to 2000km"), and the parity check compares labels.',
  },
];

function* phpFiles(dir) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) yield* phpFiles(full);
    else if (entry.endsWith('.php')) yield full;
  }
}

let failures = 0;
for (const file of phpFiles(ROOT)) {
  const source = readFileSync(file, 'utf8');
  const lines = source.split(/\r?\n/);
  for (const trap of TRAPS) {
    trap.pattern.lastIndex = 0;
    let match;
    while ((match = trap.pattern.exec(source)) !== null) {
      const line = source.slice(0, match.index).split(/\r?\n/).length;
      // The checker describes the traps it hunts; do not report itself.
      if (lines[line - 1].trim().startsWith('*')) continue;
      failures += 1;
      console.error(`${relative(process.cwd(), file)}:${line}  ${trap.name}`);
      console.error(`      ${lines[line - 1].trim()}`);
      console.error(`      ${trap.why}\n`);
    }
  }
}

if (failures > 0) {
  console.error(`${failures} problem(s) found.`);
  process.exit(1);
}
console.log('Plugin PHP OK: none of the known traps present.');
