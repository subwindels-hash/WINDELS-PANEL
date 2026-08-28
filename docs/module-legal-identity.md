# Module 19 — naming the operator

*Branch `arena/01a04558-windels-panel`. Follows module 18 (the coupon race).*

Item 9 of [unfinished.md](unfinished.md), closed. The smallest change in this
series and one of the more consequential ones: the panel could take deposits
without ever saying who was taking them.

---

## 1. "The party that deployed this instance"

That is what the Terms said, verbatim, where the trader's name belongs. The
Privacy Policy matched it: *"the operator of this deployment is the controller
of personal data … identity, address and a Data Protection Officer must be
published by that operator."* Section 20, Governing law, deferred to *"the
operator's principal place of business"* — a place the page never named.

It was honest. It was also useless to the person reading it, who could not
determine:

- **who they are contracting with** — the trader behind a prepaid wallet;
- **where to serve a notice** — there was no registered address anywhere;
- **whose law applies** — no jurisdiction, so no forum, so no idea what
  consumer protections they had;
- **who controls their personal data**, or which regulator to complain to.

And there was no way to fix it short of editing PHP. No setting, no admin
field, nothing. Every operator who deployed this panel shipped a Terms page
that said nobody in particular was responsible.

For a panel that holds customer balances that is not cosmetic. Most consumer
regimes require the trader to be identified in the terms, and GDPR-style law
requires the controller to be named in the privacy notice. The page a customer
reads immediately before their first deposit was the one saying nobody was
home.

## 2. It is a setting now, and nothing is invented

New settings group **Legal and company details** (Admin → Settings):

| Setting | Purpose |
|---|---|
| `legal_entity_name` | the trader, as registered |
| `legal_registration_number` | RC/company/VAT number — blank for a sole trader |
| `legal_registered_address` | where notices are served |
| `legal_jurisdiction` | whose law governs |
| `legal_courts` | where disputes are heard (defaults to the jurisdiction) |
| `legal_contact_email` | notices (defaults to the support address) |
| `legal_dpo_contact` | privacy contact (defaults to the legal one) |
| `legal_supervisory_authority` | the regulator a customer may complain to |

`LegalIdentity` resolves them, applies those fallbacks, and answers three
questions the views need: `is_published()`, `missing()` and `line()`.

**Every default is empty on purpose.** A legal page that invents a company is
worse than one that admits it does not know, so an unpublished identity
produces wording that says exactly that — *"The operator has not published
their legal details yet"* — and names the outstanding fields, so the person who
can fix it learns what is missing by reading their own site. `line()` returns
an empty string rather than `", "` or a lone comma, and the footer renders
nothing at all rather than an empty *Operated by*.

Three required fields decide it: name, address and governing law. A trading
name with no address is not an identity, and an address with no governing law
leaves section 20 unanswerable. The rest improve the pages without being
load-bearing.

Once published:

- **Terms** open by naming the entity, its registration number and its
  registered address, with the notice email; section 20 states the governing
  law and the courts, and the *"requires review by the operator's legal
  counsel"* caveat is withdrawn.
- **Privacy** names the controller and its address, gives the data-protection
  contact, and — where one is configured — tells the customer which
  supervisory authority they may complain to.
- **The footer**, on every page, carries *"Operated by Marvy Digital Ltd (RC
  1234567), 12 Broad Street, Lagos Island, Lagos, Nigeria."*

## 3. Telling the operator, not just the customer

An operator who never opens their own Terms page would never learn any of
this. `Preflight` gained `legal_identity`, so `php index.php preflight` and the
deployment check report *"not published: legal entity name, registered address,
governing law"* with the exact screen to fix it on.

It is a **WARN, never a FAIL**. The software works; unfinished paperwork must
not stop a panel booting, and a brand-new install refusing to start because the
company is not registered yet would help nobody.

---

## 4. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php  # 1466 tests, 16995 assertions, 0 failures
node tools/devserver/legal_check.mjs --admin-password '…'    # 26/26
bash tools/verify_all.sh --admin-password '…'                # 45 passed, 0 failed
```

`tests/unit/LegalIdentityTest.php` (11 tests): a fresh install publishes
nothing and says so; a published identity reads back whole with a multi-line
address flattened for prose; the registration number is optional and leaves no
`()` behind; contacts fall back rather than pointing at nothing; the identity
degrades to "unpublished" with no database at all, because a legal page must
render on a bad day; every field is in the settings catalogue under the
`legal` group **with an empty default**; the admin screen titles the group;
and the three surfaces plus the Preflight WARN are pinned.

`tools/devserver/legal_check.mjs` (26 checks, now stage 6 of
`verify_all.sh`) does what an operator does: blanks the settings, reads the
public pages, fills the details in **through the real admin form**, reloads,
and asserts the wording changed on Terms, Privacy, the footer and the
homepage — then checks a half-filled identity still reads as unpublished with
no trailing comma, and **restores the operator's own settings**, because those
are live values on whatever instance the sweep runs against.

---

## 5. Still open

- **The pages are still not legal advice.** They describe what the software
  does, accurately, and now say who is doing it. The refund windows, the
  liability cap and the assistant disclaimer remain the operator's counsel's
  problem — the pages say so.
- **No per-country wording.** One Terms page serves every jurisdiction; a
  panel selling into the EU and Nigeria at once may need two.
- **`SiteOperatorKnowledge::EFFECTIVE_DATE` is still a constant in code.** The
  "last updated" dates on the legal pages move with a deploy, not with a
  setting. Worth changing the next time those pages are edited, so an operator
  amending their terms can date the amendment.
