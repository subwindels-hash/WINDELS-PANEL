# Module 9 — authorisation, proved

*Branch `arena/01a04558-windels-panel`. Follows module 8 (service recovery).*

This module is different from the eight before it. Those each opened with a
defect that cost money. This one opened with a question nobody in the project
could answer without reading twenty files: **is every admin endpoint actually
gated, and does every permission the panel grants actually gate something?**

The answer, after auditing all 195 admin routes and driving the panel as a
real, deliberately under-privileged member of staff, is *yes* — with one
exception, which was in the tool that was supposed to tell operators the
answer.

---

## 1. What was broken

### `RbacService::unenforced()` counted a mention as a check

The Roles and permissions screen shows operators which ticks currently do
nothing — "a permission with no check behind it is a promise the code does not
keep". It decided that by concatenating every PHP file under `controllers/`,
`libraries/` and `core/` and asking whether the literal `'vtu.refund'` appeared
**anywhere** in the result.

Every permission key also appears in:

- the navigation tree (which link to draw),
- tab and queue definitions,
- `$this->auth->can(...)` calls that decide whether to render a button.

So a permission used only to *hide a link* was reported as enforced. That is
precisely the false reassurance the screen exists to prevent: the operator is
told the tick means something, ticks it, and the endpoint behind the hidden
button is still open to anyone who types the URL. The detector could never have
found the class of bug it was written to find.

Nothing else in the audit came back dirty — and that is worth stating plainly,
because the value of this module is the proof, not a patch.

---

## 2. What the code does now

### The detector is precise in both directions

`unenforced()` now counts a key as enforced only when it reaches a **gate**:

1. a literal argument to `require_perm()` / `require_any_perm()`;
2. a literal passed to a helper that forwards it to a gate — the shared
   `private function guard($public_id, $perm) { …; $this->require_perm($perm); }`
   used by half the admin controllers. Detected by *shape* (a helper whose body
   gates a variable), so a new controller following the same pattern is covered
   without being named;
3. a key reached through a declared map that a gate reads dynamically — the
   `admin/Operations` queue table (`require_perm(self::$queues[$q][1])`) and
   `ContentService::permission($domain)`. Recognised only when the same source
   also contains the gate that reads them, so an ordinary lookup table cannot
   launder a key into looking enforced.

`can()` and `has_perm()` are deliberately **not** gates. They answer "should I
draw this?", never "may you do this?".

---

## 3. The proofs

### Static — `tests/unit/AuthorizationMatrixTest.php` (5 tests, 195 routes)

Reads `config/routes.php`, resolves every admin route to the method behind it
(including the private helpers that method calls), and asserts:

- **every routed admin URL resolves to a method that exists** — a routed URL
  with nothing behind it is a dead screen, which is exactly how the refill and
  cancellation queues shipped in an earlier session;
- **every entry point is behind a permission** (constructor or method);
- **everything that writes is POST-only** — either it refuses non-POST, or it
  is the form-and-save pattern where the write lives inside an explicit
  `if (method === POST)` branch. A state change reachable by GET can be fired
  by an `<img>` tag on any page a signed-in admin visits;
- **every permission in the seeder's catalogue gates something**, using the
  same rule written out independently — a detector that grades its own homework
  cannot fail, and this test exists to catch the detector too;
- the detector no longer accepts a mention.

Adding a twenty-first admin controller without a guard now fails the suite.

### Live — `tools/devserver/security_check.mjs` (31 checks)

**Attack shapes**, as a second registered customer and as a stranger: another
customer's order, ticket, service receipt and deposit are unreadable (404, not
"forbidden but rendered"); cancelling, refilling and closing their records is
refused; `/admin`, `/admin/customers`, `/admin/settings`, `/admin/wallets` and
`/admin/staff` all answer 403; `role`, `status` and `price_group_id` smuggled
into the profile form change nothing; a POST with no CSRF token is refused; the
session identifier changes on sign-in; session cookies are HttpOnly with
SameSite; an unknown account and a wrong password produce byte-identical
messages.

**The RBAC matrix, live** — the part that had never been run. The STAFF role is
narrowed to `orders.view`, `reports.view`, `vtu.view`; a real staff account is
created and signed into the admin area; then five money-moving POSTs across
five domains are attempted:

| Endpoint | Needs | Result |
|---|---|---|
| `POST /admin/orders/:id/refund` | `orders.refund` | 403 |
| `POST /admin/orders/:id/cancel` | `orders.cancel` | 403 |
| `POST /admin/vtu/:id/refund` | `vtu.refund` | 403 |
| `POST /admin/customers/:id/adjust` | `wallets.adjust` | 403 |
| `POST /admin/settings/save` | `settings.manage` | 403 |

Then exactly one permission is granted, and the check proves **exactly one
endpoint opened** and the others stayed shut. Finally, the limited account
tries to grant itself `staff.manage` through `/admin/staff/permissions/STAFF`:
refused, and the grid is verified unchanged in the database.

The STAFF role's real grants are snapshotted and restored in a `finally`, and
the restoration itself is asserted.

```
node tools/devserver/security_check.mjs --admin-password '…'
31/31 checks passed
```

**Suite: 1369 tests, 16400 assertions, 0 failures, 1 skipped.** Regressions all
green: `smoke` 24/24, `journey` 38/38, `commerce_check` 24/24, `admin_check`
18/18, `ux_separation_check` 58/58, `refunds_check` 32/32, `analytics_check`
19/19, `service_recovery_check` 17/17, `notifications_check` 22/22, `api_check`
31/31, `page_audit` (0 failing pages).

### One tooling fix along the way

The security check's login-enumeration probe originally used the demo account
for its "wrong password" arm. Failed attempts count towards that identifier's
lockout, so running the check locked the account every other e2e check signs in
with — a self-inflicted denial of service on the test suite. It now uses the
throwaway account it just registered and deletes the failed attempts it
created. `refunds_check` was also made resilient to the sandbox's occasional
inability to open an outbound socket from a wasm worker: that produces the
*transport* case rather than the refusal case, so the check now drives the
worker instead of reporting a failure that is not the panel's.

---

## 4. What this does not cover

- **Authentication strength itself** — password hashing, MFA enrolment and the
  transaction PIN have their own tests (`AuthRbacTest`, `pin_check`,
  `pin_rotation_check`) and were not re-audited here.
- **Infrastructure** — TLS, HSTS in production, WAF rules, and the file
  permissions of `storage/` are deployment concerns; `Preflight` and
  `deploy-verify.php` check what they can from inside PHP.
- **Impersonation** is covered by `ImpersonationTest` and `admin_check`
  (banner, audit trail, exit). A dedicated matrix of "who may impersonate
  whom" would be the natural next step if the staff hierarchy grows.
- No penetration testing tool was run: everything above is hand-written request
  shaping against the real application.
