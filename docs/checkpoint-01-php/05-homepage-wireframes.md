# WINDELS PANEL — Artifact 5 (REVISED): Three Homepage Wireframes — PHP Views (CodeIgniter 3.x)

> Revised 2026-08-16 | Rendered as **CI3 PHP views** via `Home.php` → `views/homepages/{aurora,nexus,pulse}/*`. Supersedes Node wireframes.

---

## Shared System (All Three)

* **Controller:** `Home::index()` reads `$active = $this->Setting_model->get('active_homepage')` (`AURORA|NEXUS|PULSE`) and loads `views/homepages/{strtolower($active)}/hero.php` + sections. No Node/Next.js.
* **Layout:** `views/layouts/public.php` wraps all three (nav + footer). Tailwind CSS built to `assets/css/tailwind.css`.
* **Switcher:** `Admin/Appearances::homepage()` — radio `AURORA|NEXUS|PULSE` + live preview iframe (`/?preview=PULSE`, admin session only, not persisted) + `[Publish]` → updates `settings.active_homepage`.
* **SEO:** each homepage sets `$data['meta_title']`, `meta_description`, `og`, `canonical` in controller → `layouts/public.php` `<head>`. `sitemap.xml` / `robots.txt` via `Home::sitemap/robots`.
* **Responsive:** mobile-first Tailwind; mobile bottom nav only in `layouts/dashboard.php`, not public.

---

## Homepage 01 — AURORA

**Positioning:** Modern premium SaaS. Trustworthy storefront for agencies/creators.

**Style:** Cinematic hero, soft gradients (indigo→violet→pink), careful glassmorphism, floating service cards, rounded-2xl, generous whitespace. Hybrid white/dark sections. Serif+sans (e.g. Fraunces + Inter). Lucide icons.

**Views:** `views/homepages/aurora/{hero.php, stats.php, popular_services.php, how_it_works.php, platforms.php, why_choose.php, categories.php, pricing.php, faq.php, testimonials.php, cta.php}`

```
┌──────────────────────────────────────────────────────────┐
│ layouts/public.php → public_nav.php (transparent over   │
│ hero, sticky on scroll; Login ghost + Start Ordering)    │
├──────────────────────────────────────────────────────────┤
│ hero.php (2-col desktop, stacked mobile)                 │
│  Left: eyebrow "Trusted by 50k+ marketers"               │
│   h1 "Grow Your Social Presence / With WINDELS PANEL"    │
│   sub "One platform. 2,000+ services..."                 │
│   [Start Ordering → /register] [View Services →/services]│
│   trust: ★★★★★ 4.9 · 2M+ orders                         │
│  Right: floating glass cards (IG Followers $1.20 etc.)   │
│   + gradient orb + grid                                   │
├──────────────────────────────────────────────────────────┤
│ stats.php (4-col)                                         │
│  [2M+ Orders] [48k Users] [2k Services] [99.8% Uptime]  │
│  count-up on scroll (IntersectionObserver, vanilla JS)   │
├──────────────────────────────────────────────────────────┤
│ popular_services.php (carousel → grid)                   │
│  6-8 cards: platform logo, name, rate/1k, min/max,      │
│  avg time, [Order Now → /dashboard/new-order]           │
│  filter chips: All | Instagram | TikTok | YouTube (JS)  │
├──────────────────────────────────────────────────────────┤
│ how_it_works.php (3 steps, connecting line)               │
│  01 Choose → 02 Place Order → 03 Track & Grow            │
├──────────────────────────────────────────────────────────┤
│ platforms.php (logo wall, 12 muted → color on hover)    │
├──────────────────────────────────────────────────────────┤
│ why_choose.php (3-col benefits + visual)                  │
├──────────────────────────────────────────────────────────┤
│ categories.php (8-cat icon grid)                          │
├──────────────────────────────────────────────────────────┤
│ pricing.php (Starter vs Reseller tiers)                   │
├──────────────────────────────────────────────────────────┤
│ faq.php (accordion, 6 items, link to /faq) — <details>  │
├──────────────────────────────────────────────────────────┤
│ testimonials.php (3 cards)                                │
├──────────────────────────────────────────────────────────┤
│ cta.php (gradient band) "Ready to scale?" [Create Acct] │
├──────────────────────────────────────────────────────────┤
│ partials/footer.php                                       │
└──────────────────────────────────────────────────────────┘
```

Mobile: hero stacked, stats 2×2, services horizontal swipe, steps vertical.

---

## Homepage 02 — NEXUS

**Positioning:** Dark enterprise dashboard. For resellers/power users who want automation + provider transparency.

**Style:** Dark `#0B0F1A`, neon cyan `#06FFA5` / violet `#7C5CFF`, mono JetBrains Mono + Inter, data viz, grid lines, glow.

**Views:** `views/homepages/nexus/{hero.php, network_viz.php, stats.php, service_explorer.php, automation.php, reseller_api.php, wallet.php, faq.php, cta.php}`

```
┌──────────────────────────────────────────────────────────┐
│ Header (dark, border-b, mono nav)                        │
├──────────────────────────────────────────────────────────┤
│ hero.php (2-col, dark)                                   │
│  label "ENTERPRISE SMM INFRASTRUCTURE" (mono, cyan)     │
│  h1 "One Platform. / Thousands of Services. /            │
│      Automated Fulfillment."                               │
│  sub "Aggregate providers. Route orders. Sync status..."  │
│  [Launch Dashboard] [View API Docs → /api/docs]          │
│  Right — flow diagram (CSS + JS animation):              │
│   Customer ↓ WINDELS PANEL (Queue·Ledger·State Machine) │
│            ↓ Provider Network (4 nodes, health dots)    │
│            ↓ Fulfillment ✓                               │
├──────────────────────────────────────────────────────────┤
│ stats.php (dark cards, mono numbers, Chart.js area)     │
│  [Orders/min] [Queue depth] [Provider latency] [99.3%]  │
├──────────────────────────────────────────────────────────┤
│ network_viz.php (6 provider cards: name, health,        │
│  last sync, balance, sparkline)                           │
├──────────────────────────────────────────────────────────┤
│ service_explorer.php (dense table-like; search+filter   │
│  → rows ID|name|type|rate|min/max|provider | [Order])  │
├──────────────────────────────────────────────────────────┤
│ automation.php (3-col + code snippet)                    │
│  Order Sync (cron → CLI) / Wallet Ledger (DECIMAL)      │
│  Webhooks & retries (backoff)                             │
│  <pre>POST /api/v1/orders {service, link}</pre>         │
├──────────────────────────────────────────────────────────┤
│ reseller_api.php (left curl+JSON, right endpoints)      │
├──────────────────────────────────────────────────────────┤
│ wallet.php (Deposit → Ledger → Order diagram; gateways) │
├──────────────────────────────────────────────────────────┤
│ faq.php (dark accordion) | cta.php (neon border)        │
├──────────────────────────────────────────────────────────┤
│ Footer (dark)                                            │
└──────────────────────────────────────────────────────────┘
```

Mobile: hero stacked, stats single col, explorer → card list (swipe filters).

---

## Homepage 03 — PULSE

**Positioning:** Bright marketplace. Discovery-first, search-first, 2-click ordering. **Mobile-first target.**

**Style:** Bright white, bold accent rose/amber, large rounded cards, category pills, Inter + Plus Jakarta Sans, playful.

**Views:** `views/homepages/pulse/{hero_search.php, categories.php, trending.php, popular.php, fast_order.php, reviews.php, faq.php, cta.php}`

```
┌──────────────────────────────────────────────────────────┐
│ Header (white, search in header on desktop)             │
│  logo | [🔍 Search services — prominent input+button]   │
│  nav: Categories | Trending | Pricing | Login | Sign Up │
├──────────────────────────────────────────────────────────┤
│ hero_search.php (centered, search-centric)               │
│  h1 "Find the Right Service. / Place Your Order. /     │
│      Track Everything."                                  │
│  [🔍 Search e.g. "Instagram followers"] [Search]         │
│  chips: Instagram • TikTok • YouTube • X • Facebook    │
│  "Popular: Instagram Followers, YouTube Views"           │
├──────────────────────────────────────────────────────────┤
│ categories.php (horiz scroll on mobile)                 │
│  [Followers] [Likes] [Views] [Comments] — icon+count   │
├──────────────────────────────────────────────────────────┤
│ popular.php / trending.php (masonry/grid 8 + carousel 6)│
│  Card: platform badge, name, ★, rate/1k, min/max,      │
│  delivery time, [Order]                                  │
├──────────────────────────────────────────────────────────┤
│ fast_order.php (sticky desktop)                          │
│  Category → Service → Link → Quantity → Price           │
│  → [Place Order] (mini new-order; live PricingService)  │
│  Mobile: bottom drawer / FAB "Quick Order"              │
├──────────────────────────────────────────────────────────┤
│ reviews.php (★4.9/5 from 12k + 3 review cards)          │
├──────────────────────────────────────────────────────────┤
│ faq.php (light accordion) | cta.php (accent band)       │
├──────────────────────────────────────────────────────────┤
│ Footer (light)                                           │
└──────────────────────────────────────────────────────────┘
```

Interactions: hero search filters grids client-side (debounced, no page reload); category pills filter; fast-order live price via AJAX to `Services::price` (no provider call).

Mobile-first: search full-width, categories swipe, cards 1-col large tap targets, fast-order bottom sheet.

---

## Technical Notes (PHP)

* **Single PHP switch:** `Home::index()` — no Next.js routing. Preview via `?preview=PULSE` bypasses setting (guarded `if ($this->session->userdata('role')==='ADMIN')`).
* **No shared homepage logic** beyond `layouts/public.php` + Tailwind tokens. Each folder is independent.
* **Dark/light:** `NEXUS` is inherently dark; `AURORA`/`PULSE` support `prefers-color-scheme` via Tailwind `dark:`.
* **Animations:** CSS + vanilla JS or minimal Framer-equivalent (no React). Respects `prefers-reduced-motion`.
* **Accessibility:** semantic HTML, keyboard accordion, skip link, WCAG 2.2 AA contrast (NEXUS tested for dark contrast).
