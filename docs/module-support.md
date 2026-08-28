# Module 10 — support: the assistant, the limiter, and attachments

*Branch `arena/01a04558-windels-panel`. Follows module 9 (authorisation).*

Two defects, neither of which had ever failed a test, because neither made
anything *incorrect* — they made the panel unusable in ways that arrive at
support as "the site is broken".

---

## 1. Using the help widget locked you out of signing in

`login_attempts` is the panel's only throttling store, and every throttled
feature writes to it: sign-in, admin sign-in, MFA, registration, password reset
and the on-site assistant. Each namespaced its **identifier**
(`assistant:1.2.3.4`, `pwreset:someone@example.com`), so the per-account
counters were properly separate.

The **per-IP** counter had no such separation. It counted every failure row for
the address, whoever wrote it. The login limiter allows `5 × IP_MULTIPLIER`
(15) per address per 15 minutes; the assistant allows 20 questions per hour and
records every question as a row. So:

> Sixteen **answered** assistant questions put the visitor's IP over the login
> lockout, and the login page then said *"Too many failed attempts. Try again
> in 15 minutes."*

Nothing had failed. A visitor who used the help widget locked **themselves**
out of signing in, and behind an office or mobile NAT, one chatty visitor
locked out everyone. A handful of password-reset requests did the same. It also
worked in reverse: fifteen wrong passwords across the network silenced the
assistant and blocked registration.

This was reproduced before it was fixed — 16 questions, then a correct
password, then the lockout page — and the same effect bit this very session:
the security check's login probe kept locking the demo account that every other
end-to-end check signs in with.

### The fix

**Migration 028** adds `login_attempts.scope` (`login`, `admin_login`, `mfa`,
`register`, `pwreset`, `assistant`, `chat`) with indexes on
`(scope, ip, created_at)` and `(scope, email, created_at)`, and backfills it
from the identifier prefix already in the data.

`RateLimiter` derives the scope from the identifier — no caller had to change —
and filters **both** counters by it. An unrecognised prefix falls back to
`login`, which is the conservative answer: forgetting to add a new feature's
name is a missed improvement, never a missing limit. On a database that has not
run the migration yet, the counters stay shared exactly as before; emulating
the split with a `LIKE` over the busiest table in the panel was tried and
rejected for the sake of a window that ends when `migrate` does.

The protection itself is unchanged: five wrong passwords against one account,
or fifteen from one address, still lock sign-in.

---

## 2. Ticket attachments existed everywhere except in the product

The `ticket_attachments` table shipped in the support migration. Every
`TicketService` write already took an `$attachments` argument and wrote those
rows. `MediaService::PURPOSES` already contained `'ticket'`.

**No controller ever passed a file.** A customer could not send the screenshot
that is the entire content of most support requests, and staff could not send a
receipt back — so every such conversation happened by asking the customer to
describe what they saw.

### The fix

- `TicketService::attachments_from_upload()` turns `$_FILES['attachments']`
  into the array the service has always accepted, storing each file through
  **MediaService** — so a ticket upload inherits exactly the validation the
  media library had: sniffed MIME (not the browser's claim), an image that must
  actually decode, size cap, generated filename. Capped at 5 files per message.
- The customer's new-ticket form, the customer's reply form and the staff reply
  form are now multipart with a file input; rejected files produce a warning
  the customer can read rather than a screenshot that silently vanishes.
- `Ticket_message_model::for_ticket()` loads each message's attachments in
  **one** extra query for the whole thread, not one per message.
- A shared `partials/ticket_attachments.php` renders them identically on both
  sides, escaping the customer-supplied file name.

---

## 3. How it was verified

### Unit / integration — `tests/unit/SupportTest.php` (9 tests)

Twenty assistant questions do not lock sign-in, while the assistant's own cap
still trips; password-reset requests do not spend the login budget; five wrong
passwords against an account and fifteen from an address still lock out; the
scope is derived, not guessed, with an unrecognised prefix falling back to
`login`. Attachments: stored and read back with their message, never bleeding
between messages, an empty thread answers with an empty list (the view iterates
it), the cap holds, and both controllers hand uploads to the validated pipeline
rather than touching `$_FILES` themselves.

**Suite: 1378 tests, 16486 assertions, 0 failures, 1 skipped.**

### End-to-end — `tools/devserver/support_check.mjs` (21 checks)

Against the running panel: the assistant answers with real text; 18 questions
are recorded under the `assistant` scope; **a customer can still sign in
afterwards**; the 20-per-hour cap still returns 429 with a readable message;
six failed sign-ins do not silence the assistant, but the sign-in lockout
itself still works. Then a real 1×1 PNG is uploaded with a new ticket over
multipart HTTP and read back from the thread; a `.php` file declaring itself
`image/png` is refused, stores nothing, and the customer is told why while
their reply still posts; and a member of staff attaches a file the customer can
then see.

```
node tools/devserver/support_check.mjs --admin-password '…'
21/21 checks passed
```

Regressions all green: `smoke` 24/24, `journey` 38/38, `commerce_check` 24/24,
`admin_check` 18/18, `security_check` 31/31, `content_check` 18/18,
`page_audit` (0 failing pages), `npm run test:js` 13/13.

### Test doubles improved, never weakened

Two gaps in `FakeDb` made these tests impossible to write honestly, and both
were fidelity bugs rather than missing conveniences:

- **`conn_id` was absent**, so `marvy_load_database()` — which production code
  calls before writing a rate-limit row — reported the database as down. Every
  such write silently no-opped in tests, meaning the throttling code had never
  actually been exercised by the suite.
- **`field_data()` was missing**, so code asking "has this migration run yet?"
  fell into its pre-migration branch, testing a path production would not take.

`RateLimiter`'s "does the column exist" memo was also moved from static to
per-instance: the schema cannot change inside a request, but a long-lived
process that outlives a migration — a worker, a test runner — would otherwise
cache the answer from before it ran.

---

## 4. Still open

- **Attachment URLs are unguessable, not authenticated.** Files land in the
  media library's public directory with a 32-hex-character generated name, so
  the link cannot be enumerated, but anyone holding the URL can fetch it
  without a session. Serving them through an ownership-checked route would need
  the upload directory moved outside the document root — a deployment change,
  not a code one, and worth doing before tickets are used for anything more
  sensitive than screenshots.
- **The assistant is deliberately not an LLM.** It answers from a local
  knowledge map, so there is no prompt-injection surface and no third-party
  data sharing — and equally, no ability to answer anything outside the map.
  The cap and the wording are tuned for that.
