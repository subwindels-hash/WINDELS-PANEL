# Module 21 — a link in the announcement bar

*Branch `arena/01a04558-windels-panel`. Follows module 20 (dashboard cost).*

Item 6 of [unfinished.md](unfinished.md), closed — plus a colour bug found
while closing it, which had been quietly ignoring one of the operator's
settings since module 16.

---

## 1. A notice nobody can follow

Module 16 made the banner editable, as plain text. That is the wrong stopping
point for the one strip that renders on every page of the panel, because the
messages operators actually want there are:

> Wallet funding is down until 14:00 — details here.
> New Instagram rates from Monday.

and both are useless if the customer cannot reach the page being talked about.
"Go and find it in the menu" is how an outage notice turns into a support
ticket, from the customers who were already having a bad time.

The obvious fix is to let the operator type HTML. That would put an unescaped
operator-controlled string on the login page, the customer dashboard and the
admin, for every visitor on every request — the highest-value XSS target in the
whole product. One compromised or careless staff account with settings access
would own every session in the panel.

## 2. A syntax with nothing to exploit

One shape, borrowed from Markdown because operators already recognise it:

```
Funding is down until 14:00. [Read more](/blog/funding-outage)
Questions? [Email us](mailto:help@marvy.example)
```

`AnnouncementText::render()` **builds** the anchor; it never passes anything
through. The label and the target are escaped and placed into markup this class
writes, so there is no way to express a tag, an attribute or an event handler —
`<img src=x onerror=alert(1)>` typed into the box renders as those exact
characters, visibly, on the page.

Targets are restricted to what a banner legitimately needs:

| Target | Result |
|---|---|
| `/pricing`, `#faq` | link, same tab |
| `https://…`, `http://…`, `mailto:…` | link, `target="_blank" rel="noopener noreferrer"` |
| `javascript:`, `data:`, `vbscript:` | **refused** |
| `//evil.example` | **refused** — reads like a path, behaves like leaving the site |
| `java\tscript:` | **refused** — control characters are how naive scheme checks are walked past |
| `marvy.example/pricing` (no scheme) | **refused** — a browser reads that as a relative path; guessing would be wrong as often as right |

A refused target keeps its words. Silently dropping the link would leave the
operator staring at a sentence that lost three words with no clue why.

Only external links get `target="_blank"` — and never without `noopener`, which
otherwise hands `window.opener` to the destination. Internal links stay in the
tab, because opening your own pricing page in a new window is just litter.

## 3. The colour setting that did nothing

Found while testing this: `.ws-announce-item` carried `color:#000`. The banner's
text colour is an operator setting applied inline on the container — so on a
navy banner with white text configured, the scrolling messages rendered
**black on navy**, and the setting appeared to do nothing at all. It only
worked for the single-message (static) case, which is what module 16's check
happened to exercise.

Now `color:inherit`, with the separator dot at `currentColor` and reduced
opacity so it stays visible on any background. `.ws-announce-link` is
underlined rather than coloured, for the same reason: the operator owns the
background, so a fixed link colour can always land unreadable.

---

## 4. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php   # 1492 tests, 17089 assertions, 0 failures
node tools/devserver/chrome_check.mjs --admin-password '…'  # 72/72
bash tools/verify_all.sh --admin-password '…'               # 45 passed, 0 failed
```

`tests/unit/AnnouncementTextTest.php` (18 tests) is mostly the adversarial
half: five raw-HTML payloads asserted to survive only as escaped text (with the
anchors stripped first, a single surviving `<` fails the test); four
script-bearing schemes refused with their words intact; protocol-relative and
control-character targets refused; a quote in the label and a quote in the
target both unable to escape the anchor's attributes; malformed syntax staying
text. Then the wiring: the banner renders through the sanitiser and does *not*
double-escape it, the setting's help text teaches the syntax and says HTML is
not accepted, and the CSS inherits the operator's colour.

`chrome_check.mjs` saves a real multi-line banner through the admin form and
reads the rendered page: the site link is an anchor, the mailto carries
`noopener`, `javascript:alert(1)` is refused with "pwn" still on screen, and
`<img src=x onerror=…>` appears as `&lt;img`. Then it restores the operator's
own settings.

---

## 5. Still open

- **One link style.** No bold, no emphasis, no images — deliberately. A
  marquee is not a document, and every additional construct is another parser
  to get wrong.
- **CMS announcements are still plain text** in the bar. `Announcement_model`
  content is stripped to text before it scrolls; only the `announcement_text`
  setting understands links. Routing CMS bodies through the same renderer
  would mean deciding what a paragraph looks like in a marquee.
- **No click tracking.** An operator cannot tell how many people followed the
  banner. That needs an analytics decision, not a rendering one.
