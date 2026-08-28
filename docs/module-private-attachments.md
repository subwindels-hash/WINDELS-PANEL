# Module 17 — private support attachments

*Branch `arena/01a04558-windels-panel`. Follows module 16 (site chrome).*

The first of the open items from [unfinished.md](unfinished.md), and the only
one where a stranger could read a paying customer's documents. Two related
questions, both answered with real HTTP requests rather than a code review:

1. Can someone who has the URL of a support attachment read it?  **They could.**
2. Can a refunded buyer still fetch the file with a link captured before the
   refund?  **They could not — but nobody had ever tested it, and the fixture
   that was supposed to prove it was fetching nothing at all.**

---

## 1. Unguessable is not authorised

Every ticket attachment was written to `assets/uploads/<32 hex>.png` and served
by the web server itself. No session, no ownership check, no expiry. The only
thing between a stranger and the file was the filename being random.

That is a weak control for a holiday-snap avatar and completely the wrong one
here. A support attachment is where customers put the things support asks for:
a bank statement showing the transfer, the passport photo behind an identity
order, a screenshot of a failed checkout with the account visible. And a URL
leaks in ordinary, non-adversarial ways:

- a customer forwards the ticket email to their accountant;
- the panel sends a `Referer` header when the file view links onward;
- support shares their screen on a call;
- browser history on a shared machine;
- a staff member leaves, taking their bookmarks with them.

Once leaked, the link was good for ever. Closing the ticket, refunding the
order, deleting the account, sacking the agent — none of them changed anything,
because nothing was ever checked at fetch time.

### What it is now

`MediaService::PRIVATE_PURPOSES` (currently `ticket`) writes to
`storage/ticket_attachments/` — the same storage root the logs and cache use,
already refused by `.htaccess` and, on a properly configured host, outside the
document root entirely. Files are `chmod 0600`: PHP reads them, the web server
never does.

The `media` row's `url` becomes `/support/attachment/<public_id>`, so the
thread view — which renders `file_url` straight into an `<a href>` — got the
authorised path without a single change. `Attachment::ticket()` answers three
questions on every request:

| Question | Enforced by |
|---|---|
| Is anyone signed in? | `Auth_Controller` |
| Is this their ticket, or are they staff? | `TicketService::may_read_attachment()` |
| Is it on an internal note? | staff only, always |

The rule is a pure static function rather than an `if` buried in the
controller, because an access rule no test can interrogate is an access rule
nobody can trust. It refuses in four shapes: signed out, signed-in stranger,
the ticket's own customer asking for an **internal note** (staff write those
*about* the customer; the thread already hides the message, so serving the file
would leak exactly what the flag protects), and an orphan upload, which only
its uploader may read.

Every refusal is **404, never 403** — a 403 confirms the id exists. Bytes go
out as `Content-Disposition: attachment` with the sniffed MIME type,
`X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`: a
customer-supplied file must never render inline in the panel's own origin, and
a shared proxy must never hand one customer's document to the next.

### The files that already exist

Migration **029** moves them. For every `media` row with purpose `ticket` it
relocates the file, rewrites `media.url` / `media.storage_key` **and**
`ticket_attachments.file_url` (the rendered link — rewriting only `media` would
have left every existing thread pointing at a deleted file). It is
re-runnable, and `down()` is deliberately a no-op: rolling back would copy
identity documents back into a public directory, and a migration must never be
the thing that re-opens a leak.

The old URLs stop working the moment it runs. That is the point.

## 2. The revocation question, and a fixture that proved nothing

Module 11 left this open: *"a URL captured before revocation still resolves to
the file until the storage key is rotated."*

`marketplace_fulfilment_check.mjs` now does what that sentence describes.
It issues a real signed link while the purchase is good, fetches it (200,
real PDF bytes), lets the 40-day escrow sweep refund the order, and then
**replays the captured URL**. The result: refused, with *"This download has
been revoked"*, and a fresh link cannot be minted either.

So the concern was unfounded — `resolve_download()` re-checks the revoked flag
and the download limit on every single request; the signed token is a
credential, not a capability. The module doc was describing a risk the code
did not have.

But the check could not have told anyone that, because **the fixture was
fetching nothing**. It inserted a `digital_products` row whose `storage_key`
was `storage/digital/e2e-<stamp>.pdf` — a path that pointed at no file and did
not even match the `digital_products/` prefix that `ShopDeliveryService`
confines lookups to. Every download in that check had always failed as
`MISSING_FILE`; the script only ever inspected database columns, so nothing
noticed. The fixture now writes a real PDF into the real private store (and
deletes it in cleanup), which is what turned the revocation stages into
evidence.

*The test double was corrected; no assertion was weakened.*

---

## 3. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php   # 1442 tests, 16834 assertions, 0 failures
node tools/devserver/attachment_check.mjs --admin-password '…'            # 25/25
node tools/devserver/marketplace_fulfilment_check.mjs --admin-password '…' # 20/20
```

`tests/unit/PrivateAttachmentsTest.php` (15 tests) pins where the bytes land,
that public purposes are untouched, that the recorded URL contains no file
path, that path resolution is confined to the private directory (including a
`../../../.env` row), that deletion still finds the file, all four access-rule
verdicts, the route, the controller's 404-only refusals and download-only
headers, the model lookup, and that migration 029 is registered and
irreversible.

`tools/devserver/attachment_check.mjs` (25 checks, now stage 6 of
`tools/verify_all.sh`) uploads a document as a real customer and then asks for
it as four different parties:

| Asking | Result |
|---|---|
| the owner | 200, byte-for-byte the uploaded file |
| a signed-out stranger with the link | no bytes |
| a signed-in stranger with the link | 404 |
| support staff | 200 |
| the owner, for an internal note on their own ticket | 404, and the link is not even rendered |

It also asserts the bytes exist under `storage/ticket_attachments/`, do **not**
exist under `assets/uploads/`, that the old public URL shape 404s, and that the
thread never renders a raw upload path.

Regressions: `support_check` 21/21, `security_check` 30/30, full
`verify_all.sh` green.

---

## 4. Still open

- **Only `ticket` is private.** `avatar`, `blog`, `branding`, `service` and
  `marketplace` media are public by design — they are rendered on public
  pages. If a future feature accepts customer documents (KYC uploads, payout
  proofs), it must be added to `PRIVATE_PURPOSES`, not merely stored with a
  random name.
- **Files already leaked before the upgrade are still leaked.** Migration 029
  breaks every old URL, which is the most that can be done from here; anything
  a third party already downloaded is gone.
- **No virus scanning.** Uploads are type-checked and never executed, but the
  panel will happily hand a staff member a malicious PDF that a customer sent.
  ClamAV at the storage boundary is the deployment-level answer.
- **`storage/` is inside the document root in this repository layout** and is
  protected by `.htaccess` plus the dev server's deny list. On shared hosting
  where `.htaccess` is honoured that is sound; the stronger arrangement, which
  the deployment doc recommends, is a storage path set outside `public_html`
  via `STORAGE_PATH`.
