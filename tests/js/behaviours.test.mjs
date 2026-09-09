/**
 * Frontend regression test for the CSP-safe declarative behaviours.
 *
 * The admin and dashboard UIs used to open dialogs, confirm destructive posts,
 * copy keys and auto-submit filters through inline `onclick="…"` /
 * `onsubmit="…"` attributes. Those attributes forced the Content-Security-
 * Policy to allow 'unsafe-inline' for scripts. They are now data attributes
 * implemented by one delegated listener in assets/js/app.js, and script-src is
 * nonce-only — so if this listener regresses, buttons silently stop working
 * with no server-side test able to notice.
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
<button id="open" data-dialog-open="dlg">New ticket</button>
<dialog id="dlg" data-dialog-light-dismiss>
  <button id="cancel" type="button" data-dialog-close="dlg">Cancel</button>
</dialog>

<form id="danger" method="post" action="/admin/orders/1/refund" data-confirm="Refund this order in full?">
  <button type="submit">Refund</button>
</form>

<form id="filters" method="get" action="/dashboard/orders">
  <select id="status" name="status" data-autosubmit><option value="">All</option><option value="PENDING">Pending</option></select>
</form>

<code id="ws-key">wind_live_abc123</code>
<button id="copy" data-copy="#ws-key" data-copied-label="Copied">Copy</button>

<input id="master" type="checkbox" data-check-all=".listing-check">
<input class="listing-check" type="checkbox"><input class="listing-check" type="checkbox">

<select id="order-status" data-toggle-target="remains-row" data-toggle-when="PARTIAL">
  <option value="COMPLETED">Completed</option><option value="PARTIAL">Partial</option>
</select>
<div id="remains-row" hidden>Remains</div>
</body></html>`;

const results = [];
function assert(condition, message) {
  if (!condition) throw new Error(message);
  results.push(message);
}

function boot() {
  const dom = new JSDOM(html, { url: 'https://panel.example/admin/orders/1', runScripts: 'outside-only' });
  const { window } = dom;
  window.Element.prototype.scrollIntoView = function () {};
  window.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: { name: 'csrf_marvy', hash: 'test-token-123' } }) });
  // jsdom implements <dialog> but not showModal in every version.
  const proto = window.HTMLDialogElement ? window.HTMLDialogElement.prototype : null;
  if (proto && typeof proto.showModal !== 'function') {
    proto.showModal = function () { this.open = true; };
    proto.close = function () { this.open = false; };
  }
  let copied = null;
  window.navigator.clipboard = { writeText: (t) => { copied = t; return Promise.resolve(); } };
  window.eval(appJs);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  return { window, copied: () => copied };
}

function click(window, el, init = {}) {
  const event = new window.MouseEvent('click', { bubbles: true, cancelable: true, ...init });
  el.dispatchEvent(event);
  return event;
}

// --- dialogs ---------------------------------------------------------------
{
  const { window } = boot();
  const doc = window.document;
  const dlg = doc.getElementById('dlg');
  assert(!dlg.open, 'dialog starts closed');
  click(window, doc.getElementById('open'));
  assert(dlg.open, 'data-dialog-open opens the dialog');
  click(window, doc.getElementById('cancel'));
  assert(!dlg.open, 'data-dialog-close closes the dialog');
  click(window, doc.getElementById('open'));
  click(window, dlg);
  assert(!dlg.open, 'data-dialog-light-dismiss closes on a backdrop click');
}

// --- confirm-guarded destructive form --------------------------------------
{
  const { window } = boot();
  const form = window.document.getElementById('danger');

  window.confirm = () => false;
  let submitted = new window.Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(submitted);
  assert(submitted.defaultPrevented, 'declining the confirm blocks the destructive POST');

  window.confirm = () => true;
  submitted = new window.Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(submitted);
  assert(!submitted.defaultPrevented, 'accepting the confirm lets the POST through');
}

// --- filter select auto-submit ---------------------------------------------
{
  const { window } = boot();
  const form = window.document.getElementById('filters');
  let submits = 0;
  form.submit = () => { submits++; };
  const select = window.document.getElementById('status');
  select.value = 'PENDING';
  select.dispatchEvent(new window.Event('change', { bubbles: true }));
  assert(submits === 1, 'data-autosubmit submits the filter form on change');
}

// --- copy to clipboard ------------------------------------------------------
{
  const ctx = boot();
  const btn = ctx.window.document.getElementById('copy');
  click(ctx.window, btn);
  assert(ctx.copied() === 'wind_live_abc123', 'data-copy copies the referenced element');
  assert(btn.textContent === 'Copied', 'data-copied-label confirms the copy to the operator');
}

// --- master checkbox --------------------------------------------------------
{
  const { window } = boot();
  const master = window.document.getElementById('master');
  click(window, master); // a real click toggles the master, then the handler runs
  const boxes = [...window.document.querySelectorAll('.listing-check')];
  assert(boxes.every((b) => b.checked === master.checked), 'data-check-all mirrors the master checkbox onto every listing checkbox');
  click(window, master);
  assert(boxes.every((b) => b.checked === master.checked), 'and unticks them again');
}

// --- conditional row --------------------------------------------------------
{
  const { window } = boot();
  const select = window.document.getElementById('order-status');
  const row = window.document.getElementById('remains-row');
  select.value = 'PARTIAL';
  select.dispatchEvent(new window.Event('change', { bubbles: true }));
  assert(row.hidden === false, 'data-toggle-target reveals the row for the matching value');
  select.value = 'COMPLETED';
  select.dispatchEvent(new window.Event('change', { bubbles: true }));
  assert(row.hidden === true, 'and hides it again for any other value');
}

console.log(results.map((r) => '  ✓ ' + r).join('\n'));
console.log(`\n${results.length} checks passed`);
