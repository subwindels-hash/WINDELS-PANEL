/**
 * Frontend regression test for the provider → service → category picker.
 *
 * The admin service form keeps its "Upstream service" and "Category" fields
 * coherent when the operator switches provider: the synced catalogue is
 * fetched from the services.view JSON endpoint, the picker is rebuilt, and a
 * service whose provider category has no panel equivalent offers
 * "create <that category>" as a select option. The PHP end of that flow is
 * covered by ProviderImportTest; this file pins the inline script in
 * views/admin/services/form.php, which no server-side test can see.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const view = readFileSync(resolve(root, 'application/views/admin/services/form.php'), 'utf8');

// Render the script's two PHP interpolations the way the server would (the
// endpoint URL and the sentinel option value), then extract the script body.
// The nonce attribute tag is `<<?=csp_nonce_attr()?>>` — the `?>` closes PHP
// and the trailing `>` closes the HTML tag, so drop both PHP parts first.
const rendered = view
  .replace(/<\?=\s*json_encode\(site_url\('admin\/services\/provider-services'\)\)\s*\?>/g,
    '"/admin/services/provider-services"')
  .replace(/<\?=\s*json_encode\(SmmServiceAdminService::PROVIDER_CATEGORY_OPTION\)\s*\?>/g,
    '"__provider_category__"')
  .replace(/<\?=\s*csp_nonce_attr\(\)\s*\?>/g, '');
const scriptMatch = /<script\b[^>]*>\s*([\s\S]*?)<\/script>/.exec(rendered);
if (!scriptMatch) throw new Error('no inline script found in the service form view');
const script = scriptMatch[1];
if (script.includes('<?')) throw new Error('unreplaced PHP tag left in the extracted script');

const SENTINEL = '__provider_category__';

const CATALOGUE = [
  { id: '101', name: 'Instagram Followers [Real]', category: 'Instagram', rate: '0.90000000', min: 100, max: 50000 },
  { id: '102', name: 'TikTok Views', category: 'TikTok', rate: '0.05000000', min: 100, max: 1000000 },
];

const html = `<!doctype html><html><head></head><body>
<select id="ws-provider-select" name="provider">
  <option value="">No provider</option>
  <option value="pub-1">Fake Panel Co.</option>
  <option value="pub-2">Other Panel</option>
</select>
<div id="ws-provider-service-field">
  <span>Upstream service ID</span>
  <input class="input mono" name="provider_service_id" maxlength="64" value="">
</div>
<select id="ws-category-select" name="category_id">
  <option value="">Choose a category</option>
  <option value="7">Instagram</option>
</select>
</body></html>`;

const results = [];
function assert(condition, message) {
  if (!condition) throw new Error(message);
  results.push(message);
}

function boot(fetchImpl) {
  const dom = new JSDOM(html, { url: 'https://panel.example/admin/services/create', runScripts: 'outside-only' });
  const { window } = dom;
  window.fetch = fetchImpl;
  window.eval(script);
  return window;
}

const settle = () => new Promise((r) => setTimeout(r, 0));

function change(window, el) {
  el.dispatchEvent(new window.Event('change', { bubbles: true }));
}

// --- switching provider rebuilds the picker from the synced catalogue -------
{
  const window = boot(async (url) => {
    assert(String(url).endsWith('/admin/services/provider-services?provider=pub-1'),
      'the catalogue fetch targets the JSON endpoint with the chosen provider');
    return { ok: true, json: async () => ({ ok: true, truncated: false, services: CATALOGUE }) };
  });
  const doc = window.document;

  const providerSel = doc.getElementById('ws-provider-select');
  providerSel.value = 'pub-1';
  change(window, providerSel);
  await settle();

  const picker = doc.getElementById('ws-provider-service');
  assert(picker && picker.tagName === 'SELECT', 'switching provider replaces the ID field with a picker');
  assert(picker.options.length === 3, 'the picker offers every synced service plus a placeholder');
  assert(picker.options[1].textContent.includes('#101')
    && picker.options[1].textContent.includes('Instagram Followers [Real]'),
    'options are labelled with the upstream ID, name and rate');
  assert(picker.options[1].dataset.category === 'Instagram', 'each option carries its provider category');

  // A service whose category exists on the panel just selects it.
  picker.value = '101';
  change(window, picker);
  const categorySel = doc.getElementById('ws-category-select');
  assert(categorySel.value === '7' && categorySel.selectedIndex === 1,
    'a service whose provider category exists preselects the matching panel category');
  assert(![...categorySel.options].some((o) => o.value === SENTINEL),
    'no create-category option is offered when the category already exists');

  // One whose category does not exist offers to create it.
  picker.value = '102';
  change(window, picker);
  const sentinelOption = [...categorySel.options].find((o) => o.value === SENTINEL);
  assert(sentinelOption && sentinelOption.selected, 'an unmatched provider category offers "create …" and selects it');
  assert(sentinelOption.textContent.includes('TikTok'),
    'the create-category option names the provider\'s own category');
}

// --- a failed fetch degrades to the manual field, not a dead form -----------
{
  const window = boot(async () => { throw new Error('network down'); });
  const doc = window.document;
  const providerSel = doc.getElementById('ws-provider-select');
  providerSel.value = 'pub-2';
  change(window, providerSel);
  await settle();

  const input = doc.querySelector('#ws-provider-service-field input[name=provider_service_id]');
  assert(input && input.tagName === 'INPUT', 'a failed catalogue fetch falls back to the manual ID field');
}

// --- clearing the provider unlinks without losing the page ------------------
{
  const window = boot(async () => ({ ok: true, json: async () => ({ ok: true, truncated: false, services: CATALOGUE }) }));
  const doc = window.document;
  const providerSel = doc.getElementById('ws-provider-select');
  providerSel.value = 'pub-1';
  change(window, providerSel);
  await settle();

  providerSel.value = '';
  change(window, providerSel);
  await settle();
  assert(!doc.getElementById('ws-provider-service'),
    'clearing the provider removes the picker');
  const input = doc.querySelector('#ws-provider-service-field input[name=provider_service_id]');
  assert(input && input.value === '', 'and restores an empty manual ID field');

  // A stale sentinel must not survive as the posted category.
  const categorySel = doc.getElementById('ws-category-select');
  assert(![...categorySel.options].some((o) => o.value === SENTINEL),
    'clearing the provider leaves no create-category option behind');
}

// --- a truncated catalogue keeps a manual escape hatch ----------------------
{
  const window = boot(async () => ({
    ok: true,
    json: async () => ({ ok: true, truncated: true, services: CATALOGUE }),
  }));
  const doc = window.document;
  const providerSel = doc.getElementById('ws-provider-select');
  providerSel.value = 'pub-1';
  change(window, providerSel);
  await settle();

  const manual = doc.querySelectorAll('#ws-provider-service-field [name=provider_service_id]');
  assert(manual.length === 2, 'a truncated catalogue renders the picker and a manual ID field');
  const [select, input] = manual;
  input.value = '9999';
  input.dispatchEvent(new window.Event('input', { bubbles: true }));
  assert(select.value === '', 'typing a manual ID clears the picker so one name carries one value');
  select.value = '101';
  change(window, select);
  assert(input.value === '', 'and picking from the dropdown clears the manual field back');
}

console.log(results.map((r) => `  ✓ ${r}`).join('\n'));
console.log(`\n${results.length} checks passed`);
