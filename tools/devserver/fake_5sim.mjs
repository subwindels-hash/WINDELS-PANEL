/**
 * Fake 5sim current-protocol (v1) server for local/staging verification.
 *
 * DEV TOOLING ONLY. Implements exactly the surface of the CURRENT 5sim API
 * documented at https://5sim.net/docs — GET /v1/... with Bearer auth — so the
 * real FiveSimAdapter can be exercised end to end without vendor credentials:
 *
 *   node tools/devserver/fake_5sim.mjs --port 9400
 *
 * The panel is pointed at it in a NON-production environment with
 * FIVESIM_BASE_URL=http://127.0.0.1:9400/v1 (FiveSimAdapter refuses that
 * variable in production). The deprecated API1 protocol (handler_api.php,
 * /stubs/) is deliberately NOT implemented: a request for it returns the
 * vendor's real-world behaviour — HTTP 404.
 *
 * Failure injection: POST /__control/behavior {behavior: "ok"|"no-stock"|
 * "insufficient"|"timeout"|"server-error"} changes what the NEXT buy returns.
 */
import http from 'node:http';

const argv = process.argv.slice(2);
const PORT = parseInt((() => { const i = argv.indexOf('--port'); return i === -1 ? '9400' : argv[i + 1]; })(), 10);

const COUNTRIES = {
  nigeria: ['any', 'mtn', 'airtel'],
  ghana: ['any', 'mtn'],
  kenya: ['any', 'safaricom'],
  southafrica: ['any', 'vodacom'],
  england: ['any', 'vodafone', 'ee'],
  usa: ['any', 'virtual38'],
  india: ['any', 'airtel'],
};

const PRODUCTS = {
  whatsapp: { Category: 'activation', Qty: 25, Price: 4 },
  telegram: { Category: 'activation', Qty: 8, Price: 6 },
  facebook: { Category: 'activation', Qty: 12, Price: 5 },
  instagram: { Category: 'activation', Qty: 0, Price: 9 },
  google: { Category: 'activation', Qty: 40, Price: 3 },
  twitter: { Category: 'activation', Qty: 15, Price: 4.5 },
  tiktok: { Category: 'activation', Qty: 20, Price: 2.5 },
  discord: { Category: 'activation', Qty: 30, Price: 3.5 },
  uber: { Category: 'activation', Qty: 5, Price: 12 },
  amazon: { Category: 'activation', Qty: 18, Price: 4.2 },
  other: { Category: 'activation', Qty: 50, Price: 1.8 },
  '3hours': { Category: 'hosting', Qty: 7, Price: 20 },
};

const state = {
  behavior: 'ok',
  orders: new Map(),
  nextId: 1000,
  requests: [],
};

function iso(date) { return date.toISOString(); }

function smsBody(seed) {
  return [{
    id: seed + 500,
    created_at: iso(new Date()),
    date: iso(new Date()),
    sender: 'WhatsApp',
    text: `WhatsApp: ${seed} - use this code to verify your account.`,
    code: String(seed),
  }];
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  const method = req.method;
  const path = url.pathname;
  const auth = String(req.headers.authorization || '');
  state.requests.push({ method, path: path + url.search, hasAuth: auth.startsWith('Bearer ') });

  const json = (code, body) => {
    res.writeHead(code, { 'content-type': 'application/json' });
    res.end(JSON.stringify(body));
  };
  const text = (code, body) => {
    res.writeHead(code, { 'content-type': 'text/plain' });
    res.end(body);
  };

  // ---- control plane -----------------------------------------------------
  if (method === 'POST' && path === '/__control/behavior') {
    let raw = '';
    req.on('data', (c) => { raw += c; });
    req.on('end', () => {
      try {
        const b = JSON.parse(raw || '{}').behavior;
        if (['ok', 'no-stock', 'insufficient', 'timeout', 'server-error'].includes(b)) state.behavior = b;
      } catch {}
      json(200, { behavior: state.behavior });
    });
    return;
  }

  if (method === 'GET' && path === '/__stats') {
    return json(200, { requests: state.requests, orders: state.orders.size });
  }

  // ---- deprecated API1 must NOT exist (matches the real vendor) ---------
  if (/handler_api|stubs/i.test(path)) {
    text(404, '404 Not Found');
    return;
  }

  // ---- current protocol --------------------------------------------------
  const m = path.match(/^\/v1(\/.*)$/);
  if (!m) { text(404, 'Not Found'); return; }
  const p = m[1];

  if (method === 'GET' && p === '/user/profile') {
    if (!auth.startsWith('Bearer ')) { text(401, 'Unauthorized'); return; }
    return json(200, {
      id: 4481800,
      email: 'operator@example.test',
      vendor: 'demo',
      balance: 1000,
      rating: 96,
      default_country: { name: 'nigeria', iso: 'ng', prefix: '+234' },
      default_operator: { name: 'any' },
      frozen_balance: 0,
    });
  }

  if (method === 'GET' && p === '/guest/countries') {
    return json(200, COUNTRIES);
  }

  if (method === 'GET' && p === '/guest/prices') {
    const country = url.searchParams.get('country') || null;
    const out = {};
    for (const [c, operators] of Object.entries(COUNTRIES)) {
      if (country && c !== country) continue;
      out[c] = {};
      for (const [slug, info] of Object.entries(PRODUCTS)) {
        if (info.Category !== 'activation') continue;
        out[c][slug] = {};
        for (const op of operators) {
          out[c][slug][op] = { cost: info.Price, count: info.Qty, rate: 98.5 };
        }
      }
    }
    return json(200, out);
  }

  const prodMatch = p.match(/^\/guest\/products\/([^/]+)\/([^/]+)$/);
  if (method === 'GET' && prodMatch) {
    const [, country, operator] = prodMatch;
    if (!COUNTRIES[country]) { text(400, 'bad country'); return; }
    return json(200, PRODUCTS);
  }

  const buyMatch = p.match(/^\/user\/buy\/activation\/([^/]+)\/([^/]+)\/([^/]+)$/);
  if (method === 'GET' && buyMatch) {
    const [, country, operator, product] = buyMatch;
    if (!auth.startsWith('Bearer ')) { text(401, 'Unauthorized'); return; }
    if (!COUNTRIES[country]) { text(400, 'bad country'); return; }
    if (!PRODUCTS[product] || PRODUCTS[product].Category !== 'activation') { text(400, 'no product'); return; }

    // Sticky until changed via /__control/behavior — a vendor failure should
    // keep failing while the panel retries, or the panel would "heal" it.
    const behavior = state.behavior;
    if (behavior === 'no-stock') { text(200, 'no free phones'); return; }
    if (behavior === 'insufficient') { text(400, 'not enough user balance'); return; }
    if (behavior === 'timeout') {
      setTimeout(() => { try { text(200, '{}'); } catch {} }, 40000); // never in time
      return;
    }
    if (behavior === 'server-error') { text(500, 'internal error'); return; }

    const id = state.nextId++;
    const phone = country === 'nigeria' ? `+23490${String(id).padStart(7, '0')}` : `+44${String(id).padStart(8, '0')}`;
    const order = {
      id,
      phone,
      operator: operator === 'any' ? (COUNTRIES[country][1] || 'any') : operator,
      product,
      price: PRODUCTS[product].Price,
      status: 'PENDING',
      expires: iso(new Date(Date.now() + 15 * 60000)),
      sms: [],
      created_at: iso(new Date()),
      forwarding: false,
      forwarding_number: '',
      country,
      pollCount: 0,
      smsSeed: 100000 + Math.floor(Math.random() * 800000),
    };
    state.orders.set(String(id), order);
    const { pollCount, smsSeed, ...publicOrder } = order;
    return json(200, publicOrder);
  }

  const orderMatch = p.match(/^\/user\/(check|finish|cancel|ban)\/(\d+)$/);
  if (method === 'GET' && orderMatch) {
    const [, action, id] = orderMatch;
    if (!auth.startsWith('Bearer ')) { text(401, 'Unauthorized'); return; }
    const order = state.orders.get(id);
    if (!order) { text(200, 'order not found'); return; }
    if (action === 'check') {
      order.pollCount += 1;
      // The second poll delivers the code for whatsapp; telegram stays silent.
      const deliver = order.product === 'whatsapp' && order.pollCount >= 2;
      if (deliver) {
        order.status = 'RECEIVED';
        order.sms = smsBody(order.smsSeed);
      }
    } else if (action === 'finish') {
      order.status = 'FINISHED';
    } else if (action === 'cancel') {
      if (order.sms.length) { text(200, 'order has sms'); return; }
      order.status = 'CANCELED';
    } else if (action === 'ban') {
      order.status = 'BANNED';
    }
    const { pollCount, smsSeed, ...publicOrder } = order;
    return json(200, publicOrder);
  }

  text(404, 'Not Found');
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`[fake-5sim] current-protocol v1 server on http://127.0.0.1:${PORT}`);
  console.log(`[fake-5sim] /v1/user/profile, /v1/guest/countries, /v1/guest/prices, /v1/guest/products/…, /v1/user/buy|check|finish|cancel|ban`);
  console.log(`[fake-5sim] handler_api.php / /stubs/ → 404 (like the real vendor)`);
});
