# WINDELS PANEL — Session 13: Support & Content

> Customer support tickets (with messages) plus public content — FAQ, blog and
> site announcements — backed by the migration-008 tables.

## What shipped

| Area | Files |
|---|---|
| Ticketing service | `libraries/TicketService.php`, `models/Ticket_model.php`, `Ticket_message_model.php` |
| Customer tickets | `controllers/dashboard/Tickets.php`, `views/dashboard/tickets/{index,detail}.php` |
| FAQ / announcements / blog | `models/{Faq,Announcement,Blog_post,Blog_category}_model.php` |
| Public blog | `controllers/Blog.php`, `views/public/blog/{list,detail}.php` |
| Public FAQ | `Home::faq` now loads live data, `views/public/faq.php` |
| Announcements | rendered in `layouts/public.php` when active/time-windowed |
| Tests | `tests/unit/SupportContentTest.php` |

## Support tickets

* **Open** (`POST /dashboard/tickets/create`): subject, message, optional
  department/priority, and an optional linked order (validated by public ULID so
  a customer can only reference their own orders). Creates the ticket and the
  first message transactionally.
* **Detail / reply**: conversation view; customer replies are never internal
  notes (`is_internal_note = 0`). Replying to a `CLOSED` ticket reopens it and
  clears `closed_at`. A close action is provided.
* **Scoping**: every lookup is `where(public_id)->where(user_id)`; internal
  staff notes are filtered out of the customer view (verified by test).
* All mutations are POST-only and CSRF-protected.

## Content

* **FAQ** (`/faq`): grouped by category, semantic `<details>` accordions, with
  a link to open a ticket for logged-in customers. Only `is_active` rows shown.
* **Blog** (`/blog`, `/blog/:slug`): only `PUBLISHED` posts whose
  `published_at` is in the past; category filter, pagination, related posts,
  and a view counter on detail. Content is trusted staff HTML rendered in a
  `.ws-prose` container (customers never write blog content).
* **Announcements**: the public layout loads active, time-bounded announcements
  scoped to the viewer (`all` vs `customers` vs `staff`), with severity-based
  banner colors.

## Safety

* Customers can never read another user's ticket or internal notes.
* Ticket subjects/messages are length-validated; order references are scoped.
* Blog content is staff-authored only; there is no public write path in this
  session (admin CRUD arrives with Session 15).
* No secrets are rendered; file-attachment rows are accepted as already-uploaded
  URLs (upload validation is a separate, size/type-checked step).

## Follow-ups

* **Session 15 (Admin)** — staff ticket queue, replies/internal notes, FAQ/blog
  CRUD, and announcement management behind the relevant permissions.
* File upload endpoint with MIME/size validation and virus scanning policy for
  ticket attachments.
