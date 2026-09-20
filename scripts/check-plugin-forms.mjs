// Catch nested <form> elements in the admin pages.
//
// WHY THIS EXISTS. HTML has no nested forms. A browser silently merges an
// inner form into the outer one, so both hidden `pokbon_action` fields arrive
// in the same submission and PHP takes the last — meaning a button runs
// somebody else's action. That is not a visible error: "Save connection"
// quietly ran the connection test and saved nothing on the first real
// install, and the page looked fine throughout.
//
// `Pokbon_Delivery_Admin::button()` renders a whole form, so calling it
// between `form_open()` and `</form>` is the trap. This walks each admin page
// and fails on it.
//
// Run: node scripts/check-plugin-forms.mjs
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';

const PAGES = 'plugin/pokbon-delivery/admin/pages';

const problems = [];

for (const file of (await readdir(PAGES)).filter((f) => f.endsWith('.php'))) {
  const src = await readFile(join(PAGES, file), 'utf8');
  const lines = src.split('\n');

  let openLine = 0; // 0 = not inside a form

  lines.forEach((line, i) => {
    const n = i + 1;

    // Comments describe the trap; they are not the trap.
    const code = line.replace(/\/\/.*$/, '').replace(/^\s*\*.*$/, '');

    if (/Pokbon_Delivery_Admin::form_open\s*\(/.test(code)) {
      if (openLine) {
        problems.push(`${file}:${n} form_open() while the form opened at line ${openLine} is still open`);
      }
      openLine = n;
      return;
    }

    if (/<\/form>/.test(code)) {
      openLine = 0;
      return;
    }

    if (openLine && /Pokbon_Delivery_Admin::button\s*\(/.test(code)) {
      problems.push(
        `${file}:${n} button() inside the form opened at line ${openLine} — ` +
          `button() renders its own <form>, so the browser will merge them and the wrong action will run`,
      );
    }
  });

  if (openLine) {
    problems.push(`${file}: the form opened at line ${openLine} is never closed`);
  }
}

if (problems.length) {
  console.error('Nested or unclosed admin forms:\n');
  for (const p of problems) console.error('  ' + p);
  console.error(`\n${problems.length} problem(s).`);
  process.exit(1);
}

console.log('Admin forms OK: none nested, none left open.');
