/**
 * Admin content-management round trip.
 *
 * DEV TOOLING ONLY. Proves the requirement that matters most about a CMS: text
 * an administrator saves in the back office actually appears on the public
 * website, and resetting it restores the bundled copy rather than blanking a
 * legal page.
 *
 *   node tools/devserver/content_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();
const pw = argv[argv.indexOf('--admin-password') + 1];
if (!pw) {
  console.error('usage: content_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const stamp = Date.now().toString().slice(-8);
const marker = `Policy revision ${stamp}`;

const a = new Client(BASE);
const pub = new Client(BASE);

console.log('── Content · admin');
await a.get('/admin/login');
const login = await a.postForm('/admin/login', { identifier: 'admin', password: pw });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url), `at ${login.url}`);

const index = await a.get('/admin/pages');
check('website pages list loads', index.status === 200);
check('the legal pages are listed', /Terms &amp; Conditions|Terms &amp; Conditions|Terms/.test(index.text));
check('a page starts on the bundled text', /Default/.test(index.text));

console.log('\n── Content · edit');
const form = await a.get('/admin/pages/refund-policy');
check('edit form loads', form.status === 200 && /name="body_html"/.test(form.text));

const saved = await a.postForm(
  '/admin/pages/refund-policy/save',
  {
    title: 'Refund Policy',
    meta_description: 'How refunds work at MarvySocials.',
    body_html: `<h2>Refunds</h2><p>${marker}</p><p>Undelivered quantity is returned to your wallet.</p>`,
    is_published: '1',
  },
  { fromHtml: form.text }
);
check('save accepted', saved.status === 200 && !/error/i.test(saved.url), `status=${saved.status}`);

console.log('\n── Content · the change is live');
const live = await pub.get('/refund-policy');
check('public page loads', live.status === 200);
check('the edited text appears on the website', live.text.includes(marker),
  'the saved content did not reach the public page');
check('the page title is applied', /<title>[^<]*Refund Policy/.test(live.text));

console.log('\n── Content · sanitisation');
const form2 = await a.get('/admin/pages/refund-policy');
const xss = await a.postForm(
  '/admin/pages/refund-policy/save',
  {
    title: 'Refund Policy',
    body_html:
      `<p>${marker}</p><script>window.__pwned=1</script>` +
      `<img src=x onerror="window.__pwned=1">` +
      `<a href="javascript:alert(1)">click</a>`,
    is_published: '1',
  },
  { fromHtml: form2.text }
);
check('save with hostile markup is accepted', xss.status === 200);

const live2 = await pub.get('/refund-policy');
check('script tag is stripped', !/<script>window\.__pwned/.test(live2.text), 'a script tag survived');
check('inline event handler is stripped', !/onerror=/i.test(live2.text), 'onerror survived');
check('javascript: URL is neutralised', !/href="javascript:/i.test(live2.text), 'javascript: URL survived');
check('the legitimate text still renders', live2.text.includes(marker));

console.log('\n── Content · reset');
const form3 = await a.get('/admin/pages/refund-policy');
const reset = await a.postForm('/admin/pages/refund-policy/reset', {}, { fromHtml: form3.text });
check('reset accepted', reset.status === 200, `status=${reset.status}`);

const live3 = await pub.get('/refund-policy');
check('public page still works after reset', live3.status === 200);
check('the override is gone', !live3.text.includes(marker), 'custom text survived the reset');
check('the bundled policy is back, not a blank page', live3.text.length > 3000,
  `page was only ${live3.text.length} bytes`);

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
