<?php
use PHPUnit\Framework\TestCase;

/**
 * Links in the announcement bar (module 21).
 *
 * The banner became editable in module 16, but only as plain text — which is
 * the wrong stopping point for the one strip on every page. The messages
 * operators actually want there are "Wallet funding is down until 14:00 —
 * details here" and "New Instagram rates from Monday", and both are useless
 * if the customer cannot reach the page being talked about. An outage notice
 * nobody can follow becomes a support ticket.
 *
 * The obvious fix — let the operator paste HTML — would put an unescaped
 * operator-controlled string on every page of a site holding wallet balances.
 * So the syntax is `[label](target)` and the anchor is *built* by
 * AnnouncementText: no attacker-supplied text is ever interpreted as markup.
 *
 * Most of this file is the adversarial half. A banner is the highest-value
 * XSS target in the whole panel: it renders on the login page, in the customer
 * dashboard and in the admin, for every visitor, on every request.
 */
class AnnouncementTextTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        require_once self::$root.'/application/libraries/AnnouncementText.php';
    }

    /* ========================== what it is for ========================== */

    public function testALinkBecomesAnAnchor()
    {
        $html = AnnouncementText::render('Funding is down. [Read more](/blog/outage) Sorry!');

        $this->assertStringContainsString(
            '<a class="ws-announce-link" href="/blog/outage">Read more</a>', $html);
        $this->assertStringStartsWith('Funding is down. ', $html);
        $this->assertStringEndsWith(' Sorry!', $html);
    }

    public function testAnExternalLinkOpensSafelyInANewTab()
    {
        $html = AnnouncementText::render('[Status page](https://status.example/incident)');

        $this->assertStringContainsString('href="https://status.example/incident"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html,
            'a target=_blank without noopener hands window.opener to the destination');
    }

    /** An internal link must NOT open a new tab — that is only for leaving. */
    public function testASitePathStaysInTheSameTab()
    {
        $html = AnnouncementText::render('[Pricing](/pricing)');
        $this->assertStringNotContainsString('target=', $html);
    }

    public function testMailtoAndFragmentsAreAllowed()
    {
        $this->assertStringContainsString('href="mailto:help@marvy.example"',
            AnnouncementText::render('[Email us](mailto:help@marvy.example)'));
        $this->assertStringContainsString('href="#faq"',
            AnnouncementText::render('[Jump](#faq)'));
    }

    public function testSeveralLinksInOneLine()
    {
        $html = AnnouncementText::render('[A](/a) and [B](/b)');
        $this->assertSame(2, substr_count($html, '<a '));
        $this->assertStringContainsString(' and ', $html);
    }

    public function testPlainTextIsUntouchedAndStillEscaped()
    {
        $this->assertSame('Rates change on Monday',
            AnnouncementText::render('Rates change on Monday'));
        $this->assertSame('Ben &amp; Co &lt;3', AnnouncementText::render('Ben & Co <3'));
        $this->assertSame('', AnnouncementText::render('   '));
    }

    /* ====================== the adversarial half ======================== */

    /**
     * The headline case. Operator input reaches every page in the panel; if
     * any of it is interpreted as markup, one settings save is stored XSS
     * against every signed-in customer and every member of staff.
     */
    public function testRawHtmlIsNeverMarkup()
    {
        foreach (array(
            '<script>alert(document.cookie)</script>',
            '<img src=x onerror=alert(1)>',
            '<a href="/x" onclick="steal()">click</a>',
            '</span><script>alert(1)</script><span>',
            '<iframe src="https://evil.example"></iframe>',
        ) as $payload) {
            $html = AnnouncementText::render('Notice: '.$payload);

            // The only tag the renderer ever emits is its own anchor. With
            // those removed, a single surviving "<" would mean operator text
            // reached the page as markup. (The escaped words "onerror" and
            // "script" DO appear in the output — as text, which is the point.)
            $without_anchors = preg_replace('~</?a\b[^>]*>~', '', $html);
            $this->assertStringNotContainsString('<', $without_anchors, $payload);
            $this->assertStringContainsString('&lt;', $html,
                'the payload must be visible as escaped text, not silently dropped');
        }
    }

    public function testScriptBearingSchemesAreRefused()
    {
        foreach (array(
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'vbscript:msgbox(1)',
        ) as $target) {
            $html = AnnouncementText::render('[Click here]('.$target.')');
            $this->assertStringNotContainsString('<a ', $html, $target.' must not become a link');
            $this->assertStringContainsString('Click here', $html,
                'the words survive so the operator can see the link was refused');
            $this->assertStringNotContainsString('javascript', strtolower($html), $target);
        }
    }

    /** `//evil.example` reads like a path and behaves like an off-site link. */
    public function testProtocolRelativeTargetsAreRefused()
    {
        $html = AnnouncementText::render('[Deal](//evil.example/phish)');
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('evil.example', $html);
    }

    /** `java\tscript:` is how a naive scheme check gets walked past. */
    public function testControlCharactersInATargetAreRefused()
    {
        $html = AnnouncementText::render("[Go](java\tscript:alert(1))");
        $this->assertStringNotContainsString('<a ', $html);
    }

    /** A quote in the label must not break out of the anchor's attributes. */
    public function testAQuoteInTheLabelCannotEscapeTheAnchor()
    {
        $html = AnnouncementText::render('[say "hi" onmouseover=x](/ok)');
        $this->assertStringContainsString('href="/ok"', $html);
        $this->assertStringNotContainsString('onmouseover=x"', $html);
        $this->assertStringContainsString('&quot;hi&quot;', $html);
    }

    /** A quote in the target cannot either. */
    public function testAQuoteInTheTargetIsEscaped()
    {
        $html = AnnouncementText::render('[x](/a"onmouseover="alert(1))');
        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    /** Malformed syntax is text, not a hole. */
    public function testUnclosedSyntaxIsJustText()
    {
        $this->assertSame('[Read more](/blog', AnnouncementText::render('[Read more](/blog'));
        $this->assertSame('[](/blog)', AnnouncementText::render('[](/blog)'));
    }

    /* ============================ helpers =============================== */

    public function testPlainStripsTheSyntaxForScreenReadersAndTitles()
    {
        $this->assertSame('Funding is down. Read more',
            AnnouncementText::plain('Funding is down. [Read more](/blog/outage)'));
    }

    public function testHasLinkAnswersHonestly()
    {
        $this->assertTrue(AnnouncementText::has_link('[a](/b)'));
        $this->assertFalse(AnnouncementText::has_link('no link here'));
        $this->assertFalse(AnnouncementText::has_link('[a](javascript:alert(1))'),
            'a refused target is not a link');
    }

    /* ============================ the wiring ============================ */

    public function testTheBannerRendersThroughTheSanitiser()
    {
        $src = file_get_contents(self::$root.'/application/views/partials/announcement_bar.php');

        $this->assertStringContainsString('AnnouncementText::render($text)', $src,
            'the scrolling items must go through the renderer');
        $this->assertStringContainsString('AnnouncementText::render($items[0])', $src,
            'and so must a single static message');
        $this->assertStringNotContainsString('htmlspecialchars($text)', $src,
            'double-escaping would print the anchor as text');
    }

    /** The operator has to be told the syntax exists, and its limits. */
    public function testTheSettingExplainsTheSyntax()
    {
        $src = file_get_contents(self::$root.'/application/libraries/SettingsService.php');
        $this->assertStringContainsString('[Read more](/blog/outage)', $src);
        $this->assertStringContainsString('HTML is not accepted', $src);
    }

    /**
     * The strip's colours are an operator setting applied inline on the
     * container. A hard-coded colour on the item meant a white-on-navy banner
     * scrolled its messages in black — the setting appeared to do nothing.
     */
    public function testTheBannerTextInheritsTheOperatorsColour()
    {
        $css = file_get_contents(self::$root.'/assets/css/design-system.css');
        $this->assertMatchesRegularExpression(
            '~\.ws-announce-item\{[^}]*color:inherit~s', $css);
        $this->assertDoesNotMatchRegularExpression(
            '~\.ws-announce-item\{[^}]*color:#000~s', $css);
        $this->assertStringContainsString('.ws-announce-link', $css,
            'a link in the banner needs a visible affordance on any background');
    }
}
