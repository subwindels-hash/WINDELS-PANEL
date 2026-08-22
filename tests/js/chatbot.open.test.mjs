/**
 * Frontend regression test for the on-site assistant.
 *
 * We cannot boot CodeIgniter in this lightweight Node test, so the test renders
 * the exact chat markup produced by partials/site_operator.php inside jsdom and
 * loads assets/js/app.js. It verifies the chain that was previously breaking:
 *
 *   button exists -> click handler attached -> panel opens -> send -> fetch ->
 *   JSON -> assistant bubble
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(resolve(root, 'assets/js/app.js'), 'utf8');

const html = `<!doctype html><html><head>
<meta name="csrf-name" content="csrf_windels">
<meta name="csrf-token" content="test-token-123">
<meta name="csrf-endpoint" content="/csrf">
</head><body>
<button type="button" class="ws-assistant-launch" id="ws-assistant-launch"
        aria-controls="ws-assistant" aria-expanded="false">
  <span>Assistant</span>
</button>
<section class="ws-assistant" id="ws-assistant" hidden role="dialog"
         data-endpoint="/assistant/chat" aria-label="Site assistant">
  <header class="ws-assistant-head">
    <button type="button" class="btn btn-ghost btn-sm" id="ws-assistant-close">Close</button>
  </header>
  <div class="ws-assistant-log" id="ws-assistant-log">
    <div class="ws-bubble ws-bubble-assistant">Welcome</div>
  </div>
  <div class="ws-suggest" id="ws-assistant-suggest">
    <button type="button" data-suggest="What is WINDELS PANEL?">What is WINDELS PANEL?</button>
  </div>
  <div class="ws-assistant-status" id="ws-assistant-status" aria-live="polite"></div>
  <form class="ws-assistant-form" id="ws-assistant-form">
    <label class="sr-only" for="ws-assistant-input">Your question</label>
    <input class="input" id="ws-assistant-input" name="message" autocomplete="off" maxlength="1000">
    <button class="btn btn-primary" type="submit" id="ws-assistant-send">Send</button>
  </form>
</section>
<button type="button" data-nav-toggle aria-controls="ws-nav-panel" aria-expanded="false">Menu</button>
<div id="ws-nav-panel" class="ws-nav-panel" hidden></div>
</body></html>`;

function makeResponse(body) {
  return {
    ok: true,
    status: 200,
    headers: { get: () => null },
    json: async () => body,
  };
}

let assistantHits = 0;
const dom = new JSDOM(html, { url: 'https://example.test/services', runScripts: 'outside-only' });
const { window } = dom;

// Mock the network. The chat fetch is intercepted by app.js' own CSRF wrapper;
// this mock is the response the wrapper ultimately returns.
window.fetch = async (input) => {
  const url = typeof input === 'string' ? input : String(input.url || '');
  if (url.endsWith('/assistant/chat')) {
    assistantHits++;
    return makeResponse({
      success: true,
      data: {
        reply: 'WINDELS PANEL is a prepaid commerce platform. Recent data from the test harness.',
        intent: 'about',
        links: [{ label: 'Services', href: '/services' }],
        suggestions: ['What services do you offer?', 'How do I sign up?'],
      },
    });
  }
  return makeResponse({ success: true, data: { name: 'csrf_windels', hash: 'test-token-123' } });
};

if (!window.XMLHttpRequest || !window.XMLHttpRequest.prototype) {
  // jsdom generally provides XMLHttpRequest; a minimal shim keeps this test
  // focused on the assistant rather than on the XHR polyfill.
  window.XMLHttpRequest = function () {};
  window.XMLHttpRequest.prototype.open = function () {};
  window.XMLHttpRequest.prototype.send = function () {};
  window.XMLHttpRequest.prototype.setRequestHeader = function () {};
}

window.eval(appJs);
window.document.dispatchEvent(new window.Event('DOMContentLoaded'));

const results = [];
function assert(condition, message) {
  if (!condition) throw new Error(message);
  results.push(message);
}

const launch = window.document.getElementById('ws-assistant-launch');
const panel = window.document.getElementById('ws-assistant');

assert(launch !== null, 'chatbot toggle button exists');
assert(panel !== null, 'chatbot panel exists');
assert(panel.hidden === true, 'chatbot panel starts closed');

launch.click();
assert(panel.hidden === false, 'chatbot panel opens on button click');
assert(launch.getAttribute('aria-expanded') === 'true', 'aria-expanded updates when opened');
const focused = window.document.activeElement === window.document.getElementById('ws-assistant-input');
assert(focused, 'chat input receives focus after opening');

const input = window.document.getElementById('ws-assistant-input');
const form = window.document.getElementById('ws-assistant-form');
input.value = 'What is WINDELS PANEL?';
form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

// Allow the CSRF token resolution + fetch to resolve.
await new Promise((resolvePromise) => setTimeout(resolvePromise, 25));

const log = window.document.getElementById('ws-assistant-log');
const bubbles = log.querySelectorAll('.ws-bubble');
const userBubble = log.querySelector('.ws-bubble-user');
const assistantBubble = log.querySelector('.ws-bubble-assistant:not(:first-child)');

assert(assistantHits === 1, 'assistant endpoint is called once');
assert(userBubble !== null, 'user message bubble is rendered');
assert(assistantBubble !== null && assistantBubble.textContent.indexOf('WINDELS PANEL') !== -1,
  'assistant reply bubble is rendered from JSON');

// Close path.
const close = window.document.getElementById('ws-assistant-close');
close.click();
assert(panel.hidden === true, 'chatbot panel closes');

console.log('CHATBOT TEST PASSED (' + results.length + ' assertions)');
for (const r of results) console.log('  ✓ ' + r);
