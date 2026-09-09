/**
 * Responsive-layout checks.
 *
 * DEV TOOLING ONLY. This is a static audit of the rendered HTML/CSS, not a
 * pixel test — there is no browser here. It catches the failures that actually
 * break small screens and that a human reviewer misses: a missing viewport tag,
 * fixed pixel widths wide enough to force horizontal scroll, and grids with no
 * mobile breakpoint.
 *
 *   node tools/devserver/responsive_check.mjs
 */
import fs from 'node:fs';
import path from 'node:path';

const argv = process.argv.slice(2);
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();
const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const PAGES = ['/', '/services', '/pricing', '/about', '/faq', '/contact', '/terms', '/login', '/register'];

console.log('── Responsive · rendered pages');
for (const p of PAGES) {
  const res = await fetch(BASE + p);
  const html = await res.text();

  const hasViewport = /<meta[^>]+name=["']viewport["'][^>]+width=device-width/i.test(html);
  if (!hasViewport) {
    check(`${p} declares a viewport`, false, 'missing width=device-width — the page will render zoomed out');
    continue;
  }

  // Fixed widths in inline styles are the usual cause of horizontal overflow.
  // Only a hard "width: NNNpx" can force horizontal scroll. max-width and
  // min-width are the responsive-safe forms and must not be flagged, so the
  // preceding characters are checked explicitly.
  const wide = [...html.matchAll(/style="[^"]*?([a-z-]*)width:\s*(\d{3,})px/gi)]
    .filter((m) => m[1] !== 'max-' && m[1] !== 'min-')
    .map((m) => parseInt(m[2], 10))
    .filter((w) => w > 420);

  // A grid with no media query and 3+ fixed columns squashes on a phone.
  const rigidGrid = /grid-template-columns:\s*repeat\((\d+),/i.exec(html);
  const hasBreakpoint = /@media\s*\([^)]*max-width/i.test(html);

  const ok = wide.length === 0 && (!rigidGrid || parseInt(rigidGrid[1], 10) <= 2 || hasBreakpoint);
  check(
    `${p} has no fixed-width overflow`,
    ok,
    wide.length ? `inline width up to ${Math.max(...wide)}px` : 'multi-column grid with no max-width breakpoint'
  );
}

console.log('\n── Responsive · stylesheet');
const cssPath = path.join(ROOT, 'assets/css/design-system.css');
const css = fs.readFileSync(cssPath, 'utf8');

check('the design system defines mobile breakpoints', /@media[^{]*max-width/i.test(css));
check(
  'tables can scroll rather than overflow',
  /overflow-x\s*:\s*auto/i.test(css),
  'no horizontal scroll container — wide tables will push the page'
);
check(
  'the sidebar collapses on small screens',
  /@media[^{]*max-width[^}]*\{[\s\S]{0,4000}?(sidebar|ws-side|nav)/i.test(css),
  'no responsive rule mentioning the sidebar'
);
check(
  'no viewport-breaking min-width on the body',
  !/body\s*\{[^}]*min-width:\s*\d{3,}px/i.test(css)
);

console.log('\n── Responsive · dashboard shell');
const appLayout = fs.readFileSync(path.join(ROOT, 'application/views/layouts/app.php'), 'utf8');
const appCss = fs.readFileSync(path.join(ROOT, 'assets/css/design-system.css'), 'utf8');
// The shell uses a hidden-on-mobile sidebar (off-canvas via .ws-sidebar, driven
// by a max-width media query in design-system.css) plus a fixed bottom tab bar
// — a legitimate mobile pattern. The sidebar/tabbar visibility is CSS-driven,
// not expressed as Tailwind utility classes in the markup, so this asserts the
// CSS rules that actually implement it rather than a specific class string.
check(
  'the sidebar is hidden on small screens',
  /@media\s*\(max-width:\s*767px\)\s*\{[\s\S]{0,400}?\.ws-sidebar\s*\{[\s\S]{0,200}?transform:\s*translateX\(-100%\)/.test(appCss)
    && appLayout.includes('class="ws-sidebar"'),
  'no off-canvas transform for .ws-sidebar below the mobile breakpoint'
);
check(
  'a mobile navigation replaces it',
  /\.ws-mobile-tabbar\s*\{[^}]*display:\s*none/.test(appCss)
    && /@media\s*\(max-width:\s*767px\)\s*\{[\s\S]{0,2000}?\.ws-mobile-tabbar\s*\{[^}]*display:\s*grid/.test(appCss)
    && appLayout.includes('class="ws-mobile-tabbar"'),
  'no mobile bottom navigation'
);
check('the app shell declares a viewport', /width=device-width/.test(appLayout)
  || /partials\/head/.test(appLayout), 'no viewport and no shared head partial');

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
