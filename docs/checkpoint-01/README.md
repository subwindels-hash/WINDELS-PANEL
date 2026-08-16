# WINDELS PANEL — Checkpoint 01: Foundation Review

> **Date:** 2026-08-16
> **Branch:** `arena/01a00cd1-windels-panel`
> **Required action:** REVIEW & APPROVE before implementation
> Per spec §101 / §106 — five artifacts must be reviewed before coding.

---

## Purpose

This checkpoint translates the full WINDELS PANEL rebuild spec (§1–106) into executable architecture. Nothing here copies proprietary SmartPanel code; the old script is used only as a functional reference per §1/§105.

---

## Artifacts

| # | File | What it covers | Spec sections |
|---|---|---|---|
| 1 | [01-folder-structure.md](./01-folder-structure.md) | Monorepo layout (apps/web, apps/api, apps/worker, packages/*, prisma, docker, k8s, docs) | §3–5, §80–83 |
| 2 | [02-prisma-schema.md](./02-prisma-schema.md) | Full PostgreSQL/Prisma schema: Identity, Wallet/Ledger, Services, Providers, Orders, Refill/Cancel/Drip/Subscriptions, Payments, Tickets, Referrals, Content, Security, System | §16–18, §21, §24–26, §30–35, §40–46, §50–59, §68–70, §85 |
| 3 | [03-module-dependency-map.md](./03-module-dependency-map.md) | NestJS modules, Next.js features, BullMQ queues/workers, extension points, build order | §4, §19–23, §28–33, §37–40, §48–49, §65–66, §80 |
| 4 | [04-api-endpoint-map.md](./04-api-endpoint-map.md) | Complete `/api/v1` endpoint map (public, auth, wallet, services, providers, orders, drip/sub, payments, tickets, referrals, admin, reseller API, webhooks, OpenAPI) | §37–39, §71–73, §74–76 |
| 5 | [05-homepage-wireframes.md](./05-homepage-wireframes.md) | Three genuinely different homepage layouts — AURORA (premium SaaS), NEXUS (dark enterprise), PULSE (bright marketplace) + switcher + responsive + SEO | §6–9, §60, §77, §93 |

---

## Feature Parity & Security Decisions

### SmartPanel → WINDELS mapping ( §102 → modules)

Every item in the §102 Feature Parity Checklist is assigned to a module in Artifact 3. Highlights:

* **Provider system:** `ProviderAdapter` interface + `StandardSmmProvider` — no provider code scattered through controllers ( §19).
* **Financial ledger:** `wallets → wallet_transactions → ledger_entries`, `NUMERIC(20,8)`, transactional wallet mutations ( §24–25, §56).
* **Order state machine:** explicit `OrderStatus` enum + valid transitions; `order_status_history` with source tracking ( §26, §29).
* **Idempotency:** `IdempotencyKey` + unique constraints on webhook/order/ledger keys ( §64).
* **Queues:** 13 BullMQ queues replacing PHP crons, with Redlock + retry + DLQ ( §65–66).
* **Reseller API:** `/api/v1` with OpenAPI at `/api/docs`, per-key rate limiting via Redis ( §37–39, §71–72).

### Features deliberately NOT carried forward ( §103)

| Dropped | Replacement |
|---|---|
| Envato purchase-code / license server | No `WINDELS_LICENSE_KEY`; installer has no license step ( §81); `APP_ENV=demo` gates demo via feature flags only |
| `CURLOPT_SSL_VERIFYPEER=false` | `SecureHttpService` with mandatory TLS verification ( §62–63) |
| Frontend direct provider calls | All provider I/O via queues + `SecureHttpService` ( §22, §63) |
| Balance without ledger | Ledger is source of truth ( §24) |
| Synchronous cron URLs | BullMQ scheduled jobs ( §66) |
| Arbitrary plugin uploads | Deployed-code extension system ( §80) |
| Legacy CodeIgniter/jQuery theme | Next.js + NestJS + Tailwind + shadcn/ui ( §3) |

---

## What Happens After Approval

On approval, Sessions 01–20 (§99) proceed in order:

```
01 Foundation → 02 Database → 03 Auth → 04 Design System → 05 Three Homepages
→ 06 Customer Dashboard → 07 Services → 08 Providers → 09 Order Engine
→ 10 Advanced Orders → 11 Payments → 12 Reseller API → 13 Support+Content
→ 14 Affiliate → 15 Admin → 16 Workers → 17 Security Hardening
→ 18 Performance → 19 Testing → 20 Production Release
```

Per §100, a module is **COMPLETE** only when frontend + backend + database + validation + authorization + error handling + tests + audit logging + docs + integration are done.

---

## Open Questions for Reviewer

1. **Currency:** single-currency ledger (`USD` base, `currencies` table for display + exchange) vs multi-currency ledger? Current plan is single-currency per §57 (no mixing without explicit conversion records).
2. **Encryption at rest:** `ENCRYPTION_KEY` (AES-256-GCM) for provider API keys + payment secrets — confirm key management approach (env vs KMS).
3. **Homepage default:** `AURORA` as initial `activeHomepage` — confirm.
4. **PWA scope:** PWA-ready architecture now (manifest + service worker shell), full offline later — confirm.

---

## How to Approve

Comment approval on the branch or reply to the checkpoint. After approval, implementation begins with **Session 01 — Foundation** (monorepo, Docker, CI/CD, env).
