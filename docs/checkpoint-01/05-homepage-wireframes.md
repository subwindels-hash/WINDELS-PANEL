# WINDELS PANEL — Artifact 5: Three Homepage Wireframe / Layout Plans

> Checkpoint 01 | Each homepage is a genuinely different layout, not a recolor. Responsive: desktop/laptop/tablet/mobile. Light+dark support. SEO-friendly SSR.

---

## Shared System (All Three Homepages)

* **Header:** logo (light/dark), nav (`Services`, `Pricing`, `FAQ`, `Blog`, `Contact`), `Login` (ghost) + `Start Ordering` (primary CTA). Mobile: hamburger → drawer + bottom bar on dashboard only.
* **Footer:** brand, tagline, social links, sitemap (Services, Company, Legal), newsletter (optional), copyright. Configured via `settings.branding`.
* **Switcher:** `Admin → Appearance → Homepage → Active Homepage` = `AURORA | NEXUS | PULSE`. `apps/web/app/(public)/page.tsx` is a server component that reads `settings.activeHomepage` and renders the active template. Preview route: `/admin/appearance/homepage?preview=PULSE` renders in iframe without changing `activeHomepage`.
* **SEO:** each homepage exports `metadata` (title, description, OG, canonical). Sections use semantic HTML (`<section>`, `h1`→`h2` hierarchy, `aria-label`).
* **Performance:** hero + above-fold SSR; below-fold lazily loaded; images via `next/image` with S3/R2 loader; no provider API calls during render (Redis cache fallback).

---

## Homepage 01 — AURORA

**Positioning:** *Modern premium SaaS.* For agencies & creators who want a polished, trustworthy storefront.

**Style:** Cinematic hero, soft gradients (indigo→violet→pink), careful glassmorphism, floating service cards, rounded-2xl, generous whitespace. Hybrid white/dark sections. Strong serif+ sans typography (e.g. `Fraunces` + `Inter`). Lucide icons. Recharts sparkline in stats.

### Layout (top → bottom)

```
┌─────────────────────────────────────────────────────────┐
│ Header (transparent over hero, sticky on scroll)        │
├─────────────────────────────────────────────────────────┤
│ HERO (full-bleed, 2-col on desktop, stacked on mobile) │
│ Left:                                                    │
│  eyebrow: "Trusted by 50k+ marketers"                   │
│  h1: "Grow Your Social Presence / With WINDELS PANEL"   │
│  sub: "One platform. 2,000+ services. Instagram,       │
│       TikTok, YouTube & more — automated fulfillment."  │
│  CTAs: [Start Ordering — primary] [View Services]       │
│  trust row: ★★★★★ 4.9 • "2M+ orders delivered"         │
│ Right (visual):                                          │
│  floating glass cards (Instagram Followers $1.20,        │
│  YouTube Views $0.80, TikTok Likes) with subtle         │
│  parallax + provider network arc in background           │
│  gradient orb + grid pattern                             │
├─────────────────────────────────────────────────────────┤
│ LIVE PLATFORM STATISTICS (4-col cards)                  │
│ [2M+ Orders] [48k Users] [2k Services] [99.8% Uptime]  │
│ animated count-up on scroll; icons in tinted circles     │
├─────────────────────────────────────────────────────────┤
│ POPULAR SERVICES (carousel → grid)                      │
│  6-8 service cards: platform logo, name, rate/1k,       │
│  min/max, avg time, [Order Now]                         │
│  filter chips: All / Instagram / TikTok / YouTube       │
├─────────────────────────────────────────────────────────┤
│ HOW IT WORKS (3 steps, numbered, connecting line)       │
│  01 Choose Service → 02 Place Order → 03 Track & Grow  │
│  illustration per step; mobile = vertical timeline       │
├─────────────────────────────────────────────────────────┤
│ SUPPORTED PLATFORMS (logo wall, 12 platforms)           │
│  Instagram TikTok YouTube X Facebook Spotify ...        │
│  muted logos, hover → color                              │
├─────────────────────────────────────────────────────────┤
│ WHY CHOOSE WINDELS (3-col benefits + left visual)       │
│  • Fastest fulfillment  • Reseller API  • 24/7 Support  │
│  • Wallet & analytics   • Secure payments               │
├─────────────────────────────────────────────────────────┤
│ SERVICE CATEGORIES (icon grid, 8 categories)            │
│  Followers / Likes / Views / Comments / Subscribers...  │
├─────────────────────────────────────────────────────────┤
│ PRICING / RESELLER BENEFITS (2-tier cards)              │
│  Starter (pay-as-you-go) vs Reseller (volume pricing)   │
│  comparison table + "Calculate your margin" teaser       │
├─────────────────────────────────────────────────────────┤
│ FAQ (accordion, 6 items, link to /faq)                  │
├─────────────────────────────────────────────────────────┤
│ TESTIMONIALS (3 cards, avatar, quote, role)             │
├─────────────────────────────────────────────────────────┤
│ CTA (gradient band, centered)                           │
│  "Ready to scale your social growth?"                   │
│  [Create Account] [Contact Sales]                        │
├─────────────────────────────────────────────────────────┤
│ Footer                                                   │
└─────────────────────────────────────────────────────────┘
```

**Interactions:** hero CTA scrolls to services; stats count-up via `IntersectionObserver`; service filter is client-side; FAQ accordion accessible (`<details>` + keyboard).

**Mobile adaptation:** hero stacked (visual below copy), stats 2×2, services horizontal swipe, steps vertical.

---

## Homepage 02 — NEXUS

**Positioning:** *Dark enterprise dashboard.* For resellers & power users who want automation, API, and provider-network transparency.

**Style:** Dark background (`#0B0F1A`), neon accents (cyan `#06FFA5` / violet `#7C5CFF`), mono + sans typography (`JetBrains Mono` + `Inter`), data viz, grid lines, glow, large code-like blocks. Enterprise/SaaS dashboard feeling.

### Layout

```
┌─────────────────────────────────────────────────────────┐
│ Header (dark, border-b, mono nav)                       │
├─────────────────────────────────────────────────────────┤
│ HERO (2-col, dark)                                      │
│ Left:                                                    │
│  label: "ENTERPRISE SMM INFRASTRUCTURE" (mono, cyan)   │
│  h1: "One Platform. / Thousands of Services. /          │
│       Automated Fulfillment."                            │
│  sub: "Aggregate providers. Route orders. Sync status.  │
│       Scale with a real API — not a spreadsheet."       │
│  CTAs: [Launch Dashboard — neon] [View API Docs]        │
│ Right (visual — the flow diagram):                      │
│  ┌──────────┐                                            │
│  │ Customer │                                            │
│  └────┬─────┘                                            │
│       ↓ (animated line)                                 │
│  ┌──────────────┐                                        │
│  │ WINDELS PANEL│  ← "Queue • Ledger • State Machine"   │
│  └──────┬───────┘                                        │
│         ↓                                                │
│  ┌──────────────────┐                                    │
│  │ Provider Network │  (4 provider nodes, health dots)  │
│  └────────┬─────────┘                                    │
│           ↓                                             │
│     [Fulfillment] — checkmark animation                 │
├─────────────────────────────────────────────────────────┤
│ LIVE ORDER STATISTICS (dark cards, mono numbers)        │
│ [Orders/min chart (Recharts area)] [Provider latency]   │
│ [Queue depth] [Success rate 99.3%]                      │
├─────────────────────────────────────────────────────────┤
│ PROVIDER NETWORK (interactive grid)                     │
│  6 provider cards: name, health (Online/Degraded),     │
│  last sync, balance, latency sparkline                  │
├─────────────────────────────────────────────────────────┤
│ SERVICE EXPLORER (table-like, dense)                    │
│  Search + platform filter → service rows (ID, name,    │
│  type, rate, min/max, provider) with "Order" action    │
├─────────────────────────────────────────────────────────┤
│ AUTOMATION FEATURES (3-col, icon + code snippet)        │
│  • Order Sync (cron → BullMQ)                           │
│  • Wallet Ledger (NUMERIC, idempotent)                  │
│  • Webhooks & retries (exponential backoff)             │
│  code block: `POST /api/v1/orders { service, link }`   │
├─────────────────────────────────────────────────────────┤
│ RESELLER API (dark code panel + endpoint list)          │
│  Left: curl examples, response JSON                     │
│  Right: endpoints: /services, /orders, /balance...     │
│  CTA: [Read API Docs]                                    │
├─────────────────────────────────────────────────────────┤
│ WALLET / PAYMENTS (diagram: Deposit → Ledger → Order)   │
│  gateway icons: Stripe PayPal Flutterwave...            │
├─────────────────────────────────────────────────────────┤
│ FAQ (dark accordion)                                    │
├─────────────────────────────────────────────────────────┤
│ CTA (dark, neon border)                                 │
│  "Automate your SMM operation today."                   │
│  [Create Account]                                        │
├─────────────────────────────────────────────────────────┤
│ Footer (dark)                                            │
└─────────────────────────────────────────────────────────┘
```

**Interactions:** hero flow animates on load (Framer Motion lines); live stats are SSR with client polling (or SSE mock); provider cards show real health if authed, otherwise static demo; service explorer is searchable without page reload.

**Mobile adaptation:** hero stacked (diagram below), stats single column, explorer becomes card list (swipeable filters), code panel horizontally scrollable.

---

## Homepage 03 — PULSE

**Positioning:** *Bright marketplace.* For discovery-first customers who want to search, compare, and order in 2 clicks.

**Style:** Bright, card-based, highly visual, mobile-first, search-first. White background, bold accent (e.g. `rose`/`amber`), large rounded cards, category pills, `Inter` + `Plus Jakarta Sans`, playful but clean. Like a modern app marketplace.

### Layout

```
┌─────────────────────────────────────────────────────────┐
│ Header (white, search in header on desktop)             │
│  logo | [Search services — prominent input + button]    │
│  nav: Categories | Trending | Pricing                   │
│  actions: [Login] [Sign Up — accent]                    │
├─────────────────────────────────────────────────────────┤
│ HERO (centered, search-centric, bright)                 │
│  h1: "Find the Right Service. / Place Your Order. /    │
│       Track Everything."                                 │
│  sub: "Search 2,000+ services across every platform."   │
│  ┌──────────────────────────────────────────────────┐   │
│  │ 🔍 Search services (e.g. "Instagram followers") │   │
│  │ [Search]                                         │   │
│  └──────────────────────────────────────────────────┘   │
│  chips: Instagram • TikTok • YouTube • X • Facebook    │
│  below: "Popular: Instagram Followers, YouTube Views"   │
├─────────────────────────────────────────────────────────┤
│ CATEGORY NAVIGATION (horiz scroll on mobile)            │
│  [Instagram Followers] [Likes] [Views] [Comments]       │
│  [Subscribers] [Traffic] — icon + count pills           │
├─────────────────────────────────────────────────────────┤
│ POPULAR SERVICES (masonry/grid, 8 cards)                │
│  Card: platform badge, service name, rating ★,          │
│  rate per 1k, min/max, delivery time, [Order]           │
│  hover: quick-view                                    │
├─────────────────────────────────────────────────────────┤
│ TRENDING NOW (horizontal carousel, 6 cards, flame icon) │
├─────────────────────────────────────────────────────────┤
│ FAST-ORDER INTERFACE (sticky on desktop)                │
│  Left: Category → Service → Link → Quantity → Price    │
│  Right: Order summary + [Place Order]                   │
│  Mobile: collapsible sheet / bottom drawer              │
├─────────────────────────────────────────────────────────┤
│ CUSTOMER REVIEWS (star summary + 3 review cards)        │
│  "4.9/5 from 12k reviews" + avatars                     │
├─────────────────────────────────────────────────────────┤
│ FAQ (light accordion)                                   │
├─────────────────────────────────────────────────────────┤
│ CTA (accent band, playful)                              │
│  "Start ordering in 30 seconds."                        │
│  [Browse Services] [Create Account]                      │
├─────────────────────────────────────────────────────────┤
│ Footer (light)                                          │
└─────────────────────────────────────────────────────────┘
```

**Interactions:** hero search filters the Popular/Trending grids client-side (debounced); category pills filter; fast-order is a mini version of `/new-order` — selecting a service updates price live via `PricingService`; mobile: categories swipe, fast-order is a bottom sheet triggered by "Quick Order" FAB.

**Mobile adaptation (primary design target):** hero search full-width, categories horizontal swipe, services 1-col cards with large tap targets, fast-order as bottom drawer, bottom nav not on homepage (only in dashboard).

---

## Technical Notes (All Three)

* **Routing:** single `page.tsx` switches via `activeHomepage` (SSR). No client redirect. Preview via `?preview=PULSE` bypasses setting (admin only, guarded).
* **Components:** each homepage lives in `apps/web/components/homepages/<aurora|nexus|pulse>/` — no shared homepage logic beyond header/footer and design tokens.
* **Design tokens:** all three consume the same `packages/ui` tokens (colors, radius, shadows) but with different semantic mappings (AURORA=light, NEXUS=dark, PULSE=bright). Dark/light toggle via `next-themes`.
* **Animations:** Framer Motion where meaningful (hero, stats, flow). Respects `prefers-reduced-motion`.
* **Accessibility:** keyboard nav, focus rings, skip link, WCAG 2.2 AA contrast (NEXUS tested for dark-mode contrast).
* **Responsive breakpoints:** `sm:640, md:768, lg:1024, xl:1280` — tested on desktop/laptop/tablet/mobile per spec.
