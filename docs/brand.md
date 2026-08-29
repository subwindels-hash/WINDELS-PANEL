# Brand — the shipped mark, and how to replace it

*Item 8 of [unfinished.md](unfinished.md): "`assets/brand/*` is generated, not
designed." This is the design record that closes the reviewable half of that:
what the shipped artwork is, where every file is used, the verification that
the set is coherent, and the exact procedure for an operator to replace it
with their own identity. The final identity is the operator's to choose — no
panel can design a brand it does not own — but the placeholder it ships is
now a complete, documented system rather than a bag of generated rasters.*

## The mark

- **The monogram.** A near-black (`#0A0A0F`) rounded panel (25% corner
  radius) carrying the letter **M** in a single diagonal gradient,
  indigo `#6366F1` → fuchsia `#C026D3`, read top-left to bottom-right.
  The M is drawn as one closed path — no strokes, so it stays crisp from
  16px favicons to full-width footers.
- **The wordmark.** `MarvySocials` in Inter 800, tight tracking
  (`letter-spacing: -1`), slate-900 `#0F172A` on light chrome, white on
  dark chrome.
- **The palette** is the panel's own: the gradient is the same
  indigo→fuchsia the buttons, hero gradients and assistant read
  (`--brand-*` / `--accent-*` in `design-system.css`), so the mark and the
  UI cannot drift apart.

## The files

Every file in `assets/brand/`, its size, and what it is for. All sizes were
verified against the shipped set (ImageMagick `identify`); the SVGs are the
vectors the PNGs were rendered from.

| File | Size | Role |
|---|---|---|
| `logo.svg` / `logo.png` | 972×192 | Primary wordmark, light chrome (public header) |
| `logo-horizontal.*` | 972×192 | Same artwork as the primary — the partial's `horizontal` variant |
| `logo-dark.*` | 972×192 | Wordmark in white, for dark chrome (navbar, footer, auth shell) |
| `logo-white.png` | 972×192 | Monochrome white wordmark, grayscale-safe for dark surfaces |
| `logo-icon.*` | 256×256 | The monogram alone (sidebar, dashboard header) |
| `favicon.svg` | 80×80 | Vector favicon (modern browsers) |
| `favicon.ico` | 16+32+48 | Legacy favicon bundle |
| `favicon-16/32/48.png` | as named | Explicit favicon sizes |
| `apple-touch-icon.png` | 180×180 | iOS home-screen icon |
| `icon-192.png` / `icon-512.png` | as named | PWA icons (512 is also the `maskable` one) |
| `site.webmanifest` | — | PWA manifest: name, theme `#4F46E5`, background `#0B1020`, icon list |

The 972×192 wordmark ratio is **5.0625** — the number the layout code
reserves the logo box with (below).

## Where each variant is rendered

`partials/brand_logo.php` is the single renderer. Variants:

| Variant | Files tried (raster → SVG fallback) | Used by |
|---|---|---|
| `horizontal` (default) | `logo-horizontal.png`, `logo-horizontal.svg` | public header, styleguide |
| `full` | `logo.png`, `logo.svg` | error pages |
| `dark` | `logo-dark.png`, `logo-dark.svg` | navbar, footer, auth shell |
| `icon` | `logo-icon.png`, `logo-icon.svg` | sidebar (32), dashboard header (26) |

An administrator can override the **wordmark** from Admin → Appearance
(`brand_logo_url`); the icon mark is deliberately not overridable so the
sidebar and favicon never lose their shape.

## Reserving the layout box

`partials/brand_logo.php` carries the ratio table the browser uses to size
the logo before the image loads (no layout jump):

```php
$ratios = array('icon' => 1, 'horizontal' => 5.0625, 'dark' => 5.0625, 'full' => 5.0625);
```

**If you replace the wordmark artwork, update this table to the new
width÷height** — or the page will reserve the wrong box and jump on load.
The requested height always wins (`.ws-logo` renders at the height the
caller passes via `--ws-logo-h`); the ratio only fixes the width.

## Replacing the set (operator procedure)

1. Prepare your artwork as PNG (SVG works for the wordmark variants too):
   - wordmark light: 972×192 (or any size at the same 5.0625 ratio)
   - wordmark dark: same, white type
   - monochrome white: same
   - icon: 256×256, transparent background
   - icons: 192×192 and 512×512 (512 must survive `maskable` cropping —
     keep the mark inside the central 80%)
   - favicons: 16/32/48 + a multi-size `.ico`; `apple-touch-icon` 180×180
2. Overwrite the files in `assets/brand/` one-for-one (same names — the
   partial, the webmanifest and the layout ratio all key off the names).
3. If the wordmark ratio changed, update `$ratios` in
   `application/views/partials/brand_logo.php`.
4. Rebuild the deployment package (`bash tools/build_deployment_package.sh`)
   so the zip ships the new set, and confirm with
   `node tools/devserver/image_audit.mjs` (every `<img>` resolves) and a
   look at `/styleguide` (it renders all three variants side by side).
5. Update `site.webmanifest`'s `theme_color`/`background_color` if your
   palette differs, and the `apple-touch-icon` must stay 180×180.

## What is intentionally not here

- **No recolours.** The gradient is the brand. A recoloured mark on a busy
  background is how a logo stops being a logo.
- **No shadows, no bevels, no animation.** The mark is flat and static in
  every shipped placement.
- **The assistant avatar** (`assets/images/ai/avatar.jpg`) is part of the
  imagery system (indigo-navy with a fuchsia accent), not the mark; it is
  documented on the styleguide page alongside the brand colours.
