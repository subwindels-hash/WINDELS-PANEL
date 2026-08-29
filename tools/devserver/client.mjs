/**
 * Shared HTTP client for the dev-server test scripts.
 *
 * DEV TOOLING ONLY. A browser-ish client: cookie jar, redirect following and
 * CSRF token extraction, so the tests drive the real application the way a
 * browser does rather than poking controllers directly.
 *
 * Importing this module also bootstraps `process.env` from the repository
 * `.env` (see env.mjs), so `process.env.DEMO_PASSWORD` — which every check
 * resolves before its fallback — is whatever the current database was
 * seeded with, on any machine.
 */
import { loadDotEnv } from './env.mjs';

loadDotEnv();

const ERROR_MARKERS = [
  'A Database Error Occurred',
  'An uncaught Exception',
  'A PHP Error was encountered',
  'Fatal error',
  'Parse error',
  'Undefined constant',
  'Call to undefined',
  'An unexpected error occurred',
  'Unable to locate',
];

/** A browser-ish HTTP client: cookie jar, redirects, CSRF token extraction. */
export class Client {
  constructor(base = 'http://127.0.0.1:8080') {
    this.base = base;
    this.jar = new Map();
    this.last = null;
  }

  cookieHeader() {
    return [...this.jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  }

  storeCookies(res) {
    const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    for (const line of raw) {
      const [pair] = line.split(';');
      const idx = pair.indexOf('=');
      if (idx === -1) continue;
      const name = pair.slice(0, idx).trim();
      const value = pair.slice(idx + 1).trim();
      if (value === '' || /expires=Thu, 01 Jan 1970/i.test(line)) this.jar.delete(name);
      else this.jar.set(name, value);
    }
  }

  async raw(pathname, options = {}) {
    const url = pathname.startsWith('http') ? pathname : this.base + pathname;
    const headers = Object.assign({}, options.headers);
    const cookies = this.cookieHeader();
    if (cookies) headers.cookie = cookies;
    const res = await fetch(url, { ...options, headers, redirect: 'manual' });
    this.storeCookies(res);
    const text = await res.text();
    this.last = { url, status: res.status, text, headers: res.headers };
    return this.last;
  }

  /** GET, following redirects (like a browser). */
  async get(pathname, hops = 5) {
    let r = await this.raw(pathname);
    while (r.status >= 300 && r.status < 400 && hops-- > 0) {
      const loc = r.headers.get('location');
      if (!loc) break;
      r = await this.raw(loc.startsWith('http') ? loc : loc);
    }
    return r;
  }

  /**
   * Pull the CSRF token out of a rendered form.
   *
   * Matches the whole <input> and reads its attributes separately, because
   * name= and value= appear in either order depending on the view helper.
   */
  csrfFrom(html) {
    for (const tag of html.match(/<input[^>]*>/gi) || []) {
      const name = (/name="([^"]+)"/i.exec(tag) || [])[1];
      const value = (/value="([^"]*)"/i.exec(tag) || [])[1];
      if (name && /csrf/i.test(name) && value) return { name, value };
    }
    return null;
  }

  /** POST a form, reusing the CSRF token from a page we just loaded. */
  async postForm(pathname, fields, { fromHtml = null, follow = true } = {}) {
    const html = fromHtml ?? this.last?.text ?? '';
    const token = this.csrfFrom(html);
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(fields)) {
      // Repeated fields (checkbox groups like `listing_ids[]`) need one
      // `append()` per value — URLSearchParams would otherwise stringify an
      // array into one comma-joined value, which PHP's $_POST never parses
      // back into an array.
      if (Array.isArray(v)) { for (const item of v) body.append(k, item); }
      else body.append(k, v);
    }
    if (token && !body.has(token.name)) body.append(token.name, token.value);

    let r = await this.raw(pathname, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    });
    let hops = 5;
    while (follow && r.status >= 300 && r.status < 400 && hops-- > 0) {
      const loc = r.headers.get('location');
      if (!loc) break;
      r = await this.raw(loc);
    }
    return r;
  }
}


/** Error text that means a page failed even when the status code is 200. */
export function errorMarkersIn(text) {
  return ERROR_MARKERS.filter((m) => text.includes(m));
}
