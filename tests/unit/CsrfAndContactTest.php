<?php
use PHPUnit\Framework\TestCase;

/**
 * "The first message works, the second one says something went wrong."
 *
 * That report covered three surfaces — the ticket reply box, the contact page
 * and a chat widget bolted onto the theme — and they shared one cause: every
 * POST is CSRF-checked, the token rotated on each POST, and there was no way
 * for anything other than a freshly rendered form to obtain a current one. The
 * first submit consumed the token; the second was rejected; whatever posted it
 * reported its own generic failure.
 *
 * These tests pin the fix: a stable token by default, a token JavaScript can
 * read and refresh, header-borne tokens for non-form clients, a machine-
 * readable 419 that carries a usable token, and markup that is not silently
 * re-parented by the browser between the first and second submit.
 */
class CsrfAndContactTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('APPPATH'))  define('APPPATH', self::$root.'/application/');
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!function_exists('log_message')) eval('function log_message($l, $m){}');
        // MY_Security extends the framework class, which cannot be loaded
        // outside a booted CodeIgniter. Only the two accessors the subclass
        // touches are needed to exercise its own logic.
        if (!class_exists('CI_Security')) {
            eval('class CI_Security {
                public function get_csrf_hash(){ return "stub-hash"; }
                public function get_csrf_token_name(){ return "csrf_windels"; }
                public function csrf_verify(){ return $this; }
            }');
        }
        require_once self::$root.'/application/core/MY_Security.php';
    }

    /* ============================ the token ============================== */

    public function testTheTokenDoesNotRotateOutFromUnderAPageByDefault()
    {
        $src = file_get_contents(self::$root.'/application/config/config.php');
        $this->assertStringContainsString(
            "\$config['csrf_regenerate'] = Env::get_bool('CSRF_REGENERATE', FALSE);", $src,
            'per-POST rotation breaks any page that posts twice without re-rendering');
        $this->assertStringContainsString("\$config['csrf_protection'] = TRUE", $src,
            'the protection itself stays on — only the rotation is relaxed');
    }

    public function testEveryLayoutPublishesTheTokenToJavaScript()
    {
        foreach (array('public', 'app', 'auth') as $layout) {
            $src = file_get_contents(self::$root."/application/views/layouts/{$layout}.php");
            $this->assertStringContainsString('name="csrf-token"', $src,
                "layouts/{$layout}.php must expose the token so scripted posts can use it");
            $this->assertStringContainsString('name="csrf-name"', $src);
            $this->assertStringContainsString('name="csrf-endpoint"', $src);
            $this->assertStringContainsString('assets/js/app.js', $src,
                "layouts/{$layout}.php must load the script that attaches the token");
        }
    }

    public function testThereIsAnEndpointThatHandsOutACurrentToken()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("\$route['csrf'] = 'csrf/index';", $routes);

        $src = file_get_contents(self::$root.'/application/controllers/Csrf.php');
        $this->assertStringContainsString("method(TRUE) !== 'GET'", $src, 'GET only, no side effects');
        $this->assertStringContainsString('get_csrf_hash', $src);
        $this->assertStringContainsString('no-store', $src,
            'a cached token response would hand the next request a retired token');
        $this->assertStringNotContainsString('Access-Control-Allow-Origin', $src,
            'the token must not be readable cross-origin — that would defeat CSRF entirely');
    }

    /* ======================== the rejection path ========================= */

    public function testAHeaderBorneTokenIsAccepted()
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abc123';
        $this->assertSame('abc123', MY_Security::header_token(),
            'JSON requests have no form fields; a header is the only place they can carry a token');

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_SERVER['HTTP_X_XSRF_TOKEN'] = 'xyz789';
        $this->assertSame('xyz789', MY_Security::header_token(), 'the axios/angular spelling');

        unset($_SERVER['HTTP_X_XSRF_TOKEN']);
        $this->assertNull(MY_Security::header_token());
    }

    public function testAnXhrCallerIsAnsweredWithJsonAndABrowserWithAPage()
    {
        $this->clearRequestHeaders();

        $this->assertFalse(MY_Security::wants_json(), 'a plain form post gets the HTML page');

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $this->assertTrue(MY_Security::wants_json());
        $this->clearRequestHeaders();

        $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
        $this->assertTrue(MY_Security::wants_json());
        $this->clearRequestHeaders();

        $_SERVER['CONTENT_TYPE'] = 'application/json;charset=UTF-8';
        $this->assertTrue(MY_Security::wants_json(), 'a JSON body is never a browser form');
        $this->clearRequestHeaders();

        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abc';
        $this->assertTrue(MY_Security::wants_json(), 'anything sending the header is a script');
        $this->clearRequestHeaders();
    }

    public function testARejectionHandsBackAUsableTokenSoTheRetryCanSucceed()
    {
        $src = file_get_contents(self::$root.'/application/core/MY_Security.php');
        $this->assertStringContainsString("'code'    => 'CSRF_EXPIRED'", $src);
        $this->assertStringContainsString("'csrf'    => array('name' => \$name, 'hash' => \$hash)", $src,
            'a widget must be able to retry immediately instead of telling the customer to contact support');
        $this->assertStringContainsString('419', $src, 'the de-facto "token expired" status');
        $this->assertStringContainsString('log_message(', $src,
            'a rejected post must be visible in the log, or nobody can diagnose it');
    }

    public function testTheVerifierStillRunsTheFrameworkCheck()
    {
        $src = file_get_contents(self::$root.'/application/core/MY_Security.php');
        $this->assertMatchesRegularExpression('/class MY_Security extends CI_Security/', $src);
        $this->assertSame(2, substr_count($src, 'parent::csrf_verify()'),
            'the comparison itself must stay in the framework — we only supply the token');
        $this->assertStringNotContainsString('return TRUE;', $src,
            'nothing here may short-circuit the check');
    }

    /* =========================== the client ============================== */

    public function testTheScriptAttachesAndRefreshesTheToken()
    {
        $js = file_get_contents(self::$root.'/assets/js/app.js');
        $this->assertStringContainsString('X-CSRF-TOKEN', $js);
        $this->assertStringContainsString('window.fetch', $js, 'same-origin fetch() posts carry the token');
        $this->assertStringContainsString('XMLHttpRequest.prototype.send', $js, 'and so do XHR posts');
        $this->assertStringContainsString('419', $js, 'an expired token is retried, not surfaced');
        $this->assertStringContainsString('WINDELS.csrf', $js,
            'third-party widgets need a supported way to get the token');
        $this->assertStringContainsString('sameOrigin', $js,
            'the token must never be attached to a cross-origin request');
        $this->assertStringContainsString('pageshow', $js,
            'a page restored from the back/forward cache holds a stale token');
    }

    /* ======================== the reply box markup ======================= */

    public function testTheTicketReplyFormsAreNotInterleaved()
    {
        $src = file_get_contents(self::$root.'/application/views/dashboard/tickets/detail.php');

        $this->assertSame(
            substr_count($src, '<?=form_open('), substr_count($src, '<?=form_close()'),
            'every form must be opened and closed the same number of times');
        $this->assertSame(0, substr_count($src, '<form method="post"'),
            'hand-rolled <form> tags miss the CSRF field that form_open() adds');

        // The precise defect: </form> emitted between the opening <div> and its
        // </div>, with a second form starting inside that div. Browsers recover
        // by re-parenting, which is how a submit button stops belonging to the
        // form it is written inside.
        $reply = strpos($src, "/reply', array('class'=>'mt-2 stack')");
        $close = strpos($src, "/close'");
        $this->assertNotFalse($reply);
        $this->assertNotFalse($close);
        $this->assertLessThan($close, $reply,
            'the reply form must open before the close-ticket form');
        $between = substr($src, $reply, $close - $reply);
        $this->assertSame(1, substr_count($between, '<?=form_close()'),
            'the reply form must be closed exactly once before the close-ticket form opens');

        // Nothing opened inside the reply form may still be open when it ends:
        // that is precisely the mis-nesting that made the browser re-parent the
        // submit button.
        $body = substr($between, 0, strpos($between, '<?=form_close()'));
        $this->assertSame(
            substr_count($body, '<div'), substr_count($body, '</div>'),
            'the reply form must not leave a div open across its own </form>');
    }

    /* ========================== the contact page ========================= */

    public function testTheContactPageHasAFormThatPostsSomewhere()
    {
        $view = file_get_contents(self::$root.'/application/views/public/contact.php');
        $this->assertStringContainsString("form_open('contact'", $view,
            'the page used to be a bare heading — there was nothing to submit');
        foreach (array('name="name"', 'name="email"', 'name="subject"', 'name="message"') as $field) {
            $this->assertStringContainsString($field, $view);
        }
        $this->assertStringContainsString('name="website"', $view, 'honeypot');
        $this->assertStringContainsString('position:absolute;left:-9999px', $view,
            'the honeypot must be invisible to people');

        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("\$route['contact']['get']  = 'home/contact';", $routes);
        $this->assertStringContainsString("\$route['contact']['post'] = 'home/contact_submit';", $routes);
    }

    public function testContactSubmissionsAreValidatedThrottledAndDelivered()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Home.php');

        $this->assertStringContainsString('too_many_failures', $src, 'a public POST endpoint must be throttled');
        $this->assertStringContainsString('FILTER_VALIDATE_EMAIL', $src);
        $this->assertStringContainsString('honeypot', $src);
        $this->assertStringContainsString('ticketservice->open', $src,
            'a signed-in customer gets a ticket they can follow');
        $this->assertStringContainsString('mailservice->enqueue_raw', $src,
            'a visitor with no account gets their message emailed to support');
        $this->assertStringContainsString('html_escape(', $src,
            'the message ends up in an HTML email — escape it');
        $this->assertStringNotContainsString("redirect('contact')\n            return;", $src);
    }

    public function testAFailedContactSubmissionKeepsWhatWasTyped()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Home.php');
        $this->assertMatchesRegularExpression("/'error'\s*=>[^\n]*,\n\s*'form'\s*=>\s*\\\$form/", $src,
            'losing a long message to a validation error is its own support ticket');

        $view = file_get_contents(self::$root.'/application/views/public/contact.php');
        $this->assertStringContainsString("\$value('message')", $view);
    }

    /* ====================== the silent ticket failure ==================== */

    public function testACustomerReplyThatDidNotPersistIsReportedAsAFailure()
    {
        $src = file_get_contents(self::$root.'/application/libraries/TicketService.php');
        $reply = substr($src, strpos($src, 'public function reply('),
                        strpos($src, 'public function staff_reply(') - strpos($src, 'public function reply('));
        $this->assertStringContainsString('trans_status()', $reply,
            'a rolled-back reply used to be reported as sent, so the message just vanished');
        $this->assertStringContainsString("'code'=>'PERSIST_FAILED'", $reply);
    }

    private function clearRequestHeaders()
    {
        foreach (array('HTTP_X_REQUESTED_WITH', 'HTTP_ACCEPT', 'CONTENT_TYPE', 'HTTP_CONTENT_TYPE',
                       'HTTP_X_CSRF_TOKEN', 'HTTP_X_XSRF_TOKEN', 'HTTP_SEC_FETCH_MODE') as $key) {
            unset($_SERVER[$key]);
        }
    }
}
