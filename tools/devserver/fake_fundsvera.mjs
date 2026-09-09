/**
 * fake_fundsvera.mjs — a faithful fake of Fundsvera's v1 API for dev/e2e.
 *
 * DEV TOOLING ONLY. Implements exactly what fundsvera.co/docs documents for
 * the panel's half of the contract:
 *
 *   POST /api/v1/secured-checkout
 *     headers: Authorization: Bearer <secret>, Public-Key: <public>
 *     body:    customer_email, customer_name, amount, request_id, redirect_url,
 *              customer_phone?
 *     200:     { status:'Pending', message, bank_name, account_name,
 *               account_number, validity, request_id, trx_ref, checkout_url, ... }
 *     400:     documented validation messages
 *     401:     'Unauthorized request please use valid keys'
 *     500:     'System busy please try again later'
 *
 * Failure injection (sticky until changed): POST /__control/behavior
 *   { behavior: 'ok'|'unauthorized'|'bad-request'|'busy'|'hang'|'no-checkout-url'|'duplicate' }
 *
 * The checkout URL served by this fake renders a tiny "card page" the e2e can
 * follow, mirroring the redirect the customer's browser takes in production.
 *
 *   node tools/devserver/fake_fundsvera.mjs --port 9410
 */
import http from 'node:http';

const argv = process.argv.slice(2);
const PORT = (() => {
  const i = argv.indexOf('--port');
  return i === -1 ? 9410 : parseInt(argv[i + 1], 10);
})();

const SECRET = process.env.FAKE_FV_SECRET || 'fv-secret-for-dev-only';
const PUBLIC = process.env.FAKE_FV_PUBLIC || 'pk_dev_fake';

const state = {
  behavior: 'ok',
  requests: [],
  checkouts: new Map(), // request_id -> checkout payload
};

function json(res, code, body) {
  const text = JSON.stringify(body);
  res.writeHead(code, { 'content-type': 'application/json' });
  res.end(text);
}
function text(res, code, body) {
  res.writeHead(code, { 'content-type': 'text/plain' });
  res.end(body);
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
  const method = req.method;
  const path = url.pathname;
  let raw = '';
  req.on('data', (c) => { raw += c; });
  req.on('end', () => {
    state.requests.push({
      method, path, hasAuth: /^Bearer /i.test(req.headers.authorization || ''),
      hasPublicKey: !!(req.headers['public-key'] || req.headers['Public-Key']),
    });

    if (method === 'POST' && path === '/__control/behavior') {
      try {
        const b = JSON.parse(raw || '{}').behavior;
        if (['ok', 'unauthorized', 'bad-request', 'busy', 'hang', 'no-checkout-url', 'duplicate',
            'nested', 'nested-no-details', 'nested-camel']
          .includes(b)) state.behavior = b;
      } catch {}
      return json(res, 200, { behavior: state.behavior });
    }

    if (method === 'GET' && path === '/__stats') {
      return json(res, 200, { requests: state.requests, checkouts: state.checkouts.size });
    }

    // The checkout page the customer's browser lands on ("the card page").
    if (method === 'GET' && path === '/secured-checkout') {
      const ref = url.searchParams.get('ref') || '';
      res.writeHead(200, { 'content-type': 'text/html' });
      res.end(`<!doctype html><title>Fundsvera — secure checkout</title>
<h1>Fundsvera secure checkout</h1><p>Card page for <code>${ref}</code>.</p>
<p>Transfer within 30 minutes. This fake has no payment form.</p>`);
      return;
    }

    // The hosted card fallback (Paystack-compatible) used by the Fundsvera
    // deposit page. The panel's card_checkout() re-uses the SAME transaction
    // through this endpoint, so the e2e can assert the deposit page card CTA
    // produces a real provider checkout and the resulting webhook credits the
    // deposit exactly once.
    if (method === 'POST' && path === '/transaction/initialize') {
      if (!/^Bearer /i.test(req.headers.authorization || '')) {
        return json(res, 401, { status: false, message: 'Unauthorized' });
      }
      let body = {};
      try { body = JSON.parse(raw || '{}'); } catch {}
      const reference = String(body.reference || '');
      const authUrl = `http://127.0.0.1:${PORT}/fake-card-checkout?ref=${encodeURIComponent(reference)}`;
      return json(res, 200, {
        status: true,
        data: {
          reference,
          access_code: 'access-dev-' + reference.slice(-8),
          authorization_url: authUrl,
        },
      });
    }

    // The customer's browser lands here after Paystack "initialize"; it only
    // needs to render so the redirect target is real.
    if (method === 'GET' && path === '/fake-card-checkout') {
      res.writeHead(200, { 'content-type': 'text/html' });
      res.end(`<!doctype html><title>Fake card checkout</title>
<h1>Fake card checkout</h1><p>Reference <code>${url.searchParams.get('ref') || ''}</code>.</p>`);
      return;
    }

    if (method === 'POST' && path === '/api/v1/secured-checkout') {
      const auth = req.headers.authorization || '';
      const pub = req.headers['public-key'] || req.headers['Public-Key'] || '';
      if (!auth.startsWith('Bearer ') || pub !== PUBLIC) {
        return text(res, 401, 'Unauthorized request please use valid keys');
      }

      let body = {};
      try { body = JSON.parse(raw || '{}'); } catch {}

      const behavior = state.behavior; // sticky, like the 5sim fake
      if (behavior === 'unauthorized') {
        return text(res, 401, 'Unauthorized request please use valid keys');
      }
      if (behavior === 'hang') {
        setTimeout(() => { try { text(res, 200, '{}'); } catch {} }, 45000); // never in time
        return;
      }
      if (behavior === 'busy') {
        return json(res, 500, { message: 'System busy please try again later' });
      }
      if (behavior === 'bad-request') {
        return json(res, 400, { message: 'Please input valid amount greater than or equal to 100' });
      }

      // The provider's documented validations.
      if (!body.customer_email || !String(body.customer_email).includes('@')) {
        return json(res, 400, { message: 'Please input valid customer email' });
      }
      if (!body.customer_name || !/^[A-Za-z0-9 _\-]+$/.test(String(body.customer_name))) {
        return json(res, 400, { message: 'Please input valid customer name' });
      }
      if (!body.amount || Number(body.amount) < 100) {
        return json(res, 400, { message: 'Please input valid amount greater than or equal to 100' });
      }
      if (!body.request_id || String(body.request_id).length < 20) {
        return json(res, 400, { message: 'request_id must be at least 20 characters' });
      }
      if (!body.redirect_url || !/^https?:\/\//i.test(String(body.redirect_url))) {
        return json(res, 400, { message: 'redirect_url must start with http or https' });
      }
      if (String(body.redirect_url).includes('?')) {
        return json(res, 400, { message: 'redirect_url must not contain query parameters' });
      }

      if (behavior === 'duplicate' || state.checkouts.has(body.request_id)) {
        return json(res, 400, { message: 'Duplicate request ID, please use a unique request ID' });
      }

      const trx = 'Tref-' + Math.random().toString(36).slice(2, 10);
      const account = '81' + String(10000000 + Math.floor(Math.random() * 89999999));
      const checkout = {
        status: 'Pending',
        message: 'Account details generated successfully',
        customer_email: body.customer_email,
        customer_name: body.customer_name,
        bank_name: 'Palmpay',
        account_name: 'Fundsvera / Merchant',
        account_number: account,
        validity: '30 minutes',
        request_id: body.request_id,
        trx_ref: trx,
        checkout_url: `http://127.0.0.1:${PORT}/secured-checkout?ref=${encodeURIComponent(trx)}&sig=dev`,
        business_name: 'Merchant',
        business_email: 'merchant@example.com',
      };
      if (behavior === 'no-checkout-url') {
        delete checkout.checkout_url; // the "success without a link" case
      }
      // The production API has been observed answering with the details wrapped
      // in a top-level `data` object (rather than flat), and sometimes with
      // camelCase spelling. `nested` / `nested-camel` reproduce those so the
      // panel's normalisation is exercised, and `nested-no-details` wraps an
      // empty body to prove the customer is never stranded.
      if (behavior === 'nested' || behavior === 'nested-no-details' || behavior === 'nested-camel') {
        let payload = checkout;
        if (behavior === 'nested-camel') {
          payload = {
            status: 'Pending', message: 'Account details generated successfully',
            customer_email: checkout.customer_email, customer_name: checkout.customer_name,
            bankName: checkout.bank_name, accountName: checkout.account_name,
            accountNumber: checkout.account_number, validity: checkout.validity,
            request_id: checkout.request_id, trxRef: checkout.trx_ref,
            checkoutUrl: checkout.checkout_url,
            business_name: checkout.business_name, business_email: checkout.business_email,
          };
        }
        const wrapped = { status: 'SUCCESS', message: 'Account details generated successfully',
                          data: behavior === 'nested-no-details' ? { status: 'Pending' } : payload };
        state.checkouts.set(String(body.request_id), wrapped);
        return json(res, 200, wrapped);
      }
      state.checkouts.set(String(body.request_id), checkout);
      return json(res, 200, checkout);
    }

    text(res, 404, 'not found');
  });
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`[fake-fundsvera] Fundsvera v1 fake on http://127.0.0.1:${PORT}`);
  console.log(`[fake-fundsvera] POST /api/v1/secured-checkout; /__control/behavior; /__stats`);
  console.log(`[fake-fundsvera] secret=${SECRET} public=${PUBLIC}`);
});
