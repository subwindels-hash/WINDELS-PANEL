# MarvySocials — Session 04: Design System

> Establishes the visual language shared by the public site, auth pages, customer
> dashboard and admin area. Tailwind-based, but renders **without a Node build**.

## What shipped

| Area | Files |
|---|---|
| Brand tokens (Tailwind source of truth) | `tailwind.config.js` |
| Self-contained component CSS (no build required) | `assets/css/design-system.css` |
| Asset build manifest | `package.json` |
| Fonts wired into every layout | `views/layouts/{public,auth,app}.php` |
| Living style guide | `Home::styleguide()` → `views/public/styleguide.php` at `/design-system` |
| Icon set (Lucide-style SVG partial) | `views/partials/icon.php` |
| Homepage refresh (three distinct identities) | `views/homepages/{aurora,nexus,pulse}/index.php` |
| Login-aware footer link | `views/partials/footer.php` |

## Two-layer approach

> **Revised (2026-08-22):** the compiled `assets/css/tailwind.css` is now a
> tracked artifact. A fresh checkout or no-Node deployment must never 404 the
> stylesheet the layouts link, so the build output ships with the repo and
> `npm run build:css` only needs to run when utility classes change
> (CI verifies the committed file is in sync). `design-system.css` remains the
> committed component-layer fallback.

The stack is PHP with no runtime Node tooling, and `assets/css/tailwind.css`
is the committed compiled output (rebuilt at deploy time when utilities
change). To keep the UI working in every environment the design system has two
layers:

1. **`tailwind.config.js`** declares the brand palette, fonts, radii, shadows and
   animations. The production build (`npm run build:css`) compiles the utility
   classes used across the PHP views into `assets/css/tailwind.css`.
2. **`assets/css/design-system.css`** mirrors the same tokens as CSS custom
   properties and ships stable **component classes** (`.btn`, `.card`, `.input`,
   `.badge`, `.alert`, `.table`, `.nav`, `.sidebar`, `.container`, `.grid-*`).
   It loads after `tailwind.css` and guarantees the app is styled even before
   the Tailwind build has run (local dev without Node, code review sandboxes).

Both stylesheets are linked in every layout; PHP views should prefer the
component classes, with utility classes for one-off spacing/positioning.

## Build

```bash
npm install
npm run build:css     # one-shot -> assets/css/tailwind.css (minified)
npm run watch:css     # rebuild on view changes during development
```

`npm run build` is intended to run in CI/deploy. The output file is git-ignored
because it is a generated artifact; `design-system.css` is committed and is the
fallback stylesheet.

## Tokens

* **Brand:** indigo ramp (`--brand-50…950`, `#6366f1`/`#4f46e5` core).
* **Accent:** fuchsia (`--accent-400…600`) used for gradient text/highlights.
* **Semantic:** `success` (emerald), `warning` (amber), `danger` (rose), `info` (blue).
* **Neutrals:** slate ramp.
* **Type:** `Inter` (sans/UI), `Fraunces` (display headings), `JetBrains Mono` (code/data) — loaded via Google Fonts with `preconnect`.
* **Shape:** rounded-2xl cards, `--shadow-card`, hover lift.
* **Motion:** `fade-in` / `slide-up` keyframes, with a `prefers-reduced-motion` guard.

## Components

Buttons (`.btn .btn-primary|secondary|ghost|success|danger`, sizes `btn-sm`/`btn-lg`,
`btn-block`, disabled state), cards (`.card`, `.card-hover`, `.card-title`,
`.card-meta`), forms (`.label`, `.input`, `.select`, `.textarea`, `.field`,
`.hint`, `.form-error`, `.checkbox`), badges (`.badge badge-*`, `.badge-dot`
status pill), alerts (`.alert alert-*`), tables (`.table`), navigation (`.nav`,
`.nav-link`, `.sidebar`, `is-active`), layout helpers (`.container`,
`.stack`, `.row`, `.grid-3`, `.grid-4`), and utility helpers (`.muted`,
`.gradient-text`, `.animate-*`, responsive visibility).

See `/design-system` in the running app for the live inventory and color ramps.

## Homepages

Each keeps the personality from the wireframes but now uses the shared tokens:

* **AURORA** — premium SaaS: indigo→fuchsia gradient hero, glass stat cards, serif display.
* **NEXUS** — dark enterprise: `#0b0f1a`, cyan accents, mono eyebrow, infra copy.
* **PULSE** — bright marketplace: pill search, category chips, rose accent, quick stats.

The `Home` controller still switches on `settings.active_homepage`; the admin
preview `?preview=…` remains admin-only and unpersisted.

## Follow-ups (later sessions)

* Session 05 builds out the three homepages into the full multi-section layouts
  (popular-services carousel, how-it-works, platforms, pricing tiers, FAQ,
  testimonials, CTA) on this token foundation.
* Dashboard/admin views in Sessions 06+ adopt the component classes as they are
  built; the placeholder shells already use them.
* Replace the Google Fonts `<link>` with self-hosted font files for full
  offline/performance hardening (Session 18).
