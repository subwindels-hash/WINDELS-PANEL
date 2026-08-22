# Averion Commerce — architecture-level frontend & chatbot fix report

Branch: `arena/01a0292e-windels-panel`
Commit: `a27b7f3`

---

## 1. Audit summary

| Area | Count / result |
|---|---|
| CodeIgniter controllers | 54 |
| Models | 61 |
| Views | 150 (63 public/auth/dashboard/setup + admin + errors) |
| Layout files | 5: `main.php` (public), `auth.php`, `app.php` (authenticated), `admin.php` (forwarder), `public.php` (compat) |
| Partials | 13 |
| Libraries | 75 |
| Helpers | 1 |
| Config files | 11 |
| Migrations | 19 |
| Routes | 268 route definitions |
| Assets | 34 files |
| AI/chatbot files | `Chat.php`, `Csrf.php`, `SiteOperatorEngine.php`, `SiteOperatorKnowledge.php`, `ai/SiteOperatorPhrases.php`, `partials/site_operator.php`, `assets/js/app.js` |
| AJAX endpoints | `POST /assistant/chat`, `GET /assistant/welcome`, `GET /csrf`, `POST /contact`, plus API v1/webhook routes |
| DB tables relevant to UI/chatbot | `settings`, `announcements`, `faqs`, `blog_posts`, `services`, `service_categories`, `users`, `login_attempts` |

### Page/route inventory

**Public pages (all now through `layouts/main.php`):**
`/`, `/services`, `/services/:slug`, `/pricing`, `/about`, `/faq`, `/blog`, `/blog/:slug`, `/contact`, `/design-system`, `/terms`, `/privacy`, `/refund-policy`, `/acceptable-use`, `/assistant`, 404 page.

**Auth pages (through `layouts/auth.php`):**
`/login`, `/register`, `/forgot-password`, `/reset-password/:token`, `/admin/login`, `/verify-email`.

**Authenticated product (through `layouts/app.php`):**
All `/dashboard/*` and `/admin/*` routes.

**Robot/health:**
`/health`, `/health/live`, `/health/ready`, `/robots.txt`, `/sitemap.xml`.

---

## 2. Root causes found

### 2.1 Pages looked scattered / inconsistent
1. `assets/css/tailwind.css` (the compiled utility stylesheet used by nearly every public/auth view) was **git-ignored and missing from the checkout**, so every page loaded a 404 stylesheet. The shared design system existed, but the hundreds of Tailwind utility classes the pages rely on (`min-h-screen`, `space-y-4`, `max-w-7xl`, `lg:grid-cols-3`, etc.) never reached the browser. This is the single biggest reason pages rendered as a collection of partially-styled views instead of one product.
2. There were three different home page implementations (`AURORA`, `NEXUS`, `PULSE`) with completely different palettes. They all render inside the global shell, but a non-default setting can still change the landing page appearance. This remains behind the existing admin preview/setting and is documented here for the operator; the default is `AURORA`.
3. The existing public layout had a real usable shell, but layout rendering was split between `layouts/public.php`, hard-coded `partials/site_operator.php`, and a duplicated `<script>` tag. There was no single `head`/`header`/`navbar`/`scripts` partial, so duplication across layouts was unavoidable.

### 2.2 Chatbot did not open / backend failure
1. **Missing utility CSS** meant the assistant component's visibility/spacing was not guaranteed by the shared stylesheet pipeline.
2. **Fragile JS boot**: all global init (including the assistant) was attached only inside a `DOMContentLoaded` listener. If `app.js` loaded after that event, or if any earlier widget init threw, the assistant toggle handler was never bound. There was no try/catch around init and no immediate-boot fallback.
3. **No single canonical chatbot partial**: layouts loaded `partials/site_operator.php` from three places; any divergence or typo in a layout could omit the markup. It is now loaded from `partials/chatbot.php` in every layout.
4. **Backend rate limiter could 500 on a newly-installed database**: `RateLimiter` reads `login_attempts`. On a DB that is reachable but has not yet been migrated, `too_many_failures()` / `record()` would throw, turning `POST /assistant/chat` into an HTML 500 instead of JSON. `RateLimiter` now catches DB exceptions and fails open (rate-limiting still works once the table exists).
5. **A newly introduced global `partials/scripts.php` initially omitted the closing `?>` before raw HTML**, which would have been a PHP parse error. The PHP syntax check caught it and it is fixed.

---

## 3. Architecture changes

### 3.1 One global public layout
- New `application/views/layouts/main.php` — the single public shell.
- New shared partials:
  - `partials/head.php` — metadata, Open Graph, CSRF tags, fonts, `tailwind.css`, `design-system.css`.
  - `partials/header.php` — announcement bar + navbar.
  - `partials/navbar.php` — the only public navigation (logo, Services, Pricing, FAQ, Blog, Contact, About, Login/Sign up, mobile menu, active state).
  - `partials/announcement.php` — alias for the announcement component.
  - `partials/chatbot.php` — alias for the AI assistant component.
  - `partials/scripts.php` — the single global JS include.
  - `partials/footer.php` — global footer (already existed, now brand/config-driven).
- `layouts/public.php` remains as a backward-compatible forwarder.
- `Public_Controller::render_public()` and every public controller now load `layouts/main`.

### 3.2 Asset pipeline
- Committed the compiled `assets/css/tailwind.css` (removed from `.gitignore`), so a PHP/shared-hosting deployment no longer requires Node just to render the site.
- All layouts load CSS/JS via `base_url()` and through shared partials; no page hardcodes `assets/...`.
- `npm run build:css` still works and regenerates the committed stylesheet.

### 3.3 Design system & typography
- `assets/css/design-system.css` now exposes the requested token set: `--container-max`, `--space-1..8`, `--radius-sm/md/lg/xl`, `--text-primary/secondary/muted`, `--surface`, `--surface-muted`, `--border`, `--primary`, `--primary-hover`.
- Standard `.container` is now `width:min(100% - 2rem, 1200px); margin-inline:auto`.
- Added reusable section patterns (`.ws-hero`, `.ws-feature-grid`, `.ws-service-grid`, `.ws-pricing-grid`, `.ws-faq-section`, `.ws-cta`, `.ws-testimonial-card`, `.ws-stats-grid`, `.ws-content-section`, `.ws-contact-section`).
- Standardized heading/body/caption/label/small-text and buttons.
- Removed page-local `.ws-searchwrap` and `.ws-prose` duplicates into the design system.

### 3.4 Header / navbar
- Single `partials/navbar.php`; active page gets `.is-active` + `aria-current`.
- Consistent logo height, nav height, spacing, button styles and mobile menu.
- Public brand is config-driven: `config/windels.php` → `public_name => Averion Commerce`. Admin/internal product name is left unchanged.
- New public-brand SVG assets (light / dark / icon) plus updated `site.webmanifest`.

### 3.5 Announcement bar
- Slowed to a readable duration derived from the character count (~6 chars/sec, clamped 55–180s).
- Pauses on hover **and** focus/focus-within.
- Reduced-motion disables the animation.
- Mobile-specific padding/font size.
- `aria-live="off"` + region label + keyboard focusable.

### 3.6 Auth layout
- Now uses the same head/announcement/chatbot/scripts partials.
- Fixed a broken HTML nesting bug in `layouts/auth.php` (unbalanced `div`s around the auth shell) that could push the page/app shell into an unexpected layout.
- Password visibility, loading/error handling already existed; the auth forms are now guaranteed to get the compiled utility CSS.

### 3.7 Services / Pricing / FAQ / Design system / Legal
- `/services`: global layout, hero, product areas, new *How it works*, *Why people use it*, *Security practices*, catalogue with filters/pagination, final CTA.
- `/pricing`: global layout, equal-height pricing cards, compare table, billing info, FAQ accordion, final CTA.
- `/faq`: global layout, search filter, categorized accordion.
- `/design-system`: documents the live tokens/components and now renders from the same partials used in production.
- `/terms`, `/privacy`, `/refund-policy`, `/acceptable-use`: global layout, hero, TOC, readable `.ws-prose`.

---

## 4. AI chatbot implementation

### 4.1 Engine
The site keeps the **embedded/local deterministic operator**. No OpenAI/Anthropic/Gemini or any external AI API was added.

- `application/libraries/SiteOperatorEngine.php` — intent matching, follow-up context, FAQ retrieval.
- `application/libraries/ai/SiteOperatorPhrases.php` — phrase catalogue (greetings, courtesy, wellbeing, help, about, register, login, pricing, services, FAQ, privacy, terms, design system, follow-up, etc.).
- `application/libraries/SiteOperatorKnowledge.php` — product areas and site knowledge; public brand is now `Averion Commerce`.

### 4.2 Endpoint / CSRF
- Frontend: `POST /assistant/chat` with `{message, history}` JSON.
- `assets/js/app.js` (the single global JS) automatically sends the current CodeIgniter CSRF token in `X-CSRF-TOKEN` on every unsafe same-origin fetch/XHR; refreshes a stale token from `GET /csrf`; retries a 419 once.
- `application/core/MY_Security.php` already accepts the `X-CSRF-TOKEN` header and returns machine-readable 419 JSON. This was verified and kept.
- The public assistant endpoint remains public while applying input validation (`max 1000 chars`), request-size/history sanitization, and IP rate limiting (now fail-open if the DB table is missing).

### 4.3 UX
Floating AI button with avatar, welcome message, suggested questions, input, send, enter-to-send, loading indicator, error state, conversation history, close button, Escape-to-close, focus management, mobile full-width layout, and a `data-open-assistant` trigger for the full-page route.

---

## 5. Tests performed

### 5.1 Build / static checks (run in this sandbox)
| Check | Command / tool | Result |
|---|---|---|
| PHP syntax | `node tools/php_syntax_check.mjs` (glayzzle/php-parser over all PHP source) | ✅ 378 files parsed |
| JS syntax | `node --check assets/js/app.js` | ✅ passed |
| Tailwind build | `npm run build:css` | ✅ generated `assets/css/tailwind.css` |
| CSS/present | files exist in `assets/css` | ✅ `design-system.css` + compiled `tailwind.css` |
| HTML div balance | static scan of layouts/partials | ✅ all balanced |

### 5.2 Frontend chatbot regression test
`npm run test:js` → `tests/js/chatbot.open.test.mjs` (jsdom).

10 assertions passed:
1. Chatbot toggle button exists.
2. Chatbot panel exists.
3. Panel starts closed.
4. Panel opens on button click.
5. `aria-expanded` updates.
6. Chat input receives focus after opening.
7. Assistant endpoint is called once.
8. User message bubble is rendered.
9. Assistant reply bubble is rendered from JSON.
10. Panel closes.

### 5.3 What could not be run here
This sandbox has **no PHP CLI, Composer vendor, MariaDB or Docker runtime**, so the following were **not** executed live:

- `php -l` (we used a PHP parser instead).
- `php index.php ...` (CodeIgniter application boot).
- Live browser Network/console session on each route.
- Real MySQL connectivity check against a migrated DB.
- Real `POST /assistant/chat` through Apache/Nginx + PHP-FPM.

These must be run on a machine with PHP 7.4+ and a migrated MariaDB before deploy. The frontend chatbot chain (button → click → fetch → JSON → bubble) is covered by the jsdom test; the PHP-side intent engine is covered analytically and by the existing PHPUnit `SiteOperatorConversationTest` once PHPUnit is available.

---

## 6. Remaining operator decision

The three-homepage preview system (`AURORA`/`NEXUS`/`PULSE`) still exists because it is part of the existing admin setting and its own tests. This report keeps it intact and defaults the site to `AURORA`, the design-system homepage. If the product owner decides the marketing site must have exactly one look, the follow-up is to remove the homepage setting/preview or fold `NEXUS`/`PULSE` into the single shell. That change was deliberately not made here to avoid breaking existing admin/seed tests.
