# WINDELS PANEL — Session 05: Three Homepages

> Builds the complete, responsive marketing homepages on the Session 04 design
> system. Each page has a distinct identity per the approved wireframes
> (`docs/checkpoint-01-php/05-homepage-wireframes.md`).

## What shipped

| Homepage | File | Identity |
|---|---|---|
| AURORA | `views/homepages/aurora/index.php` | Premium SaaS — indigo→fuchsia gradients, glass stat cards, serif display |
| NEXUS | `views/homepages/nexus/index.php` | Dark enterprise — `#0b0f1a`, cyan/violet, mono data labels, API/network focus |
| PULSE | `views/homepages/pulse/index.php` | Bright marketplace — pill search, category chips, rose/amber, mobile-first |
| Tests | `tests/unit/HomepageTest.php` | Section completeness, distinctness, a11y, no license/insecure artifacts |

No controller/routing changes were required: `Home::index()` still switches on
`settings.active_homepage` (admin `?preview=` remains unpersisted and guarded).

## Sections implemented

* **AURORA:** hero + trust badge, 4-up stats, 6 popular-service cards, 3-step
  "how it works", 12-platform logo wall, 8 category tiles, accordion FAQ,
  testimonials, gradient CTA band.
* **NEXUS:** hero with CSS flow diagram (Customer → Queue/Ledger/State machine
  → providers → Fulfillment, pulsing health dots), 4 KPI tiles, 6 provider
  health cards with sparklines, dense service-explorer table, 3 automation
  pillars, `POST /api/v1/orders` code sample, dark accordion FAQ, neon CTA.
* **PULSE:** centered pill search with platform chips, horizontally-scrollable
  category rail, 8-card "trending now" grid, quick-order panel with live
  (client-side) price estimate, star-rating reviews, light FAQ, accent CTA band.

All three share the public layout (`partials/public_nav.php` + `footer.php`) and
the Session 04 tokens/component classes, but each ships scoped `<style>` so its
personality never leaks into the others.

## Behavior & accessibility

* **Responsive:** mobile-first; AURORA stats/categories collapse to 2-col,
  NEXUS hero stacks and KPIs go 2×2, PULSE search goes full-width and the
  category rail scrolls horizontally with scroll-snap.
* **A11y:** semantic landmarks, `<details>/<summary>` accordions (keyboard
  accessible), `role="search"` + labelled inputs, `aria-label` on stat groups,
  decorative emoji/animations hidden from assistive tech, WCAG-AA contrast
  (NEXUS dark text combos verified against `#0b0f1a`).
* **Motion:** CSS only + one vanilla-JS live-price calculator on PULSE (no
  network call per the wireframe). NEXUS's pulsing provider dots and all
  transitions honor `prefers-reduced-motion`.
* **Data:** the service/provider/review content is static marketing copy — live
  catalogs and pricing arrive in Session 07 (Services) via `PricingService`.
  The PULSE quick-order price is explicitly labelled an estimate.

## Verification

```bash
npm run build:css     # rebuilds assets/css/tailwind.css from the views
php tools/phpunit_lite.php HomepageTest
```

The offline test asserts every required section is present, the three pages are
visually distinct, forms are search-role/mobile-first, accordions are semantic,
no license/insecure-TLS strings appear, and any animated page honors reduced
motion. The Tailwind build was run and confirmed to include every utility used.

## Follow-ups (later sessions)

* Session 07 replaces the static "popular/trending/explorer" cards with real
  paginated, FULLTEXT-searchable service data and wires PULSE's quick-order to
  `PricingService`.
* Session 13 adds the blog/testimonials CMS behind the testimonial section.
* Session 18 self-hosts the Inter/Fraunces web fonts (currently Google Fonts)
  for performance and offline hardening.
