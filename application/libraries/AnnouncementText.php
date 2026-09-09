<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AnnouncementText — turns one operator-typed banner line into safe HTML.
 *
 * The announcement bar became editable in module 16, but only as plain text.
 * That is the wrong stopping point for the one strip on every page: the
 * messages operators actually want there are *"Wallet funding is down until
 * 14:00 — details here"* and *"New Instagram rates from Monday"*, and both are
 * useless if the customer cannot get to the page being talked about. Telling
 * them to "go and find it in the menu" is how an outage notice turns into a
 * support ticket.
 *
 * The obvious fix — let the operator type HTML — is how a banner on every page
 * of a site that holds wallet balances becomes stored XSS. So the syntax is
 * deliberately tiny and the output is built here, never pasted through:
 *
 *     Wallet funding is down until 14:00. [Read more](/blog/funding-outage)
 *
 * A `[label](target)` pair becomes an anchor; everything else is escaped text.
 * There is no way to express an attribute, an event handler or a tag, because
 * no attacker-supplied string is ever *interpreted* as markup — the label and
 * the URL are both escaped and placed into an anchor this class writes.
 *
 * Targets are restricted to what a banner legitimately needs: a site-relative
 * path, an http(s) URL, or a mailto:. Anything else — `javascript:`, `data:`,
 * a protocol-relative `//evil.example` that would silently leave the site —
 * is refused, and the link degrades to its label as plain text rather than
 * disappearing, so the operator can see something is wrong with it.
 */
class AnnouncementText {

    /** `[label](target)` — labels and targets may not contain brackets. */
    const PATTERN = '/\[([^\]\[]{1,120})\]\(([^)\s]{1,300})\)/';

    /**
     * Render one line to HTML.
     *
     * @param string $line Raw operator text, exactly as typed.
     * @return string HTML safe to place in the banner.
     */
    public static function render($line) {
        $line = (string)$line;
        if (trim($line) === '') return '';

        $out = '';
        $offset = 0;
        while (preg_match(self::PATTERN, $line, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $out  .= htmlspecialchars(substr($line, $offset, $start - $offset), ENT_QUOTES, 'UTF-8');

            $label = trim($m[1][0]);
            $href  = self::safe_target(trim($m[2][0]));

            if ($href === null || $label === '') {
                // A refused target still shows its words. Silently dropping
                // the whole thing would leave the operator wondering why their
                // sentence lost three of its words.
                $out .= htmlspecialchars($label !== '' ? $label : $m[0][0], ENT_QUOTES, 'UTF-8');
            } else {
                $out .= '<a class="ws-announce-link" href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'"'
                     .  (self::is_external($href) ? ' target="_blank" rel="noopener noreferrer"' : '')
                     .  '>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a>';
            }
            $offset = $start + strlen($m[0][0]);
        }
        $out .= htmlspecialchars(substr($line, $offset), ENT_QUOTES, 'UTF-8');
        return $out;
    }

    /** The same line with the markup removed — for `aria-label`s and titles. */
    public static function plain($line) {
        $line = preg_replace(self::PATTERN, '$1', (string)$line);
        return trim((string)$line);
    }

    /** Does this line contain at least one usable link? */
    public static function has_link($line) {
        return strpos(self::render($line), '<a ') !== false;
    }

    /**
     * A target we are willing to write into an href, or NULL.
     *
     * Site-relative paths and fragments are kept as typed. Absolute URLs must
     * be http(s) or mailto. A protocol-relative `//host` is refused on
     * purpose: it reads like a path and behaves like an off-site link.
     */
    private static function safe_target($target) {
        if ($target === '') return null;
        // Control characters and whitespace are how `java\tscript:` gets past
        // a naive scheme check.
        if (preg_match('/[\x00-\x20\x7F]/', $target)) return null;

        if (strpos($target, '//') === 0) return null;
        if ($target[0] === '/' || $target[0] === '#') return $target;

        if (preg_match('~^https?://[^\s]+$~i', $target)) return $target;
        if (preg_match('~^mailto:[^\s@]+@[^\s@]+$~i', $target)) return $target;

        // A bare domain typed without a scheme ("marvy.example/pricing") is a
        // relative path to the browser and almost never what was meant, so it
        // is refused rather than guessed at.
        return null;
    }

    private static function is_external($href) {
        return (bool)preg_match('~^(https?://|mailto:)~i', $href);
    }
}
