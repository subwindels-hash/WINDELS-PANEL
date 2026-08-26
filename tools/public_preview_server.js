/**
 * Visual preview of the public MarvySocials site.
 * Serves the real design-system CSS / app.js and static HTML that mirrors
 * the PHP views, plus a local /assistant/chat that uses the same knowledge.
 * Not a replacement for the CodeIgniter app.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const ROOT = path.join(__dirname, '..');
const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';

const faqs = [
  ['General', 'What is MarvySocials?', 'A prepaid reseller platform for SMM, Nigerian VTU, virtual numbers, identity lookups, gift cards and a platform-owned marketplace. The wallet is a spending balance — there are no customer withdrawals.'],
  ['General', 'Do I need a subscription?', 'No. Accounts are free. You pay published rates from a prepaid wallet. There is no public monthly SaaS plan.'],
  ['Accounts', 'How do I create an account?', 'Register with a username, email and password (at least 8 characters) and accept the Terms. A wallet is created automatically.'],
  ['Accounts', 'I forgot my password.', 'Use Forgot password. If an account matches we email a reset link. The confirmation is the same either way so addresses cannot be probed.'],
  ['Services', 'What can I order?', 'The live SMM catalogue is on /services. Signed-in customers can also use VTU, numbers, identity, gift cards and the marketplace when those products are priced.'],
  ['Pricing and billing', 'How does pricing work?', 'You add funds and pay the published rate for each service. Volume groups (Silver, Gold, Reseller) are assigned by staff. Default deposit minimum is ₦500.'],
  ['Pricing and billing', 'Can I withdraw wallet funds?', 'No. The wallet is a spending balance for purchases on this panel.'],
  ['Security', 'Is the site assistant a cloud AI?', 'No. It is an embedded operational engine. It does not call a third-party AI API and cannot place orders.'],
  ['Technical support', 'How do I contact support?', 'Use the contact form. Signed-in customers get a ticket. Include the public order ID.'],
  ['Administrators', 'Where do staff sign in?', 'Use /admin/login. Customer accounts are refused after the password check.'],
];

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function layout(title, desc, body, extra = '') {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)} · MarvySocials</title>
<meta name="description" content="${esc(desc)}">
<link rel="canonical" href="/">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(desc)}">
<meta name="csrf-name" content="csrf_marvy">
<meta name="csrf-token" content="preview">
<meta name="csrf-endpoint" content="/csrf">
<link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body>
<a class="ws-skip" href="#main">Skip to content</a>
<div class="ws-announce" role="region" aria-label="Announcements" tabindex="0" data-announce data-announce-interval="9000">
  <div class="ws-announce-viewport">
    <div class="ws-announce-slides">
      <div class="ws-announce-slide is-active">Prepaid wallet: add funds and spend on services — leftover deposits cannot be withdrawn.</div>
      <div class="ws-announce-slide">New here? Create an account, then browse Services or read Pricing.</div>
      <div class="ws-announce-slide">Need help? Open the FAQ, send a Contact message, or ask the on-site assistant.</div>
      <div class="ws-announce-slide">Staff sign in at Admin login. Customer passwords cannot open the back office.</div>
    </div>
  </div>
  <div class="ws-announce-dots" data-announce-dots aria-hidden="true"></div>
</div>
<nav class="ws-public-nav ws-sticky-below-announce" aria-label="Primary">
  <div class="ws-public-nav-inner">
    <a class="ws-brand" href="/"><img class="ws-logo" src="/assets/brand/logo-horizontal.svg" alt="MarvySocials" height="32" width="240"></a>
    <div class="ws-nav-links">
      <a href="/services">Services</a><a href="/pricing">Pricing</a><a href="/faq">FAQ</a><a href="/blog">Blog</a><a href="/contact">Contact</a>
    </div>
    <div class="ws-nav-actions">
      <div class="ws-nav-desktop row" style="gap:.4rem">
        <a class="btn btn-ghost btn-sm" href="/login">Log in</a>
        <a class="btn btn-primary btn-sm" href="/register">Create account</a>
      </div>
      <button type="button" class="ws-nav-toggle" data-nav-toggle aria-controls="ws-nav-panel" aria-expanded="false">Menu</button>
    </div>
  </div>
  <div id="ws-nav-panel" class="ws-nav-panel" hidden>
    <a href="/services">Services</a><a href="/pricing">Pricing</a><a href="/faq">FAQ</a>
    <a href="/blog">Blog</a><a href="/contact">Contact</a><a href="/about">About</a>
    <a href="/login">Log in</a><a href="/register">Create account</a>
  </div>
</nav>
<main id="main">${body}</main>
<footer class="ws-footer">
  <div class="container">
    <div class="ws-footer-grid">
      <div>
        <a class="ws-brand" href="/"><img class="ws-logo" src="/assets/brand/logo.svg" alt="MarvySocials" height="40" width="176"></a>
        <p class="muted mt-2">A prepaid reseller panel for social-media services, Nigerian VTU, virtual numbers, identity checks, gift cards and a platform-owned marketplace.</p>
      </div>
      <div><h2>Platform</h2><ul>
        <li><a href="/services">Services</a></li><li><a href="/pricing">Pricing</a></li>
        <li><a href="/faq">FAQ</a></li><li><a href="/api/docs">API documentation</a></li></ul></div>
      <div><h2>Company</h2><ul>
        <li><a href="/about">About</a></li><li><a href="/blog">Blog</a></li>
        <li><a href="/contact">Contact</a></li></ul></div>
      <div><h2>Support</h2><ul>
        <li><a href="/faq">Help centre</a></li><li><a href="/dashboard/tickets">Support tickets</a></li>
        <li><a href="/contact">Contact support</a></li></ul></div>
      <div><h2>Legal</h2><ul>
        <li><a href="/terms">Terms of service</a></li><li><a href="/privacy">Privacy policy</a></li>
        <li><a href="/refund-policy">Refund policy</a></li><li><a href="/acceptable-use">Acceptable use</a></li></ul></div>
    </div>
    <div class="ws-footer-meta">
      <div>© ${new Date().getFullYear()} MarvySocials. Wallet balances are for spending on this platform only.</div>
      <div><a href="/login">Customer login</a> · <a href="/admin/login">Staff login</a></div>
    </div>
  </div>
</footer>
<button type="button" class="ws-assistant-launch" id="ws-assistant-launch" aria-controls="ws-assistant" aria-expanded="false">
  <img src="/assets/images/ai/avatar.jpg" alt="" width="28" height="28" class="ws-avatar" style="width:28px;height:28px"> Assistant
</button>
<section class="ws-assistant" id="ws-assistant" hidden data-endpoint="/assistant/chat" aria-label="Site assistant">
  <header class="ws-assistant-head">
    <div class="row" style="gap:.7rem">
      <img src="/assets/images/ai/avatar.jpg" alt="" width="40" height="40" class="ws-avatar">
      <div><h2>Site assistant</h2><p class="muted" style="margin:0;font-size:.8rem">Built-in knowledge · no external AI API</p></div>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="ws-assistant-close">Close</button>
  </header>
  <div class="ws-assistant-log" id="ws-assistant-log">
    <div class="ws-bubble ws-bubble-assistant">I am MarvySocials’s on-site assistant. I answer from the panel’s built-in knowledge. I am not a cloud generative AI model and I cannot place orders.

Ask about services, pricing, accounts or where to find a page.</div>
  </div>
  <div class="ws-suggest" id="ws-assistant-suggest">
    <button type="button" data-suggest="What services can I order?">What services can I order?</button>
    <button type="button" data-suggest="How does pricing work?">How does pricing work?</button>
    <button type="button" data-suggest="How do I create an account?">How do I create an account?</button>
    <button type="button" data-suggest="How do I add funds?">How do I add funds?</button>
  </div>
  <div class="ws-assistant-status" id="ws-assistant-status" aria-live="polite"></div>
  <form class="ws-assistant-form" id="ws-assistant-form">
    <label class="sr-only" for="ws-assistant-input">Your question</label>
    <input class="input" id="ws-assistant-input" maxlength="1000" placeholder="Ask about the panel…">
    <button class="btn btn-primary" type="submit" id="ws-assistant-send">Send</button>
  </form>
</section>
<script src="/assets/js/app.js"></script>
${extra}
</body></html>`;
}

const pages = {
  '/': () => layout('Prepaid SMM, VTU and digital-goods panel',
    'MarvySocials is a prepaid reseller platform for social-media services, Nigerian VTU, virtual numbers, identity checks, gift cards and a platform marketplace.',
    `<section class="ws-page-hero">
      <div class="container" style="max-width:1180px">
        <div class="ws-hero-split">
          <div>
        <span class="badge badge-brand">Prepaid reseller panel</span>
        <h1 class="mt-4">Grow your social presence<br><span class="gradient-text">with MarvySocials</span></h1>
        <p class="ws-lede">One platform for SMM, VTU and digital goods — automated fulfilment when providers are connected, and a wallet ledger you can audit.</p>
        <div class="row" style="margin-top:1.5rem">
          <a class="btn btn-primary btn-lg" href="/register">Start ordering →</a>
          <a class="btn btn-secondary btn-lg" href="/services">View services</a>
        </div>
          </div>
          <div class="ws-hero-media">
            <img src="/assets/images/home/hero.jpg" alt="Abstract commerce operations hub of glass panels and connected services." width="960" height="720">
          </div>
        </div>
        <div class="grid grid-4 mt-6">
          <div class="card"><strong>Wallet</strong><p class="muted">Prepaid spend only</p></div>
          <div class="card"><strong>Ledger</strong><p class="muted">Double-entry credits</p></div>
          <div class="card"><strong>API</strong><p class="muted">Reseller keys &amp; scopes</p></div>
          <div class="card"><strong>Staff</strong><p class="muted">RBAC back office</p></div>
        </div>
      </div>
    </section>
    <section class="ws-section-sm"><div class="container">
      <h2 class="text-center">How it works</h2>
      <div class="grid grid-4 mt-4">
        <article class="card"><div class="badge badge-brand">01</div><h3 class="card-title">Create an account</h3><p class="muted">Username, email, password. A wallet is created automatically.</p></article>
        <article class="card"><div class="badge badge-brand">02</div><h3 class="card-title">Add funds</h3><p class="muted">Deposit through an enabled method. No customer withdrawals.</p></article>
        <article class="card"><div class="badge badge-brand">03</div><h3 class="card-title">Place an order</h3><p class="muted">Rates freeze at checkout. The ledger records the debit.</p></article>
        <article class="card"><div class="badge badge-brand">04</div><h3 class="card-title">Track and follow up</h3><p class="muted">Refill, cancel or open a ticket when the service allows it.</p></article>
      </div>
    </div></section>
    <section class="ws-section-sm" style="background:var(--slate-50)"><div class="container">
      <h2 class="text-center">Built for resellers, agencies and creators</h2>
      <div class="grid grid-3 mt-4">
        <article class="card"><h3 class="card-title">Agencies</h3><p>One prepaid balance for many orders, with refill and ticket history.</p></article>
        <article class="card"><h3 class="card-title">Resellers</h3><p>The same order engine behind API keys, scopes and IP allowlists.</p></article>
        <article class="card"><h3 class="card-title">Creators</h3><p>Pick a service, freeze the rate, pay from the wallet.</p></article>
      </div>
      <div class="text-center mt-6"><a class="btn btn-primary" href="/register">Ready to scale? Create your account</a></div>
    </div></section>`),

  '/services': () => layout('Services', 'Social-media services plus VTU, virtual numbers, identity checks, gift cards and a platform marketplace.',
    `<section class="ws-page-hero"><div class="container" style="max-width:800px">
      <p class="ws-kicker">What you can buy</p>
      <h1>One wallet for SMM, bills and digital goods</h1>
      <p class="ws-lede">For creators, agencies and resellers who want prepaid checkout and a staff-run back office — not invented volume numbers.</p>
      <div class="row mt-4"><a class="btn btn-primary" href="/register">Create an account</a><a class="btn btn-secondary" href="#catalogue">Jump to catalogue</a></div>
    </div></section>
    <section class="ws-section-sm"><div class="container">
      <h2>Product areas</h2>
      <div class="grid grid-3 mt-4">
        ${[['Social media services','Creators, agencies and resellers','Browse the live SMM catalogue. Rates freeze at checkout.','/services','/assets/images/services/smm.jpg'],
           ['VTU and bills','Nigerian airtime, data and bills','Airtime, data, cable, electricity and exam pins from the wallet.','/login','/assets/images/services/vtu.jpg'],
           ['Virtual numbers','Temporary SMS / OTP','Rent a number after a provider is connected and priced.','/login','/assets/images/services/numbers.jpg'],
           ['Identity verification','NIN and BVN lookups','Hidden until staff set a real vendor price.','/login','/assets/images/services/identity.jpg'],
           ['Gift cards','Digital brands','Encrypted codes, revealed only to authorised viewers.','/login','/assets/images/services/giftcards.jpg'],
           ['Marketplace','Platform-owned listings','The platform is the only seller.','/login','/assets/images/services/marketplace.jpg']].map(([n,a,s,h,img]) =>
          `<article class="card" style="padding-top:0;overflow:hidden"><img class="ws-visual-card" src="${img}" alt="${n}" width="640" height="400" loading="lazy"><h3 class="card-title">${n}</h3><p class="hint">${a}</p><p>${s}</p><a class="btn btn-secondary btn-sm" href="${h}">Open</a></article>`).join('')}
      </div>
    </div></section>
    <section class="py-10" id="catalogue"><div class="container">
      <header class="text-center mb-8"><h2>SMM catalogue</h2><p class="muted">Live rows appear when the operator publishes services. This preview shows the empty state the PHP catalogue uses when the database has none.</p></header>
      <div class="empty-state card"><h3>No services match your filters.</h3><p>Publish services from Admin → SMM services, or clear filters.</p><a class="btn btn-secondary" href="/services">Clear filters</a></div>
    </div></section>`),

  '/pricing': () => layout('Pricing', 'Prepaid wallet pricing. No invented monthly plans.',
    `<section class="ws-page-hero"><div class="container" style="max-width:1100px">
      <div class="ws-hero-split"><div>
      <p class="ws-kicker">Pricing</p><h1>Prepaid wallet. Published rates. No fake plans.</h1>
      <p class="ws-lede">MarvySocials does not sell a public monthly subscription. You add funds and pay the rate shown on each service.</p>
      </div><div class="ws-hero-media"><img src="/assets/images/services/marketplace.jpg" alt="Curated digital storefront representing prepaid catalogue pricing." width="800" height="600"></div></div>
    </div></section>
    <section class="ws-section-sm"><div class="container"><div class="grid grid-3">
      <article class="card ws-plan"><span class="badge badge-success">Available</span><h2 class="card-title mt-2">Pay as you go</h2>
        <p class="muted">Individual customers and new resellers</p>
        <p style="font-size:1.5rem;font-family:var(--font-display)">Service rates</p>
        <p>Free account. Default retail group. Optional API key. No monthly platform fee.</p>
        <a class="btn btn-primary btn-block" href="/register">Create an account</a></article>
      <article class="card ws-plan"><span class="badge badge-brand">Contact sales</span><h2 class="card-title mt-2">Volume groups</h2>
        <p class="muted">Agencies and active resellers</p>
        <p style="font-size:1.5rem;font-family:var(--font-display)">Custom rates</p>
        <p>Silver, Gold and Reseller groups are assigned by staff — not self-serve checkout plans.</p>
        <a class="btn btn-secondary btn-block" href="/contact">Contact sales</a></article>
      <article class="card ws-plan"><span class="badge badge-brand">Contact sales</span><h2 class="card-title mt-2">Custom / operator</h2>
        <p class="muted">Tailored catalogue or process</p>
        <p style="font-size:1.5rem;font-family:var(--font-display)">Contact sales</p>
        <p>Nothing here is a public price commitment.</p>
        <a class="btn btn-secondary btn-block" href="/contact">Contact sales</a></article>
    </div></div></section>`),

  '/faq': () => layout('FAQ', 'Answers about accounts, billing, services and the assistant.',
    `<section class="ws-page-hero"><div class="container" style="max-width:1100px">
      <div class="ws-hero-split"><div>
      <p class="ws-kicker">Help</p><h1>Frequently asked questions</h1>
      <input class="input mt-4" id="ws-faq-search" type="search" placeholder="Search questions…" style="max-width:28rem">
      </div><div class="ws-hero-media"><img src="/assets/images/faq/hero.jpg" alt="Knowledge space of glowing glass cards." width="800" height="600"></div></div>
    </div></section>
    <section class="ws-section-sm"><div class="container" style="max-width:800px">
      ${faqs.map(([c,q,a]) => `<section data-faq-category><h2 style="font-size:1.1rem">${esc(c)}</h2>
        <details class="accordion-item" data-faq-item data-faq-text="${esc((q+' '+a+' '+c).toLowerCase())}">
          <summary>${esc(q)}</summary><div class="accordion-body">${esc(a)}</div></details></section>`).join('')}
      <div class="card text-center mt-6"><p class="muted">Still have a question?</p>
        <a class="btn btn-primary" href="/contact">Contact support</a></div>
    </div></section>`),

  '/about': () => layout('About', 'What MarvySocials is and who it is for.',
    `<section class="ws-page-hero"><div class="container" style="max-width:760px">
      <p class="ws-kicker">About</p><h1>A panel for selling digital fulfilment — not a marketing slogan</h1>
      <p class="ws-lede">MarvySocials is the software that runs this site. It is operated by whoever deployed this instance.</p>
    </div></section>
    <section class="ws-section-sm"><div class="container ws-prose">
      <p>One customer account can buy social-media services, Nigerian VTU, virtual numbers, identity lookups, gift cards and platform-owned marketplace listings — when those products are connected and priced.</p>
      <p>This page does not publish customer counts, star ratings or uptime percentages.</p>
      <a class="btn btn-primary" href="/contact">Contact</a>
    </div></section>`),

  '/contact': () => layout('Contact', 'Contact MarvySocials support.',
    `<section class="ws-page-hero"><div class="container" style="max-width:720px">
      <p class="ws-kicker">Support</p><h1>Contact us</h1>
      <p class="ws-lede">A person answers. The on-site assistant cannot open a ticket for you.</p>
    </div></section>
    <section class="ws-section-sm"><div class="container" style="max-width:640px">
      <div class="card"><form class="stack" method="post" action="/contact">
        <label class="label" for="n">Your name</label><input class="input" id="n" name="name" required>
        <label class="label" for="e">Email</label><input class="input" id="e" name="email" type="email" required>
        <label class="label" for="s">Subject</label><input class="input" id="s" name="subject" required>
        <label class="label" for="m">Message</label><textarea class="textarea" id="m" name="message" required></textarea>
        <button class="btn btn-primary" type="submit">Send message</button>
      </form></div>
    </div></section>`),

  '/terms': () => legal('Terms of Service', ['Acceptance of terms','Eligibility','Account registration','Account security','Payments and billing','On-site assistant','Disclaimers','Governing law (counsel review)']),
  '/privacy': () => legal('Privacy Policy', ['Information collected','How information is used','Cookies','Security measures','Retention','Your rights','Children']),
  '/refund-policy': () => legal('Refund Policy', ['Wallet, not cash-out','Partial SMM deliveries','Failed automated purchases','Deposits']),
  '/acceptable-use': () => legal('Acceptable Use', ['You may','You may not','Enforcement']),
  '/blog': () => layout('Blog', 'Guides and product updates.',
    `<section class="ws-page-hero"><div class="container text-center"><h1>Blog</h1><p class="muted">Guides, product updates, and reseller tips.</p></div></section>
     <section class="ws-section-sm"><div class="container"><div class="empty-state card"><h2>No posts published yet</h2><p>Staff publish from the admin content tools.</p><a class="btn btn-secondary" href="/faq">Read the FAQ</a></div></div></section>`),
  '/login': () => auth('Log in to your account',
    `<form class="stack" method="post" action="/login">
      <label class="label" for="identifier">Email or username</label>
      <input class="input" id="identifier" name="identifier" required>
      <label class="label" for="password">Password</label>
      <div class="ws-password"><input class="input" id="password" name="password" type="password" required>
      <button type="button" class="ws-password-toggle" data-password-toggle="password">Show</button></div>
      <label class="checkbox"><input type="checkbox" name="remember" value="1"> Remember me on this device</label>
      <button class="btn btn-primary btn-block" type="submit">Log in</button>
    </form>
    <p class="ws-auth-aside">Don't have an account? <a href="/register">Create one</a> · <a href="/forgot-password">Forgot password?</a></p>`),
  '/register': () => auth('Create your account',
    `<form class="stack" method="post" action="/register">
      <label class="label" for="username">Username</label><input class="input" id="username" required minlength="3">
      <label class="label" for="email">Email</label><input class="input" id="email" type="email" required>
      <label class="label" for="password">Password</label>
      <div class="ws-password"><input class="input" id="password" type="password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password">Show</button></div>
      <label class="checkbox"><input type="checkbox" name="terms" required> I agree to the <a href="/terms">Terms</a> and <a href="/privacy">Privacy Policy</a>.</label>
      <button class="btn btn-primary btn-block" type="submit">Create account</button>
    </form>`),
  '/forgot-password': () => auth('Reset your password',
    `<p class="muted">If an account matches we email a reset link. The confirmation is the same either way.</p>
     <form class="stack" method="post" action="/forgot-password">
       <label class="label" for="identifier">Email or username</label>
       <input class="input" id="identifier" required>
       <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
     </form>`),
  '/admin/login': () => auth('Administrator sign-in',
    `<p class="badge badge-brand">Staff only</p>
     <p class="muted">Customer passwords are refused. The first administrator is created by the official database import or seed core — never from a password in git.</p>
     <form class="stack" method="post" action="/admin/login">
       <label class="label" for="identifier">Staff email or username</label>
       <input class="input" id="identifier" required>
       <label class="label" for="password">Password</label>
       <div class="ws-password"><input class="input" id="password" type="password" required>
       <button type="button" class="ws-password-toggle" data-password-toggle="password">Show</button></div>
       <button class="btn btn-primary btn-block" type="submit">Sign in to admin</button>
     </form>`),
  '/assistant': () => layout('Site assistant', 'On-site assistant. No external AI API.',
    `<section class="ws-page-hero"><div class="container" style="max-width:720px">
      <p class="ws-kicker">On-site assistant</p><h1>Ask the panel, not a cloud model</h1>
      <p class="ws-lede">Use the Assistant button. This page documents the same engine.</p>
    </div></section>`),
  '/api/docs': () => layout('Reseller API', 'HTTP API for services, orders and balance.',
    `<section class="ws-page-hero"><div class="container"><h1>Reseller API</h1>
      <p class="ws-lede">Authenticate with an API key from your dashboard. Interactive docs ship with the PHP app at /api/docs.</p>
      <pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:.75rem">POST /api/v1/orders
X-Api-Key: wind_…</pre>
    </div></section>`),
};

function legal(title, sections) {
  const copy = {
    'Terms of Service': [
      ['Acceptance of terms', 'By creating an account, signing in, calling the API or otherwise using the service, you agree to these terms, the Privacy Policy, the Refund Policy and the Acceptable Use Policy.'],
      ['Eligibility', 'You must be able to form a binding contract. The service is not directed at children under 16, or the higher age required in your country.'],
      ['Account registration', 'You provide a unique username, a working email and a password of at least eight characters. A wallet in the panel base currency is created with the account.'],
      ['Account security', 'Keep passwords and API keys confidential. Enable TOTP if you handle significant balance. Logout is POST-only with a CSRF token.'],
      ['Payments and billing', 'The service is prepaid. The wallet is a spending balance: the software does not offer customer withdrawals of leftover deposits.'],
      ['On-site assistant', 'The embedded assistant is a local operational engine. It is not a generative cloud model and cannot place orders or move funds.'],
      ['Disclaimers', 'The service is provided as is. Social-platform outcomes, SMS delivery and vendor matches are outside the operator’s sole control.'],
      ['Governing law', 'Requires review by the operator’s legal counsel. Until a jurisdiction is designated, notices go to the configured support email.'],
    ],
    'Privacy Policy': [
      ['Information collected', 'Account data (username, email, hashed password, role), session and CSRF cookies, login-attempt rows, wallet and order records, and optional MFA secrets stored encrypted.'],
      ['How information is used', 'To authenticate you, take payment, fulfil orders through connected providers, answer tickets, enforce rate limits, and answer assistant questions locally.'],
      ['Cookies', 'A first-party session cookie is required to stay signed in and to bind CSRF. Optional remember-me lengthens that cookie. No advertising cookies ship in this codebase.'],
      ['Security measures', 'Hashed passwords, dummy verify on unknown identifiers, encrypted secrets, TLS on outbound provider calls, RBAC on admin routes.'],
      ['Retention', 'Ledger rows are kept for accounting. Identity-lookup payloads follow the operator’s retention setting (default 30 days).'],
      ['Your rights', 'Depending on where you live you may access, correct or delete personal data via a support ticket. Some ledger rows cannot be erased without breaking the books.'],
      ['Children', 'The service is not directed at children under 16. Contact us to delete an account opened by a child.'],
    ],
    'Refund Policy': [
      ['Wallet, not cash-out', 'Refunds return value to the prepaid wallet through the ledger. They are not bank payouts.'],
      ['Partial SMM deliveries', 'Undelivered quantity can be credited when partial refunds are enabled. Completed work is not cancelled.'],
      ['Failed automated purchases', 'VTU, numbers, identity and gift-card engines credit the wallet when they mark a job failed or abandoned. Revealed codes are completed products.'],
      ['Deposits', 'A verified deposit is wallet balance. Chargebacks may lead to account suspension.'],
    ],
    'Acceptable Use': [
      ['You may', 'Order catalogue services for properties you are authorised to act on, pay bills you are allowed to fund, and call the API with a key you created.'],
      ['You may not', 'Attack the panel, bypass CSRF or RBAC, use stolen payment instruments, or resell identity-lookup payloads in breach of vendor or KYC rules.'],
      ['Enforcement', 'The operator may refuse an order, freeze a wallet, revoke keys or close an account. Appeals go through the contact form.'],
    ],
  };
  const blocks = copy[title] || sections.map((s) => [s, 'See the PHP view for the full clause.']);
  return layout(title, title + ' for this MarvySocials instance.',
    `<section class="ws-page-hero"><div class="container"><p class="ws-kicker">Legal</p><h1>${esc(title)}</h1>
      <p class="hint">Effective 19 August 2026 · Last updated 22 August 2026</p></div></section>
     <section class="ws-section-sm"><div class="container ws-prose">
       <div class="ws-callout">Operator entity, address and jurisdiction are placeholders for the party that deployed this instance and for counsel review.</div>
       ${blocks.map((pair,i) => `<h2>${i+1}. ${esc(pair[0])}</h2><p>${esc(pair[1])}</p>`).join('')}
       <p><a href="/contact">Contact</a></p>
     </div></section>`);
}

function auth(title, inner) {
  return layout(title, title,
    `<section class="ws-section-sm"><div class="container" style="max-width:28rem">
      <div class="card"><h1 style="font-size:1.6rem">${esc(title)}</h1>${inner}</div>
    </div></section>`);
}

function norm(s) {
  return String(s || '').toLowerCase().replace(/[''`]/g, '').replace(/[^a-z0-9\s+\-\/]/g, ' ').replace(/\s+/g, ' ').trim();
}

function assistantReply(message) {
  const q = norm(message);
  if (!q) return { success: false, error: { message: 'Write a question and I will answer from the site knowledge.' } };

  if (/place an order|buy now|pay now|fund my wallet|delete my account/.test(q)) {
    return ok('I cannot do that from this chat. I only explain the site — I will not pretend an account, payment or order succeeded.',
      [{label:'Contact', href:'/contact'}]);
  }
  if (q === 'night' || /good night|have a good night|goodbye|^bye$/.test(q)) {
    return ok('Good night. I will be here when you come back if you need help with the site.');
  }
  if (/good morning|^morning$/.test(q)) return ok('Good morning. I can help with MarvySocials — services, pricing, accounts, or finding a page.');
  if (/good afternoon|^afternoon$/.test(q)) return ok('Good afternoon. Ask me about this panel’s services, pricing, or how to create an account.');
  if (/good evening|^evening$/.test(q)) return ok('Good evening. I am here if you want a walkthrough of services, pricing, or signing in.');
  if (/good day/.test(q)) return ok('Good day. I can explain what this site does and point you to the right page.');
  if (/^(hi|hey|hello|howdy|greetings|hi there|hey there|hello there|hello ai|hey assistant|hello assistant)$/.test(q)) {
    return ok('Hello. I can explain what MarvySocials sells, how the wallet works, and where to sign up or log in.',
      [{label:'Sign up', href:'/register'},{label:'View Services', href:'/services'}]);
  }
  if (/^(ok|okay|great|perfect|good|nice|awesome|alright|got it|cool|i understand|thats fine|that is fine)$/.test(q)) {
    return ok('Sounds good. I am here if you need anything.');
  }
  if (/thank/.test(q) && q.split(' ').length <= 5) {
    return ok("You're welcome. If you need anything else about MarvySocials, I am here to help.");
  }
  if (/youre welcome|you are welcome|no problem/.test(q)) return ok('Glad that helped.');
  if (/how are you|what is going on|whats going on|how are things/.test(q)) {
    return ok('I am the local site assistant — running fine, and ready to help. I can explain this website, or we can pick a page to open.');
  }
  if (/what can you do|can you help|i need help|i have a question|what can you help/.test(q)) {
    return ok('I can help with MarvySocials services, pricing, account registration, login, password reset, FAQs, privacy, terms, and navigating the website. I cannot create an account or place an order from this chat.',
      [{label:'View Services', href:'/services'},{label:'Sign up', href:'/register'}]);
  }
  if (/this website|this site|what can i do here|what is marvy|tell me about this/.test(q)) {
    return ok('MarvySocials is a prepaid reseller panel for social-media services, Nigerian VTU, virtual numbers, identity lookups, gift cards and a platform marketplace. You add funds to a wallet and spend that balance here.',
      [{label:'View Services', href:'/services'},{label:'View Pricing', href:'/pricing'}]);
  }
  if (/forgot|reset my password|cant log in|cannot log in|login isnt working/.test(q)) {
    return ok('Use Forgot password and enter your email or username. If an account matches, a reset link is emailed. I cannot reset the password for you.',
      [{label:'Forgot password', href:'/forgot-password'}]);
  }
  if (/sign up|signup|register|create an account|create account|i want to join|need an account/.test(q)) {
    return ok('To create your MarvySocials account, open the registration page, choose a username and a password of at least 8 characters, and accept the Terms. I cannot register you from this chat.',
      [{label:'Sign up', href:'/register'}]);
  }
  if (/log in|login|sign me in|sign in|access my account/.test(q)) {
    return ok('Customer and staff sign-in is on the login page. I cannot sign you in from this chat.',
      [{label:'Log in', href:'/login'}]);
  }
  if (/privacy/.test(q)) return ok('The Privacy Policy describes account data, hashed passwords, session cookies and local assistant processing. We do not sell personal data.', [{label:'Privacy Policy', href:'/privacy'}]);
  if (/\bterms\b/.test(q)) return ok('The Terms of Service cover accounts, prepaid wallet billing and acceptable use. Open the Terms page for the full text.', [{label:'Terms of Service', href:'/terms'}]);
  if (/faq|frequently asked/.test(q)) return ok('The FAQ covers accounts, billing, services and this assistant. Ask a specific question here or open the FAQ page.', [{label:'View FAQ', href:'/faq'}]);

  if (/pric|cost|how much|plan|subscription/.test(q)) {
    return ok('There is no public monthly subscription. You add funds to a prepaid wallet and pay the published rate. Volume groups are assigned by staff. Default deposit minimum is ₦500.',
      [{label:'View Pricing', href:'/pricing'}]);
  }
  if (/withdraw|wallet|add fund/.test(q)) {
    return ok('Your wallet is a spending balance. Add funds from the dashboard. There are no customer withdrawals.',
      [{label:'View Pricing', href:'/pricing'}]);
  }
  if (/admin|staff/.test(q)) {
    return ok('Staff sign in at /admin/login. Customer accounts are refused. I will not share credentials.',
      [{label:'Staff sign-in', href:'/admin/login'}]);
  }
  if (/vtu|airtime|service|what can i buy|what do you sell/.test(q)) {
    return ok('MarvySocials sells SMM services, Nigerian VTU, virtual numbers, identity lookups, gift cards, a platform marketplace and a reseller API — when the operator has enabled and priced them.',
      [{label:'View Services', href:'/services'}]);
  }
  const hit = faqs.find(([, question]) => q.includes(norm(question).slice(0, 12)));
  if (hit) return ok(hit[2], [{label:'View FAQ', href:'/faq'}]);
  return ok('I am not sure I understood that. I can help with MarvySocials services, pricing, account registration, login, FAQs, privacy, terms, and navigating the website. What would you like to know?',
    [{label:'View FAQ', href:'/faq'},{label:'Contact', href:'/contact'}]);
}

function ok(reply, links) {
  return { success: true, data: { reply, intent: 'preview', links, suggestions: ['What services can I order?','How does pricing work?','How do I contact support?'] } };
}

function serveFile(req, res, file) {
  const abs = path.normalize(file);
  if (!abs.startsWith(path.join(ROOT, 'assets'))) { res.writeHead(403); res.end(); return; }
  fs.readFile(abs, (err, data) => {
    if (err) { res.writeHead(404); res.end('not found'); return; }
    const ext = path.extname(abs);
    const types = {'.css':'text/css','.js':'application/javascript','.png':'image/png','.jpg':'image/jpeg','.svg':'image/svg+xml','.ico':'image/x-icon','.webmanifest':'application/manifest+json'};
    res.writeHead(200, {'Content-Type': types[ext] || 'application/octet-stream'});
    res.end(data);
  });
}

const server = http.createServer((req, res) => {
  const parsed = url.parse(req.url, true);
  let p = decodeURIComponent(parsed.pathname || '/');
  if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);

  if (p === '/csrf') {
    res.writeHead(200, {'Content-Type':'application/json'});
    res.end(JSON.stringify({success:true,data:{name:'csrf_marvy',hash:'preview',header:'X-CSRF-TOKEN',expiresIn:7200}}));
    return;
  }
  if (p === '/assistant/chat' && req.method === 'POST') {
    let body = '';
    req.on('data', (c) => { body += c; if (body.length > 20000) req.destroy(); });
    req.on('end', () => {
      let msg = '';
      try { msg = JSON.parse(body).message || ''; } catch (e) { msg = ''; }
      res.writeHead(200, {'Content-Type':'application/json'});
      res.end(JSON.stringify(assistantReply(msg)));
    });
    return;
  }
  if (p.startsWith('/assets/')) {
    serveFile(req, res, path.join(ROOT, p));
    return;
  }
  if (pages[p]) {
    res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'});
    res.end(pages[p]());
    return;
  }
  res.writeHead(404, {'Content-Type':'text/html; charset=utf-8'});
  res.end(layout('Page not found', 'Not found',
    `<section class="ws-page-hero"><div class="container text-center">
      <p class="ws-kicker">404</p><h1>That page is not here</h1>
      <a class="btn btn-primary" href="/">Go to homepage</a>
    </div></section>`));
});

server.listen(PORT, HOST, () => {
  console.log('MARVYSOCIALS public preview on http://' + HOST + ':' + PORT);
});
