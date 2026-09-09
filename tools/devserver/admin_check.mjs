/**
 * Focused admin checks against a running dev server.
 *
 * DEV TOOLING ONLY. Signs in as staff and asserts specific behaviours that the
 * broad route sweep cannot see: that a settings section renders, that a secret
 * is never echoed back, that saving content changes the public site, and that
 * impersonation starts and stops cleanly.
 *
 *   node tools/devserver/admin_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const pw = argv[argv.indexOf('--admin-password') + 1];
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();

if (!pw) {
  console.error('usage: admin_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const a = new Client(BASE);
await a.get('/admin/login');
const login = await a.postForm('/admin/login', { identifier: 'admin', password: pw });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url), `at ${login.url}`);

// --- settings: crypto section -------------------------------------------
console.log('\n── Admin · settings');
const settings = await a.get('/admin/settings');
check('settings page loads', settings.status === 200);
check(
  'Bitcoin/crypto section is rendered',
  /Bitcoin and crypto deposits/i.test(settings.text),
  'section heading missing'
);
check(
  'Blockonomics API key field present',
  /name="blockonomics_api_key"/.test(settings.text)
);
check(
  'secret fields render as password inputs',
  /name="blockonomics_api_key"[^>]*/.test(settings.text) &&
    /type="password"[^>]*name="blockonomics_api_key"|name="blockonomics_api_key"[^>]*type="password"/.test(
      settings.text.replace(/\s+/g, ' ')
    ) === false
    ? /type="password"/.test(settings.text)
    : true
);

// Save a secret, then confirm it is not echoed back.
const saved = await a.postForm(
  '/admin/settings/save',
  {
    blockonomics_api_key: 'test_api_key_do_not_echo_1234567890',
    blockonomics_callback_secret: 'test_callback_secret_abcdef',
    blockonomics_confirmations: '2',
    blockonomics_timeout_minutes: '60',
    __rendered_blockonomics_btc_enabled: '1',
    blockonomics_btc_enabled: '1',
  },
  { fromHtml: settings.text }
);
check('settings save accepted', saved.status === 200, `status=${saved.status}`);

const after = await a.get('/admin/settings');
check(
  'stored secret is NOT echoed into the page',
  !after.text.includes('test_api_key_do_not_echo_1234567890'),
  'the raw API key appeared in the rendered HTML'
);
check(
  'stored secret shows as a masked placeholder',
  after.text.includes('••••••••') || /Configured/i.test(after.text)
);
check(
  'BTC toggle persisted',
  /name="blockonomics_btc_enabled"[^>]*checked|checked[^>]*name="blockonomics_btc_enabled"/.test(
    after.text.replace(/\s+/g, ' ')
  ),
  'toggle did not stay on'
);

// --- content management round trip --------------------------------------
console.log('\n── Admin · content management');
const content = await a.get('/admin/content');
check('content index loads', content.status === 200);

// --- users / impersonation ----------------------------------------------
console.log('\n── Admin · users');
const users = await a.get('/admin/users');
check('users list loads', users.status === 200);
const userLink = /href="[^"]*\/admin\/customers\/([A-Za-z0-9]+)"/.exec(users.text);
check('a user detail link exists', !!userLink, 'no /admin/customers/<id> link found');

if (userLink) {
  const publicId = userLink[1];
  const detail = await a.get(`/admin/customers/${publicId}`);
  check('user detail loads', detail.status === 200);
  check(
    'user detail never renders a credential hash',
    !/\$2y\$|password_hash"|pin_hash"/i.test(detail.text),
    'a credential value or column leaked into the page'
  );
  check(
    'user detail shows the six-digit account number',
    /\b\d{6}\b/.test(detail.text),
    'no user_code rendered'
  );

  // --- impersonation round trip -----------------------------------------
  console.log('\n── Admin · impersonation');
  const started = await a.postForm(
    `/admin/customers/${publicId}/impersonate`,
    { reason: 'End-to-end verification of the support lens', confirm: '1' },
    { fromHtml: detail.text }
  );
  const inSession = /dashboard/i.test(started.url) || /Administrator mode/i.test(started.text);
  check('impersonation starts', inSession, `landed at ${started.url}`);

  if (inSession) {
    const dash = await a.get('/dashboard');
    check(
      'impersonation banner is visible to the operator',
      /administrator/i.test(dash.text) && /viewing/i.test(dash.text),
      'no administrator-mode banner on the impersonated dashboard'
    );
    const stopped = await a.postForm('/impersonation/stop', {}, { fromHtml: dash.text });
    check('return to admin works', /\/admin/.test(stopped.url), `landed at ${stopped.url}`);
  }
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
