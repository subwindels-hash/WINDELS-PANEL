/**
 * fake_smm_panel.mjs — a stand-in "SMM panel API v2" for end-to-end checks.
 *
 * DEV TOOLING ONLY. Runs as its own process so the checking script can block
 * on synchronous PHP CLI runs while this keeps answering.
 *
 * Implements the subset every reseller panel exposes, including the way they
 * really behave: an error is HTTP 200 with `{"error": "..."}`, and a status
 * batch over 100 ids is refused outright.
 *
 * Control endpoints (not part of the panel API):
 *   GET  /__state            → { calls, orders, mode }
 *   POST /__state            ← { mode?, orders? }  (JSON) to script behaviour
 *   POST /__reset            ← clears the recorded calls
 *
 *   node tools/devserver/fake_smm_panel.mjs --port 8099 --key <api-key>
 */
import http from 'node:http';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const PORT = parseInt(arg('--port', '8099'), 10);
const KEY = arg('--key', 'good-key');

const state = {
  mode: 'normal',                  // normal | maintenance
  orders: {},                      // id -> { status, remains }
  calls: [],
  // Refill behaviour is scriptable because the refill lifecycle is exactly
  // where the panel used to lie to customers: a refusal has to close the
  // refill, an unanswered one has to be re-sent, and neither may be reported
  // as "requested" and then forgotten.
  refill: 'accept',                // accept | refuse
  refillStatus: 'Completed',       // whatever the panel reports for refill_status
  cancel: 'accept',                // accept | refuse
};

function readBody(req) {
  return new Promise((resolve) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => resolve(body));
  });
}

const server = http.createServer(async (req, res) => {
  const body = await readBody(req);
  const json = (payload, code = 200) => {
    res.writeHead(code, { 'content-type': 'application/json' });
    res.end(JSON.stringify(payload));
  };

  if (req.url.startsWith('/__state')) {
    if (req.method === 'POST') {
      const patch = JSON.parse(body || '{}');
      if (patch.mode) state.mode = patch.mode;
      if (patch.refill) state.refill = patch.refill;
      if (patch.refillStatus) state.refillStatus = patch.refillStatus;
      if (patch.cancel) state.cancel = patch.cancel;
      if (patch.orders) Object.assign(state.orders, patch.orders);
      return json({ ok: true });
    }
    return json(state);
  }
  if (req.url.startsWith('/__reset')) {
    state.calls = [];
    return json({ ok: true });
  }

  const params = new URLSearchParams(body);
  const action = params.get('action');
  state.calls.push({ action, orders: params.get('orders'), order: params.get('order') });

  if (state.mode === 'maintenance') {
    res.writeHead(200, { 'content-type': 'text/html' });
    return res.end('<html><body>We are upgrading. Back soon.</body></html>');
  }

  // How a real panel refuses: HTTP 200 with an error envelope.
  if (params.get('key') !== KEY) return json({ error: 'Incorrect API key' });

  if (action === 'balance') return json({ balance: '250.75', currency: 'USD' });

  if (action === 'services') {
    return json([
      { service: '101', name: 'Instagram Followers [Real]', type: 'Default', category: 'Instagram',
        rate: '0.90', min: '100', max: '100000', refill: true, cancel: true },
      { service: '102', name: 'TikTok Views', type: 'Default', category: 'TikTok',
        rate: '0.05', min: '1000', max: '1000000', refill: false, cancel: true },
    ]);
  }

  if (action === 'add') {
    const id = String(9000 + Object.keys(state.orders).length);
    state.orders[id] = { status: 'In progress', remains: String(params.get('quantity') || 0) };
    return json({ order: Number(id), charge: '0.90', currency: 'USD' });
  }

  if (action === 'status') {
    const single = params.get('order');
    if (single) {
      const o = state.orders[String(single)];
      return json(o ? { status: o.status, charge: '0.90', start_count: '10', remains: o.remains }
                    : { error: 'Incorrect order ID' });
    }
    const ids = (params.get('orders') || '').split(',').filter(Boolean);
    // Oversized batches are refused for the WHOLE batch — this is what makes
    // chunking mandatory rather than an optimisation.
    if (ids.length > 100) return json({ error: 'Too many orders' });
    const out = {};
    for (const id of ids) {
      const o = state.orders[String(id)];
      out[id] = o ? { status: o.status, charge: '0.90', start_count: '10', remains: o.remains }
                  : { error: 'Incorrect order ID' };
    }
    return json(out);
  }

  if (action === 'refill') {
    if (params.get('order') === 'unknown') return json({ error: 'Incorrect order ID' });
    return state.refill === 'refuse'
      ? json({ error: 'Refill not available for this order' })
      : json({ refill: 555 });
  }
  if (action === 'refill_status') return json({ status: state.refillStatus });
  if (action === 'cancel') {
    if (params.get('orders') === 'unknown') {
      return json([{ order: 'unknown', cancel: { error: 'Incorrect order ID' } }]);
    }
    if (state.cancel === 'refuse') {
      return json([{ order: params.get('orders'), cancel: { error: 'Order already in progress' } }]);
    }
    return json([{ order: Number(params.get('orders')), cancel: 1 }]);
  }

  return json({ error: 'Incorrect request' });
});

// Refuse to share a port. An abandoned panel from an earlier run answers with
// a DIFFERENT api key, so the checker's calls come back "Incorrect API key"
// and the failure looks like an adapter bug — which cost a debugging session
// once already.
server.on('error', (err) => {
  if (err.code === 'EADDRINUSE') {
    console.error(`fake smm panel: port ${PORT} is already in use — another panel is still `
                + `running (pkill -f fake_smm_panel) or something else has the port.`);
    process.exit(3);
  }
  throw err;
});
server.listen(PORT, '127.0.0.1', () => console.log(`fake smm panel on 127.0.0.1:${PORT}`));
