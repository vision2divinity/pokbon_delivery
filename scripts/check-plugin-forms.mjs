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

// Every PHP file, not just the admin pages.
//
// The order panel is rendered INSIDE WooCommerce's own order form, so a
// form emitted there is nested too — and it lives in includes/, which an
// admin/pages-only scan never looked at. Anything that renders markup can
// make this mistake, so everything is scanned.
const ROOTS = ['plugin/pokbon-delivery/admin/pages', 'plugin/pokbon-delivery/includes', 'plugin/pokbon-delivery/admin'];

const problems = [];

const files = [];
for (const root of ROOTS) {
  for (const name of await readdir(root)) {
    if (name.endsWith('.php')) files.push(join(root, name));
  }
}

for (const path of [...new Set(files)]) {
  const file = path.replace(/\\/g, '/').split('/').slice(-2).join('/');
  const src = await readFile(path, 'utf8');
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

  // A file that renders a meta box or hooks into another screen has no form of
  // its own, so any button() it emits lands inside somebody else's form.
  const rendersIntoAnotherForm = /add_meta_box|woocommerce_admin_order_data|add_action\(\s*'edit_form/.test(src);
  if (rendersIntoAnotherForm && /Pokbon_Delivery_Admin::button\s*\(/.test(src)) {
    problems.push(
      `${file}: button() in a file that renders into another screen's form — ` +
        `WooCommerce and WordPress wrap meta boxes in their own <form>, so this one will be merged into it`,
    );
  }
}

/*
 * Every control must also reach a handler.
 *
 * This has now happened three times: a button is written, it renders, it looks
 * exactly right, and pressing it falls straight through the switch in
 * class-admin.php and does nothing at all. The Payouts screen shipped with "I
 * have paid this" wired to a case that did not exist — on the one screen where
 * doing nothing means a rider is not paid and nobody finds out.
 *
 * A button that does nothing is worse than a missing one: somebody presses it,
 * believes it worked, and moves on.
 */
{
  const adminSrc = await readFile('plugin/pokbon-delivery/admin/class-admin.php', 'utf8');
  const handled = new Set([...adminSrc.matchAll(/case\s+'([a-z0-9_]+)'\s*:/g)].map((m) => m[1]));

  for (const path of [...new Set(files)]) {
    const file = path.replace(/\\/g, '/').split('/').slice(-2).join('/');
    const src = await readFile(path, 'utf8');
    const actions = [
      ...src.matchAll(/Pokbon_Delivery_Admin::(?:form_open|button)\s*\(\s*'([a-z0-9_]+)'/g),
    ].map((m) => m[1]);

    for (const action of new Set(actions)) {
      if (!handled.has(action)) {
        problems.push(
          `${file}: renders a control for '${action}' and class-admin.php has no case for it — ` +
            `pressing it does nothing, silently`,
        );
      }
    }
  }
}

/*
 * Every page must be reachable.
 *
 * The check above walks from the button inwards, so it never noticed the
 * bigger version of the same mistake: a page file, a working render method,
 * forms, handlers — and no line in the $pages map in class-admin.php, which is
 * the only thing that puts a page in the menu. Payouts and Collection points
 * both shipped that way. Nothing errored. There was simply no way to open
 * them, and the only symptom was somebody looking for a screen that was not
 * there.
 *
 * Reachable means: required by a render_* method, AND that method named in
 * $pages. Either half missing is the same outcome.
 */
{
  const adminSrc = await readFile('plugin/pokbon-delivery/admin/class-admin.php', 'utf8');

  // Which render_* method requires which page file.
  const requiredBy = new Map();
  for (const m of adminSrc.matchAll(
    /function\s+(render_[a-z_]+)\s*\([^)]*\)[^{]*\{\s*require[^;]*admin\/pages\/([a-z0-9-]+\.php)/g,
  )) {
    requiredBy.set(m[2], m[1]);
  }

  // Which callbacks the menu map actually lists.
  const menuBlock = adminSrc.match(/\$pages\s*=\s*\[([\s\S]*?)\n\s*\];/);
  const inMenu = new Set(
    menuBlock ? [...menuBlock[1].matchAll(/'(render_[a-z_]+)'/g)].map((m) => m[1]) : [],
  );
  if (!menuBlock) problems.push('class-admin.php: could not find the $pages menu map at all');

  for (const name of (await readdir('plugin/pokbon-delivery/admin/pages')).sort()) {
    if (!name.endsWith('.php')) continue;
    const callback = requiredBy.get(name);
    if (!callback) {
      problems.push(`admin/pages/${name}: no render_* method requires it — the file is never loaded`);
    } else if (!inMenu.has(callback)) {
      problems.push(
        `admin/pages/${name}: ${callback}() exists but is not in the $pages map — ` +
          `the page has no menu entry, so there is no way to open it`,
      );
    }
  }
}

if (problems.length) {
  console.error('Admin form problems:\n');
  for (const p of problems) console.error('  ' + p);
  console.error(`\n${problems.length} problem(s).`);
  process.exit(1);
}

console.log('Admin forms OK: none nested, none left open.');
