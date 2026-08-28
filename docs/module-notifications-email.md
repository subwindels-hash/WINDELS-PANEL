# Module: customer notifications and email

The panel had a `notifications` table, a `notification_preferences` table, a
bell in the topbar, a Notifications page, an email queue with a retry worker
and six seeded email templates. It used exactly one of them.

## What was missing

`grep` told the story: `enqueue_template()` was called from one place —
`auth.verify_email`. So:

| Event | What the customer got |
|---|---|
| Order completed | nothing |
| Order partially delivered and refunded | nothing |
| Order cancelled / refunded | nothing |
| Deposit credited to the wallet | nothing |
| Support replied to their ticket | nothing |

`order.completed`, `order.partial`, `payment.credited` and `ticket.replied`
were seeded, editable in Admin → Email templates, and **never sent**. Nothing
ever inserted a `notifications` row either, so the bell and the Notifications
page were permanently empty. There was also no way to see the mail queue, no
way to retry a failed message, and no way to prove the mail transport worked.

## What this adds

**`NotificationService`** — one place that decides what reaches the in-app
inbox, which email template goes with it, what the customer's preferences
allow, and (most importantly) that a notification failure can never break the
thing that just happened. Notifying is always the last step of a business
action: an order is complete whether or not the mail server answers, so every
failure is caught and logged.

Wired into the events that already existed:

- `OrderService::apply_status()` / `apply_partial()` → completed, partial,
  cancelled, refunded
- `PaymentService::confirm()` → wallet credited (webhook, admin approval and
  reconciliation all go through it)
- `TicketService::staff_reply()` → support replied, **never for an internal
  note** — that would leak the note's existence and promise a reply that is
  not there

**Customer preferences** (Dashboard → Profile → Notifications): per event,
in-panel and email. A missing row means both on, so a row is written only when
something is switched off and deleted when it is switched back on.

**Admin → Mail queue** (new): every message with its status, attempt count and
the actual delivery error, filterable, with **Retry** (which resets the attempt
counter so the worker's backoff starts again) and **Send test** — which sends
immediately rather than queueing, because an operator standing in front of the
screen is asking whether SMTP works, and "queued" does not answer that.

**Settings → Outgoing email**: `mail_transport` (mail / smtp / log),
`mail_from_email`, `mail_from_name` — read by `MailService` all along but never
exposed, so they could only be changed with SQL. Plus a global
`notification_emails_enabled` kill switch that leaves the in-app inbox working.

## Two bugs found while wiring it

1. **CodeIgniter silently destroys form keys containing a dot.**
   `_clean_input_keys()` rejects anything outside `[a-z0-9:_/|-]`, so
   `notify[order.completed][email]` arrived mangled and every preference save
   was a silent no-op. Event types now travel as `order__completed` and are
   translated back on save.
2. **A partial form submission would have switched everything off.** Unchecked
   checkboxes send nothing, so a form that did not render every event (an older
   page, a scripted post) would have disabled the events it did not know about.
   Each row now carries a `notify_rendered[…]` marker and only rendered events
   are touched — the same pattern the settings screen uses.

`NotificationService::write_in_app()` also reports the real outcome of the
insert instead of assuming success, so a rejected write cannot hide behind a
green result.

## Tests

- `tests/unit/NotificationServiceTest.php` — 13 tests: both channels written,
  in-panel-only events, unknown types refused, default-on preferences,
  per-channel opt-out, the global switch, **a mail failure and a database
  failure each leaving the caller unharmed**, plus source guards that every
  referenced template is seeded, that the owning services actually raise their
  events, and that an internal note never notifies.
- `tools/devserver/notifications_check.mjs` — 22 end-to-end checks: approving a
  deposit lands in the inbox, the bell and the queue; a staff reply notifies
  and an internal note does not; the cron worker drains everything due and
  marks it SENT; a failed message is visible and retryable with its attempts
  reset; and turning the email off in preferences stops the email while the
  inbox entry still arrives.

## Note for developers

The wasm dev server caches compiled PHP per worker: **restart
`tools/devserver/server.mjs` after editing PHP**, or you will be testing the
previous version of the file.
