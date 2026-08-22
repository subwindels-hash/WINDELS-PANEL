<?php
use PHPUnit\Framework\TestCase;

/**
 * Session 17 — security hardening audit (§61).
 *
 * Covers the audit areas the spec names: TLS verification, CSRF, XSS, SQL
 * injection, secret handling, rate limiting and brute force. Where a fix has
 * behaviour it is tested behaviourally; where the guarantee is "this pattern
 * must never appear in the codebase" it is a source scan, because that is the
 * only thing that catches it being reintroduced next session.
 */
class SecurityHardeningTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/libraries/RateLimiter.php';
    }

    /* =========================== brute force ============================= */

    public function testPerAccountFailuresLockThatAccount()
    {
        $rl = $this->limiter($db);
        for ($i = 0; $i < 5; $i++) $rl->record('victim@x.test', '1.1.1.1', false);

        $this->assertTrue($rl->too_many_failures('1.1.1.1', 'victim@x.test', 5, 900));
    }

    public function testFailuresAgainstOtherAccountsDoNotLockAnInnocentUser()
    {
        $rl = $this->limiter($db);
        // An attacker sprays five other accounts from the shared office IP.
        foreach (array('a', 'b', 'c', 'd', 'e') as $who) {
            $rl->record($who.'@x.test', '9.9.9.9', false);
        }

        // A colleague behind the same NAT must still be able to log in: their
        // own account has no failures. The old OR-bucket locked them out.
        $this->assertFalse($rl->too_many_failures('9.9.9.9', 'innocent@x.test', 5, 900));
    }

    public function testASprayAcrossManyAccountsStillTripsTheNetworkLimit()
    {
        $rl = $this->limiter($db);
        // Per-IP tolerance is 5 * IP_MULTIPLIER = 15.
        for ($i = 0; $i < 15; $i++) $rl->record("user{$i}@x.test", '6.6.6.6', false);

        // Nobody's individual account is over its limit, but the network is.
        $this->assertTrue($rl->too_many_failures('6.6.6.6', 'user99@x.test', 5, 900));
    }

    public function testTheNetworkLimitIsLooserThanThePerAccountLimit()
    {
        $rl = $this->limiter($db);
        for ($i = 0; $i < 6; $i++) $rl->record("user{$i}@x.test", '7.7.7.7', false);

        // 6 failures from one IP across 6 accounts: over the account limit if
        // they were pooled, under the network limit as separate buckets.
        $this->assertFalse($rl->too_many_failures('7.7.7.7', 'fresh@x.test', 5, 900));
    }

    public function testSuccessfulAttemptsAreNotCountedAgainstTheUser()
    {
        $rl = $this->limiter($db);
        for ($i = 0; $i < 10; $i++) $rl->record('busy@x.test', '2.2.2.2', true);

        $this->assertFalse($rl->too_many_failures('2.2.2.2', 'busy@x.test', 5, 900));
    }

    public function testFailuresOutsideTheWindowExpire()
    {
        $rl = $this->limiter($db);
        for ($i = 0; $i < 5; $i++) {
            $db->rows[] = array(
                'email' => 'old@x.test', 'ip' => '3.3.3.3', 'success' => 0,
                'created_at' => gmdate('Y-m-d H:i:s', time() - 4000),
            );
        }
        $this->assertFalse($rl->too_many_failures('3.3.3.3', 'old@x.test', 5, 900));
    }

    public function testScopedCountersDoNotCollideWithEachOther()
    {
        $rl = $this->limiter($db);
        $reset = RateLimiter::scope('pwreset', 'a@x.test');
        for ($i = 0; $i < 5; $i++) $rl->record($reset, '4.4.4.4', false);

        // The bug this replaced: a constant 'pwreset' identifier put every
        // user in one bucket, so five requests disabled reset for everyone.
        $other = RateLimiter::scope('pwreset', 'b@x.test');
        $this->assertTrue($rl->too_many_failures('4.4.4.4', $reset, 5, 900));
        $this->assertFalse($rl->too_many_failures('5.5.5.5', $other, 5, 900),
            'one account exhausting its reset budget must not lock another');
    }

    public function testAScopedCounterCannotBeCollidedWithByARealEmail()
    {
        // scope() namespaces the key, so a user literally named 'pwreset'
        // cannot consume or poison the reset bucket.
        $this->assertSame('pwreset:a@x.test', RateLimiter::scope('pwreset', 'a@x.test'));
        $this->assertStringContainsString(':', RateLimiter::scope('mfa', 'user:12'));
        $this->assertNotSame(RateLimiter::scope('pwreset', ''), 'pwreset');
    }

    public function testRetryAfterReportsTimeLeftOnTheLockedBucket()
    {
        $rl = $this->limiter($db);
        for ($i = 0; $i < 5; $i++) {
            $db->rows[] = array(
                'email' => 'v@x.test', 'ip' => '8.8.8.8', 'success' => 0,
                'created_at' => gmdate('Y-m-d H:i:s', time() - 300),
            );
        }
        $retry = $rl->retry_after('8.8.8.8', 'v@x.test', 900, 5);
        $this->assertGreaterThan(0, $retry);
        $this->assertLessThanOrEqual(600, $retry);
    }

    public function testRetryAfterIsZeroWhenNothingIsLocked()
    {
        $rl = $this->limiter($db);
        $this->assertSame(0, $rl->retry_after('1.2.3.4', 'nobody@x.test', 900, 5));
    }

    public function testMfaVerificationIsRateLimited()
    {
        // A 6-digit TOTP is brute-forceable in minutes once the password is
        // known, so the second factor needs its own limit.
        $src = file_get_contents(self::$root.'/application/controllers/Auth.php');
        $mfa = substr($src, strpos($src, 'function mfa_verify'));
        $mfa = substr($mfa, 0, strpos($mfa, "\n    }"));

        $this->assertStringContainsString('too_many_failures', $mfa,
            'mfa_verify must refuse once the attempt budget is spent');
        $this->assertStringContainsString('ratelimiter->record', $mfa,
            'a failed MFA attempt must be counted, or the limit never trips');
    }

    public function testRegistrationAttemptsAreCounted()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Auth.php');
        $reg = substr($src, strpos($src, 'function register_post'));
        if (strpos($src, 'function register_post') === false) {
            $reg = substr($src, strpos($src, "function register"));
        }
        $reg = substr($reg, 0, strpos($reg, "\n    }"));
        // Checking a limit you never increment is a no-op.
        $this->assertStringContainsString('too_many_failures', $reg);
        $this->assertStringContainsString('ratelimiter->record', $reg);
    }

    /* ============================== SSRF ================================= */

    public function testProviderUrlsCannotReachTheCloudMetadataEndpoint()
    {
        $client = $this->http_client();
        $reason = $this->reject($client, 'http://169.254.169.254/latest/meta-data/');
        $this->assertNotNull($reason, 'link-local metadata must be refused');
    }

    public function testLoopbackAndPrivateRangesAreRefused()
    {
        $client = $this->http_client();
        foreach (array(
            'http://127.0.0.1:6379/',
            'http://localhost/admin',
            'http://10.0.0.5/internal',
            'http://192.168.1.1/',
            'http://172.16.0.9/',
        ) as $url) {
            $this->assertNotNull($this->reject($client, $url), "{$url} must be refused");
        }
    }

    public function testNonHttpSchemesAreRefused()
    {
        $client = $this->http_client();
        foreach (array('file:///etc/passwd', 'gopher://x.test/_', 'dict://x.test:11211/') as $url) {
            $this->assertNotNull($this->reject($client, $url), "{$url} must be refused");
        }
    }

    public function testCredentialsInAUrlAreRefused()
    {
        $client = $this->http_client();
        $this->assertNotNull($this->reject($client, 'https://user:pass@example.com/api'));
    }

    public function testAnOrdinaryPublicUrlIsAllowed()
    {
        $client = $this->http_client();
        $this->assertNull($this->reject($client, 'https://8.8.8.8/api/v2'),
            'the guard must not block legitimate provider endpoints');
    }

    public function testPrivateHostsCanBeAllowedForSelfHostedDeployments()
    {
        // Self-hosted panels legitimately run providers on the LAN, so the
        // check is configurable — but it must default to off.
        $client = $this->http_client(true);
        $this->assertNull($this->reject($client, 'http://10.0.0.5/internal'));

        $config = file_get_contents(self::$root.'/application/config/windels.php');
        $this->assertStringContainsString('http_allow_private_hosts', $config);
        // Read from the environment (via env_bool, which treats the string
        // "false" as false — a plain (bool) cast does not), never hardcoded on.
        $this->assertStringContainsString("env_bool('HTTP_ALLOW_PRIVATE_HOSTS')", $config,
            'the override must be opt-in via env, not hardcoded on');
        $this->assertStringNotContainsString("=> true", $config);
    }

    public function testRedirectsCannotEscapeToOtherProtocols()
    {
        $src = file_get_contents(self::$root.'/application/libraries/SecureHttpClient.php');
        // Without REDIR_PROTOCOLS, curl follows a 302 into file:// happily.
        $this->assertStringContainsString('CURLOPT_REDIR_PROTOCOLS', $src);
        $this->assertStringContainsString('CURLOPT_PROTOCOLS', $src);
    }

    /* =============================== TLS ================================= */

    public function testTlsVerificationIsNeverDisabled()
    {
        foreach ($this->php_files() as $file) {
            $src = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '~CURLOPT_SSL_VERIFYPEER\s*=>?\s*(FALSE|false|0)~', $src,
                basename($file).' disables TLS peer verification');
            $this->assertDoesNotMatchRegularExpression(
                '~CURLOPT_SSL_VERIFYHOST\s*=>?\s*(FALSE|false|0)~', $src,
                basename($file).' disables TLS host verification');
        }
    }

    /* ======================== headers / CSP / CSRF ======================== */

    public function testSecurityHeadersAreSet()
    {
        $src = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        foreach (array(
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: SAMEORIGIN',
            'Referrer-Policy:',
            'Content-Security-Policy:',
            'Permissions-Policy:',
            'Strict-Transport-Security:',
        ) as $header) {
            $this->assertStringContainsString($header, $src, "missing {$header}");
        }
    }

    public function testHstsIsOnlySentOverTlsInProduction()
    {
        $src = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        $block = substr($src, strpos($src, 'Strict-Transport-Security') - 400, 500);
        // Pinning a plaintext dev host into HTTPS for six months is not a
        // recoverable mistake for the developer it happens to.
        $this->assertStringContainsString("APP_ENV') === 'production'", $block);
        $this->assertStringContainsString('is_https()', $block);
    }

    public function testCspDoesNotAllowUnsafeInlineScript()
    {
        $src = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        preg_match("~\"script-src[^\"]*\"~", $src, $m);
        $this->assertNotEmpty($m, 'no script-src directive found');
        $this->assertStringNotContainsString("'unsafe-inline'", $m[0],
            "script-src must use a nonce, not 'unsafe-inline'");
        $this->assertStringNotContainsString("'unsafe-eval'", $m[0]);
        $this->assertStringContainsString('nonce-', $m[0]);
    }

    public function testEveryInlineScriptCarriesTheNonce()
    {
        $missing = array();
        foreach ($this->view_files() as $file) {
            $src = file_get_contents($file);
            // Inline blocks only: <script src=...> is covered by 'self'.
            if (preg_match_all('~<script(?![^>]*\bsrc=)([^>]*)>~i', $src, $m)) {
                foreach ($m[1] as $attrs) {
                    if (strpos($attrs, 'csp_nonce_attr') === false) {
                        $missing[] = str_replace(self::$root.'/', '', $file);
                    }
                }
            }
        }
        $this->assertSame(array(), $missing,
            'these inline scripts would be blocked by the CSP: '.implode(', ', $missing));
    }

    public function testCsrfProtectionIsOnAndExclusionsAreTight()
    {
        $src = file_get_contents(self::$root.'/application/config/config.php');
        $this->assertMatchesRegularExpression(
            "~csrf_protection'\]\s*=\s*TRUE~i", $src);

        // Rotation used to be pinned on here. It is now off by default and
        // switchable with VP_CSRF_REGENERATE, because a token that is retired
        // by the first POST breaks every page that posts twice from one
        // render — an AJAX reply box, a support widget, a second tab, the Back
        // button — and the failure surfaces to the customer as an unexplained
        // error on their second message. The token is still per-session,
        // cookie-bound and unreadable cross-origin, which is what actually
        // stops the attack; MY_Security keeps the verification itself in the
        // framework and merely lets a non-form client present the token in a
        // header.
        $this->assertMatchesRegularExpression(
            "~csrf_regenerate'\]\s*=\s*Env::get_bool\('CSRF_REGENERATE', FALSE\)~i", $src,
            'rotation must stay configurable rather than silently re-pinned on');

        $security = file_get_contents(self::$root.'/application/core/MY_Security.php');
        $this->assertStringContainsString('parent::csrf_verify()', $security,
            'the token comparison must remain the framework\'s');
        $this->assertStringNotContainsString('Access-Control-Allow-Origin', $security,
            'a CSRF token readable cross-origin is not a CSRF token');

        preg_match("~csrf_exclude_uris'\]\s*=\s*array\(([^)]*)\)~", $src, $m);
        $this->assertNotEmpty($m);
        // CI3 anchors as #^pattern$#. 'health.*' would also exempt any future
        // route that merely starts with "health".
        $this->assertStringNotContainsString("'health.*'", $m[1]);
        foreach (array('webhook', 'api/v1') as $exempt) {
            $this->assertStringContainsString($exempt, $m[1],
                "{$exempt} authenticates by signature/key and must stay exempt");
        }
    }

    public function testSessionCookiesAreHardened()
    {
        $src = file_get_contents(self::$root.'/application/config/config.php');
        $this->assertMatchesRegularExpression("~cookie_httponly'\]\s*=\s*TRUE~i", $src);
        $this->assertStringContainsString("cookie_samesite'] = 'Lax'", $src);
        $this->assertStringContainsString("APP_ENV') === 'production'", $src,
            'cookie_secure must be on in production');
    }

    public function testLoginRegeneratesTheSessionId()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        $this->assertStringContainsString('sess_regenerate', $src,
            'session fixation defence');
    }

    /* ========================= injection / secrets ======================== */

    public function testNoRawSqlStringInterpolation()
    {
        foreach ($this->php_files() as $file) {
            $src = file_get_contents($file);
            // CI3's query() with an interpolated variable is the classic hole.
            $this->assertDoesNotMatchRegularExpression(
                '~->query\(\s*["\'][^"\']*\$~', $src,
                basename($file).' interpolates a variable into raw SQL');
        }
    }

    public function testUnescapedWhereClausesContainNoUserInput()
    {
        // where($x, null, false) disables escaping. Every such call must pass a
        // literal fragment, or a value already run through db->escape().
        $offenders = array();
        foreach ($this->php_files() as $file) {
            $src = file_get_contents($file);
            // One argument only: no newlines, and no nested where() call, so
            // the match cannot run across a chained builder expression.
            if (!preg_match_all(
                '~->(?:where|or_where)\(((?:[^,()\n]|\([^()\n]*\))+),\s*(?:NULL|null)\s*,\s*(?:FALSE|false)\s*\)~',
                $src, $m)) {
                continue;
            }
            foreach ($m[1] as $arg) {
                if (strpos($arg, '$') === false) continue;          // literal
                if (strpos($arg, 'escape(') !== false) continue;    // escaped
                $offenders[] = basename($file).': '.trim($arg);
            }
        }
        $this->assertSame(array(), $offenders,
            'unescaped SQL fragment with user input: '.implode(' | ', $offenders));
    }

    public function testSecretsAreNeverLogged()
    {
        foreach ($this->php_files() as $file) {
            $src = file_get_contents($file);
            if (!preg_match_all('~log_message\([^;]*;~s', $src, $m)) continue;
            foreach ($m[0] as $call) {
                $this->assertDoesNotMatchRegularExpression(
                    '~\$(?:\w+->)*(?:api_key|api_key_encrypted|password|secret|token|key_hash)\b~i',
                    $call,
                    basename($file).' logs a secret: '.substr(trim($call), 0, 90));
            }
        }
    }

    /**
     * The identity domain (§22) puts a new class of value in scope: a NIN, a
     * BVN and a decrypted identity record are as damaging in a log line as an
     * API key, and rather more likely to end up there by accident — they
     * arrive as ordinary request input and travel through error paths that
     * were written when the worst thing in a variable was an order id.
     */
    public function testIdentityDataIsNeverLogged()
    {
        foreach ($this->php_files() as $file) {
            $src = file_get_contents($file);
            if (!preg_match_all('~log_message\([^;]*;~s', $src, $m)) continue;
            foreach ($m[0] as $call) {
                $this->assertDoesNotMatchRegularExpression(
                    '~\$(?:\w+->)*(?:nin|bvn|identifier|identity_number|photo|entity)\b~i',
                    $call,
                    basename($file).' logs identity data: '.substr(trim($call), 0, 90));
            }
        }
    }

    /**
     * The identifier must not reach the session store either. Flash data is
     * written to files or a database table that nothing encrypts and no
     * retention sweep touches, so a NIN put there outlives every control the
     * identity domain has.
     */
    public function testTheIdentifierIsNeverFlashedOrStoredInTheSession()
    {
        foreach ($this->php_files() as $file) {
            $src = file_get_contents($file);
            if (!preg_match_all('~(?:set_flashdata|set_userdata)\([^;]*;~s', $src, $m)) continue;
            foreach ($m[0] as $call) {
                $this->assertDoesNotMatchRegularExpression(
                    '~\bpost\(\s*.identifier.|\$(?:\w+->)*(?:nin|bvn|identifier)\b~i',
                    $call,
                    basename($file).' puts an identifier in the session: '.substr(trim($call), 0, 90));
            }
        }
    }

    /**
     * A gift card code is the one payload in the panel that is *directly*
     * spendable. Everything the identity rules protect is damaging because of
     * what it reveals; a card number is damaging because of what it buys, so
     * it must not reach a log line or the session store either.
     */
    public function testGiftCardCodesAreNeverLoggedOrSessioned()
    {
        foreach ($this->php_files() as $file) {
            $src = file_get_contents($file);
            if (preg_match_all('~(?:log_message|set_flashdata|set_userdata)\([^;]*;~s', $src, $m)) {
                foreach ($m[0] as $call) {
                    $this->assertDoesNotMatchRegularExpression(
                        '~\$(?:\w+->)*(?:card_number|pin_code|pinCode|cardNumber|redeem_code)\b~i',
                        $call,
                        basename($file).' exposes a gift card code: '.substr(trim($call), 0, 90));
                }
            }
        }
    }

    /**
     * The same single-door rule the identity result has, for the same reason
     * and with higher stakes: a controller or view decrypting a card directly
     * would be an unlogged read of something a staff member can spend.
     */
    public function testOnlyTheAuditedServicePathDecryptsAGiftCardCode()
    {
        $callers = array();
        foreach (array_merge($this->php_files(), $this->view_files()) as $file) {
            if (basename($file) === 'GiftcardService.php') continue;
            $src = file_get_contents($file);
            if (strpos($src, 'card_number_encrypted') === false
                && strpos($src, 'pin_encrypted') === false) continue;
            if (preg_match('~encryptionservice->(?:open|decrypt)~i', $src)) {
                $callers[] = basename($file);
            }
        }
        $this->assertSame(array(), $callers,
            'decrypting outside GiftcardService::reveal() bypasses the access log: '
            .implode(', ', $callers));
    }

    /**
     * There must be exactly one route to a plaintext identity result, and it
     * is the one that audits the access. A controller or view calling the
     * decryptor directly would be a silent read.
     */
    public function testOnlyTheAuditedServicePathDecryptsAnIdentityResult()
    {
        $callers = array();
        foreach (array_merge($this->php_files(), $this->view_files()) as $file) {
            if (basename($file) === 'IdentityService.php') continue;
            $src = file_get_contents($file);
            if (strpos($src, 'result_encrypted') === false) continue;
            if (preg_match('~encryptionservice->(?:open|decrypt)~i', $src)) {
                $callers[] = basename($file);
            }
        }
        $this->assertSame(array(), $callers,
            'decrypting outside IdentityService::reveal() bypasses the access log: '
            .implode(', ', $callers));
    }

    public function testApiKeysAreStoredHashed()
    {
        $src = file_get_contents(self::$root.'/application/models/Api_key_model.php');
        $this->assertStringContainsString("hash('sha256'", $src);
        $this->assertStringNotContainsString("where('key',", $src,
            'lookup must be by hash, never by the raw key');
    }

    public function testWebhookSignaturesAreComparedInConstantTime()
    {
        $src = file_get_contents(self::$root.'/application/libraries/PaymentService.php');
        $this->assertStringContainsString('hash_equals', $src);
        $this->assertDoesNotMatchRegularExpression(
            '~\$expected\s*(===|==)\s*\$sig~', $src,
            'signature comparison must not short-circuit on the first byte');
    }

    public function testPasswordsAreHashedNotEncrypted()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        $this->assertStringContainsString('password_hash(', $src);
        $this->assertStringContainsString('password_verify(', $src);
        $this->assertStringNotContainsString('md5(', $src);
        $this->assertStringNotContainsString('sha1(', $src);
    }

    /* ---------- login lifecycle: logout is a state change, not a link ---- */

    public function testLogoutIsPostOnlyAndCsrfProtected()
    {
        $auth = file_get_contents(self::$root.'/application/controllers/Auth.php');
        $logout = preg_replace('/.*public function logout\(\)/s', '', $auth);
        $logout = substr($logout, 0, strpos($logout, "\n    }\n"));
        $this->assertStringContainsString("\$this->input->method(true) !== 'POST'", $logout,
            'logout must refuse GET so a third party cannot prime a logout CSRF');
        // csrf_protection=TRUE in config.php means every POST carries the
        // token; assert every rendered logout control actually submits one.
        foreach (array(
            'application/views/layouts/app.php',
            'application/views/layouts/auth.php',
            'application/views/partials/public_nav.php',
            'application/views/auth/mfa.php',
            'application/views/dashboard/account/security.php',
        ) as $view) {
            $src = file_get_contents(self::$root.'/'.$view);
            $this->assertStringNotContainsString("href=\"<?=site_url('logout')?>\"", $src,
                $view.' must not link to logout with a plain anchor');
            if (strpos($src, "site_url('logout')") !== false) {
                $this->assertMatchesRegularExpression(
                    '~<form method="post" action="<\?=site_url\(\'logout\'\)\?>"~', $src,
                    $view.' must post the logout');
                $this->assertStringContainsString('get_csrf_hash()', $src,
                    $view.' must embed the CSRF token near the logout form');
            }
        }
        // And nowhere in ANY rendered view does a GET logout remain.
        foreach ($this->view_files() as $file) {
            $src = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '~<a\s[^>]*href="<\?=site_url\(\'logout\'\)\?>"~', $src,
                basename($file).' still exposes a GET logout link');
        }
    }

    public function testSessionsAreRegeneratedOnEveryPrivilegeTransition()
    {
        $auth = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        // complete_login (post-authentication) and change_password.
        $this->assertGreaterThanOrEqual(2, substr_count($auth, 'sess_regenerate(true)'),
            'login and password change must both regenerate the session id');
        // Impersonation is a privilege change in BOTH directions.
        $imp = file_get_contents(self::$root.'/application/libraries/ImpersonationService.php');
        $this->assertGreaterThanOrEqual(2, substr_count($imp, 'sess_regenerate(true)'),
            'impersonation enter and exit must both regenerate the session id');
    }

    /* ---------- encrypted secrets: fail closed, never plaintext fallback -- */

    public function testMfaSecretsUseAuthenticatedDecryptionOnly()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        $this->assertStringNotContainsString('->decrypt(', $src,
            'a tampered TOTP secret must fail closed, never become the seed');
        $this->assertGreaterThanOrEqual(3, substr_count($src, '->open($method->secret)'),
            'verify_mfa, confirm_mfa and disable_mfa all open() the secret');
        $this->assertStringContainsString('MFA_SECRET_UNREADABLE', $src);
    }

    public function testDecryptPlaintextFallbackIsConfinedToLegacyProviderKeys()
    {
        // decrypt() returns its input on failure BY DESIGN for provider API
        // keys that predate mandatory encryption. Nothing else may use it.
        $allowed = array(
            'application/libraries/DojahAdapter.php',
            'application/libraries/FiveSimAdapter.php',
            'application/libraries/ReloadlyAdapter.php',
            'application/libraries/StandardSmmAdapter.php',
            'application/libraries/StandardVtuAdapter.php',
            'application/libraries/VtpassAdapter.php',
            'application/libraries/EncryptionService.php', // its own definition
        );
        foreach ($this->php_files() as $file) {
            $rel = str_replace(self::$root.'/', '', $file);
            if (in_array($rel, $allowed, true)) continue;
            $src = file_get_contents($file);
            $this->assertStringNotContainsString('->decrypt(', $src,
                $rel.' must use open() (authenticated, fail-closed) instead');
        }
        // Identity results and gift-card codes are on the audited open() path.
        foreach (array('application/libraries/IdentityService.php',
                       'application/libraries/GiftcardService.php') as $svc) {
            $this->assertStringContainsString('->open(',
                file_get_contents(self::$root.'/'.$svc));
        }
    }

    /* ---------- IDOR: customer controllers only read what they own ------- */

    public function testCustomerControllersScopeEveryRecordToTheCurrentUser()
    {
        // One accessor/ownership guard per customer record surface; the
        // audit verified each controller binds lookups to current_user->id.
        $expect = array(
            'Orders'      => array('find_public_for_user($public_id, $this->current_user->id)'),
            'Wallet'      => array('find_public_for_user($public_id, $this->current_user->id)'),
            'Giftcards'   => array('$this->current_user->id', 'function owned('),
            'Identity'    => array('$this->current_user->id', 'function owned('),
            'Numbers'     => array('$this->current_user->id', 'function owned('),
            'Tickets'     => array('find_public_for_user($public_id, $this->current_user->id)'),
            'Marketplace' => array('(int)$order->buyer_id !== (int)$this->current_user->id'),
        );
        foreach ($expect as $controller => $needles) {
            $src = file_get_contents(self::$root.'/application/controllers/dashboard/'.$controller.'.php');
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $src,
                    'dashboard/'.$controller.'.php must scope reads to the current user: '.$needle);
            }
        }
        // Direct numeric ids must never be taken from user input for these
        // resources: every lookup ends at *_for_user or a public-id + owner
        // check. Assert no controller reads a record by post('id').
        foreach (array('Orders', 'Wallet', 'Giftcards', 'Identity', 'Numbers',
                       'Tickets', 'Marketplace') as $controller) {
            $src = file_get_contents(self::$root.'/application/controllers/dashboard/'.$controller.'.php');
            $this->assertStringNotContainsString("post('id'", $src,
                $controller.' must not bind records by a submitted numeric id');
        }
    }

    /* ---------- mass assignment: sensitive columns never from POST ------- */

    public function testSensitiveUserColumnsCannotBeMassAssigned()
    {
        // Registration builds the users row from an explicit field list and
        // hard-codes role=CUSTOMER — nothing the POST carries may influence
        // role, balance, status or any is_admin-style flag.
        $auth = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        $insert = substr($auth, strpos($auth, "insert('users', array("), 1200);
        $this->assertStringContainsString("'role'              => 'CUSTOMER',", $insert);
        $this->assertStringNotContainsString("'role' => \$", $insert);
        $this->assertStringNotContainsString("post('role'", $auth);
        $this->assertStringNotContainsString("post('balance'", $auth);
        $this->assertStringNotContainsString("post('is_admin'", $auth);

        // Profile self-service writes an explicit five-field allowlist only.
        $account = file_get_contents(self::$root.'/application/controllers/dashboard/Account.php');
        $data = substr($account, strpos($account, "\$data = array("), 600);
        foreach (array('role', 'balance', 'status', 'is_admin', 'mfa_enabled') as $forbidden) {
            $this->assertDoesNotMatchRegularExpression(
                '~["\']'.$forbidden.'["\']\s*=>~', $data,
                'profile update must never write '.$forbidden);
        }

        // Role changes exist EXACTLY once, behind the escalation guard.
        $svc = file_get_contents(self::$root.'/application/libraries/UserAdminService.php');
        $this->assertStringContainsString("Only a super admin can grant the super admin role.", $svc);
        $this->assertStringContainsString('is_last_super_admin', $svc);
        $users = file_get_contents(self::$root.'/application/controllers/admin/Users.php');
        $this->assertStringContainsString("guard(\$public_id, 'staff.manage')", $users);

        // Wallet balances: nobody writes wallets.balance directly outside the
        // ledger — the audited credit/debit paths are the only writers.
        foreach ($this->php_files() as $file) {
            $rel = str_replace(self::$root.'/', '', $file);
            if (strpos($rel, 'application/libraries/LedgerService.php') === 0) continue;
            $src = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                "~update\\('wallets'\\s*,\\s*array\\([^)]*'balance'~s", $src,
                $rel.' writes wallets.balance outside the ledger');
        }
    }

    /* ------------------------------ helpers ------------------------------ */

    private function limiter(&$db)
    {
        $db = new SecFakeAttemptDb();
        // RateLimiter aliases $this->ci =& get_instance(), so the fake CI's db
        // IS the counter store. Make it the attempts fake (with a conn_id so
        // windels_load_database() accepts it) BEFORE constructing — reflection
        // afterwards would write through the alias and replace the global.
        $fake = new stdClass();
        $fake->db = $db;
        $fake->db->conn_id = 'fake';
        $GLOBALS['__fake_ci'] = $fake;
        $rl = new RateLimiter();
        return $rl;
    }

    private function http_client($allow_private = false)
    {
        require_once self::$root.'/application/libraries/SecureHttpClient.php';
        $GLOBALS['__fake_ci'] = new SecFakeHttpCI($allow_private);
        return new SecureHttpClient();
    }

    /** @return string|null rejection reason */
    private function reject($client, $url)
    {
        $m = new ReflectionMethod($client, 'reject_url');
        $m->setAccessible(true);
        return $m->invoke($client, $url);
    }

    private function php_files()
    {
        $out = array();
        foreach (array('libraries', 'models', 'controllers', 'core', 'helpers') as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$root.'/application/'.$dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    private function view_files()
    {
        $out = array();
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root.'/application/views'));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
        }
        return $out;
    }
}

/* ------------------------------- doubles --------------------------------- */

/**
 * login_attempts double that actually honours where() on email/ip/created_at,
 * so the per-bucket separation is genuinely exercised rather than assumed.
 */
class SecFakeAttemptDb {
    public $conn_id = 'fake'; // mirrors a live connection for windels_load_database()
    public $rows = array();
    private $w = array(), $since = null, $order_desc = false, $limit = null;

    public function insert($t, $d) { $this->rows[] = $d; return $this; }

    public function where($k, $v = null, $esc = null) {
        if ($k === 'created_at >=') { $this->since = $v; return $this; }
        $this->w[$k] = $v;
        return $this;
    }
    public function select($s) { return $this; }
    public function order_by($k, $dir = 'ASC') { $this->order_desc = strtoupper($dir) === 'DESC'; return $this; }
    public function limit($n, $o = 0) { $this->limit = $n; return $this; }

    public function count_all_results($t) { return count($this->matching()); }

    public function get($t) {
        $rows = $this->matching();
        usort($rows, function ($a, $b) { return strcmp($a['created_at'], $b['created_at']); });
        if ($this->order_desc) $rows = array_reverse($rows);
        if ($this->limit !== null) $rows = array_slice($rows, 0, $this->limit);
        $this->reset();
        return new SecFakeResult(array_map(function ($r) { return (object)$r; }, $rows));
    }

    private function matching() {
        $w = $this->w; $since = $this->since;
        $this->reset();
        $out = array();
        foreach ($this->rows as $r) {
            $ok = true;
            foreach ($w as $col => $val) {
                if ((string)($r[$col] ?? '') !== (string)$val) { $ok = false; break; }
            }
            if ($ok && $since !== null && $r['created_at'] < $since) $ok = false;
            if ($ok) $out[] = $r;
        }
        return $out;
    }

    private function reset() { $this->w = array(); $this->since = null; $this->order_desc = false; $this->limit = null; }
}

class SecFakeResult {
    private $rows; public function __construct($r) { $this->rows = $r; }
    public function result() { return $this->rows; }
    public function row() { return $this->rows ? $this->rows[0] : null; }
}

class SecFakeHttpCI {
    public $config;
    public function __construct($allow_private) { $this->config = new SecFakeHttpConfig($allow_private); }
}
class SecFakeHttpConfig {
    private $allow;
    public function __construct($allow) { $this->allow = $allow; }
    public function item($k) { return $k === 'http_allow_private_hosts' ? $this->allow : null; }
}
