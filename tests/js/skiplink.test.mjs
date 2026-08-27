/**
 * Frontend regression test for the "Skip to content" link.
 *
 * Regression: clicking the skip link used the browser's native anchor jump,
 * which left #main in the address bar (e.g. https://averioncommerce.org/#main)
 * and in any URL the visitor copied afterwards. assets/js/app.js now
 * intercepts the click, moves focus to <main> itself and keeps the URL clean.
 *
 * Rendered without booting CodeIgniter: the exact markup from
 * partials/navbar.php + layouts/main.php is reproduced in jsdom, app.js is
 * loaded, and the click is dispatched for real.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(resolve(root, 'assets/js/app.js'), 'utf8');

const html = `<!doctype html><html><head>
<meta name="csrf-name" content="csrf_marvy">
<meta name="csrf-token" content="test-token-123">
<meta name="csrf-endpoint" content="/csrf">
</head><body>
<a class="ws-skip" href="#main">Skip to content</a>
<nav class="ws-public-nav" aria-label="Primary"><div class="ws-public-nav-inner">nav</div></nav>
<main id="main" class="ws-main"><div class="container">page content</div></main>
</body></html>`;

const results = [];
function assert(condition, message) {
  if (!condition) throw new Error(message);
  results.push(message);
}

function boot(url) {
  const dom = new JSDOM(html, { url, runScripts: 'outside-only' });
  const { window } = dom;
  // jsdom has no layout engine: stub the scrolling APIs app.js calls.
  window.Element.prototype.scrollIntoView = function () {};
  window.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: { name: 'csrf_marvy', hash: 'test-token-123' } }) });
  window.eval(appJs);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  return window;
}

// --- Case 1: clicking the link must not send the browser to .../#main ------
{
  const window = boot('https://averioncommerce.org/services');
  const link = window.document.querySelector('a.ws-skip');
  assert(link !== null, 'skip link exists');
  assert(link.getAttribute('href') === '#main', 'skip link keeps href="#main" (works without JS)');

  const event = new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
  link.dispatchEvent(event);

  assert(event.defaultPrevented, 'click default (URL hash jump) is prevented');
  assert(window.location.href === 'https://averioncommerce.org/services', 'URL stays clean — no #main appended');

  const main = window.document.getElementById('main');
  assert(window.document.activeElement === main, 'focus moves to <main id="main">');
  assert(main.getAttribute('tabindex') === '-1', '<main> becomes focusable via tabindex="-1"');
}

// --- Case 2: arriving with #main already in the URL cleans it up -----------
{
  const window = boot('https://averioncommerce.org/#main');
  assert(window.location.href === 'https://averioncommerce.org/', 'stale #main is stripped from the URL on load');
}

// --- Case 3: the handler must not swallow modified clicks (open in new tab)
{
  const window = boot('https://averioncommerce.org/pricing');
  const link = window.document.querySelector('a.ws-skip');
  const event = new window.MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
  link.dispatchEvent(event);
  assert(!event.defaultPrevented, 'ctrl+click (open in new tab) is left to the browser');
}

console.log('SKIPLINK TEST PASSED (' + results.length + ' assertions)');
for (const r of results) console.log('  ✓ ' + r);
