/**
 * fake_smtp.mjs — a scripted SMTP server for pinning the queue's 503 failure.
 *
 * DEV TOOLING ONLY. Speaks just enough SMTP for CI3's Email client:
 * EHLO/HELO, AUTH LOGIN/PLAIN (accepts any credentials), MAIL FROM, RCPT TO,
 * DATA, RSET, QUIT — and records every line of every conversation so the
 * probe can assert on the exact command order.
 *
 * Failure behaviours (sticky): POST /__control/behavior {behavior}
 *   ok                  normal SMTP; messages are accepted
 *   greet-then-503      EHLO answered 250, then MAIL FROM → "503 HELO or
 *                       EHLO required" and the connection closes — the
 *                       production failure shape
 *   strict-helo         EHLO → "500 Command unrecognized"; HELO → 250.
 *                       A client without the RFC 5321 fallback dies here.
 *
 * GET /__stats → the full transcript of the last conversation.
 *
 *   node tools/devserver/fake_smtp.mjs --port 9325
 */
import net from 'node:net';

const argv = process.argv.slice(2);
const PORT = (() => {
  const i = argv.indexOf('--port');
  return i === -1 ? 9325 : parseInt(argv[i + 1], 10);
})();

const state = { behavior: 'ok', transcript: [] };

const server = net.createServer((socket) => {
  socket.setEncoding('utf8');
  let buf = '';
  let inData = false;
  let authStage = 0;
  const log = (line) => state.transcript.push(line.trim());
  const say = (line) => { log('S: ' + line); socket.write(line + '\r\n'); };

  say(`220 fake-smtp.local ESMTP Exim 4.99.5 — dev fake`);

  socket.on('data', (chunk) => {
    buf += chunk;
    let idx;
    while ((idx = buf.indexOf('\r\n')) !== -1) {
      const rawLine = buf.slice(0, idx);
      buf = buf.slice(idx + 2);
      handleLine(rawLine);
    }
  });
  socket.on('error', () => {});

  function handleLine(line) {
    if (line === '.') { inData = false; return say('250 OK: queued as fake-id'); }
    if (inData) return; // absorb body
    log('C: ' + line);

    const upper = line.toUpperCase();
    const cmd = upper.split(' ')[0];

    if (cmd === 'EHLO' || cmd === 'HELO') {
      if (state.behavior === 'strict-helo' && cmd === 'EHLO') {
        return say('500 5.5.1 Command unrecognized');
      }
      return say('250-fake-smtp.local Hello ' + line.split(' ')[1] + '\r\n'
        + '250-SIZE 52428800\r\n250-8BITMIME\r\n250-PIPELINING\r\n250 HELP');
    }
    if (cmd === 'MAIL') {
      if (state.behavior === 'greet-then-503') {
        say('503 HELO or EHLO required');
        return socket.end();
      }
      return say('250 OK');
    }
    if (cmd === 'RCPT') return say('250 Accepted');
    if (cmd === 'DATA') { inData = true; return say('354 Enter message, ending with "."'); }
    if (cmd === 'AUTH') {
      authStage = 1;
      return say('334 VXNlcm5hbWU6');
    }
    if (authStage > 0) { // b64 username, then b64 password
      authStage += 1;
      if (authStage === 3) { authStage = 0; return say('235 Authentication succeeded'); }
      return say('334 ' + (authStage === 2 ? 'UGFzc3dvcmQ6' : 'VXNlcm5hbWU6'));
    }
    if (cmd === 'STARTTLS') return say('454 TLS not available');
    if (cmd === 'RSET') return say('250 Reset OK');
    if (cmd === 'QUIT') { say('221 fake-smtp.local closing connection'); return socket.end(); }
    return say('502 Command not implemented');
  }
});

const http = (await import('node:http')).default;
const ctl = http.createServer((req, res) => {
  let raw = '';
  req.on('data', (c) => raw += c);
  req.on('end', () => {
    if (req.method === 'POST' && req.url === '/__control/behavior') {
      try {
        const b = JSON.parse(raw || '{}').behavior;
        if (['ok', 'greet-then-503', 'strict-helo'].includes(b)) state.behavior = b;
      } catch {}
      res.writeHead(200, { 'content-type': 'application/json' });
      return res.end(JSON.stringify({ behavior: state.behavior }));
    }
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ behavior: state.behavior, transcript: state.transcript }));
  });
});

server.listen(PORT, '127.0.0.1', () => console.log(`[fake-smtp] SMTP fake on 127.0.0.1:${PORT}`));
ctl.listen(PORT + 1, '127.0.0.1', () => console.log(`[fake-smtp] control on 127.0.0.1:${PORT + 1}`));
