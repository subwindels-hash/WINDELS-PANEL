/**
 * WINDELS PANEL — vanilla JS (no framework).
 *
 * Its one job today is CSRF plumbing, because that is what silently breaks
 * every page that posts more than once.
 *
 * CodeIgniter checks a CSRF token on every POST. A server-rendered form gets
 * one at render time and is fine. Anything that posts again from the same
 * page — an AJAX reply box, a support or chat widget, a retry after a failed
 * send — is holding a token that may already have been used or expired, and
 * the second send comes back rejected. From the customer's side: "the first
 * message went through, the second one says something went wrong".
 *
 * So: every same-origin fetch/XHR that changes state automatically carries the
 * current token in the X-CSRF-TOKEN header, the token is refreshed from
 * GET /csrf whenever the server hands back a new one, and a 419 (token
 * expired) is retried exactly once with a fresh token instead of surfacing as
 * a generic failure.
 *
 * Third-party widgets that do their own posting can use the public API:
 *
 *   await WINDELS.csrf()          // current token, refreshed if stale
 *   WINDELS.csrfHeader()          // { 'X-CSRF-TOKEN': '...' } to spread into headers
 *   WINDELS.csrfField()           // { csrf_windels: '...' } for form bodies
 */
(function () {
  'use strict';

  var TOKEN_HEADER = 'X-CSRF-TOKEN';
  var UNSAFE = /^(POST|PUT|PATCH|DELETE)$/i;

  function meta(name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? el.getAttribute('content') : null;
  }

  var state = {
    name: meta('csrf-name') || 'csrf_windels',
    hash: meta('csrf-token') || null,
    endpoint: (meta('csrf-endpoint') || '/csrf')
  };

  /** Push a new token into the meta tag and every rendered hidden input. */
  function adopt(name, hash) {
    if (!hash) return;
    if (name) state.name = name;
    state.hash = hash;

    var tag = document.querySelector('meta[name="csrf-token"]');
    if (tag) tag.setAttribute('content', hash);

    // Server-rendered forms hold the token that was current when the page was
    // built. Refreshing them means the Back button and long-open tabs keep
    // working too, not just scripted posts.
    var inputs = document.querySelectorAll('input[name="' + state.name + '"]');
    for (var i = 0; i < inputs.length; i++) inputs[i].value = hash;
  }

  var inflight = null;

  /** Current token, fetching one if we have never seen it. */
  function token(force) {
    if (state.hash && !force) return Promise.resolve(state.hash);
    if (inflight) return inflight;

    inflight = fetch(state.endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (body) {
        var data = body && body.data ? body.data : null;
        if (data) adopt(data.name, data.hash);
        return state.hash;
      })
      .catch(function () { return state.hash; })
      .then(function (value) { inflight = null; return value; });

    return inflight;
  }

  function sameOrigin(url) {
    try {
      return new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) {
      return false;
    }
  }

  /** A response may carry a rotated token; keep up with it. */
  function harvest(response) {
    if (!response) return response;
    var fresh = response.headers && response.headers.get
      ? response.headers.get('X-CSRF-TOKEN')
      : null;
    if (fresh) adopt(state.name, fresh);
    return response;
  }

  /* ----------------------------- fetch ---------------------------------- */

  var nativeFetch = window.fetch ? window.fetch.bind(window) : null;

  if (nativeFetch) {
    window.fetch = function (input, init) {
      init = init || {};
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var method = (init.method || (input && input.method) || 'GET').toUpperCase();

      if (!UNSAFE.test(method) || !sameOrigin(url)) {
        return nativeFetch(input, init).then(harvest);
      }

      return token(false).then(function (value) {
        var headers = new Headers(init.headers || (input && input.headers) || {});
        if (value && !headers.has(TOKEN_HEADER)) headers.set(TOKEN_HEADER, value);
        if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest');

        var options = Object.assign({}, init, { headers: headers });
        if (!options.credentials) options.credentials = 'same-origin';

        return nativeFetch(input, options).then(function (response) {
          harvest(response);

          // 419 = the token expired between render and send. That is a
          // mechanical failure, not something to bother the customer with:
          // take the fresh token the server returned and send it once more.
          if (response.status !== 419) return response;

          return response.clone().json().catch(function () { return null; })
            .then(function (body) {
              if (body && body.csrf && body.csrf.hash) {
                adopt(body.csrf.name, body.csrf.hash);
              } else {
                return token(true);
              }
            })
            .then(function () {
              if (!state.hash) return response;
              var retryHeaders = new Headers(options.headers);
              retryHeaders.set(TOKEN_HEADER, state.hash);
              return nativeFetch(input, Object.assign({}, options, { headers: retryHeaders }))
                .then(harvest);
            });
        });
      });
    };
  }

  /* ------------------------------- XHR ---------------------------------- */

  var open = XMLHttpRequest.prototype.open;
  var send = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.open = function (method, url) {
    this.__windels = { method: method, url: url };
    return open.apply(this, arguments);
  };

  XMLHttpRequest.prototype.send = function (body) {
    var info = this.__windels;
    var xhr = this;
    if (info && UNSAFE.test(info.method || '') && sameOrigin(info.url || '')) {
      if (state.hash) {
        xhr.setRequestHeader(TOKEN_HEADER, state.hash);
        return send.call(xhr, body);
      }
      // No token yet: fetch one, then send. Async by necessity, which is why
      // the header is set inside the callback rather than before send().
      token(false).then(function (value) {
        if (value) {
          try { xhr.setRequestHeader(TOKEN_HEADER, value); } catch (e) { /* already sent */ }
        }
        send.call(xhr, body);
      });
      return undefined;
    }
    return send.call(xhr, body);
  };

  /* ----------------------------- public API ------------------------------ */

  window.WINDELS = window.WINDELS || {};
  window.WINDELS.csrf = function (force) { return token(!!force); };
  window.WINDELS.csrfHeader = function () {
    var headers = {};
    if (state.hash) headers[TOKEN_HEADER] = state.hash;
    return headers;
  };
  window.WINDELS.csrfField = function () {
    var field = {};
    if (state.hash) field[state.name] = state.hash;
    return field;
  };

  document.addEventListener('DOMContentLoaded', function () {
    // A page restored from the back/forward cache carries the token it was
    // rendered with, which may have been retired in the meantime.
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) token(true);
    });
  });
})();
