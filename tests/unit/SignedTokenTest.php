<?php
use PHPUnit\Framework\TestCase;

/**
 * SignedToken tests — verify the stateless HMAC token primitive used for email
 * verification and password reset. No database or CI3 required.
 */
class SignedTokenTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        require_once self::$root.'/application/libraries/SignedToken.php';
    }

    public function testRoundTripVerifies()
    {
        $t = new SignedToken('test-signing-key');
        $token = $t->issue('user_01', 'verify-email', 600);
        $payload = $t->verify($token, 'verify-email');

        $this->assertIsArray($payload);
        $this->assertSame('user_01', $payload['sub']);
        $this->assertSame('verify-email', $payload['purpose']);
        $this->assertArrayHasKey('exp', $payload);
    }

    public function testTamperedBodyRejected()
    {
        $t = new SignedToken('test-signing-key');
        $token = $t->issue('user_01', 'verify-email', 600);
        // Flip a character in the body.
        $tampered = $token[0] === 'A' ? 'B'.substr($token, 1) : 'A'.substr($token, 1);
        $this->assertNull($t->verify($tampered, 'verify-email'));
    }

    public function testWrongPurposeRejected()
    {
        $t = new SignedToken('test-signing-key');
        $token = $t->issue('user_01', 'verify-email', 600);
        $this->assertNull($t->verify($token, 'reset-password'));
    }

    public function testExpiredTokenRejected()
    {
        $t = new SignedToken('test-signing-key');
        // TTL is 1 second; the constructor clamps to >= 60, so build by hand.
        $body = $this->issue_with_ttl($t, 'user_01', 'verify-email', -10);
        $this->assertNull($t->verify($body, 'verify-email'));
    }

    public function testDifferentKeyRejected()
    {
        $issuer = new SignedToken('key-one');
        $verifier = new SignedToken('key-two');
        $token = $issuer->issue('user_01', 'verify-email', 600);
        $this->assertNull($verifier->verify($token, 'verify-email'));
    }

    public function testFingerprintBindsToken()
    {
        $t = new SignedToken('test-signing-key');
        $fp = 'abc123def456';
        $token = $t->issue('user_01', 'reset-password', 600, $fp);

        // Same fingerprint verifies; a different fingerprint does not (simulating
        // a password change invalidating outstanding reset tokens).
        $this->assertIsArray($t->verify($token, 'reset-password', $fp));
        $this->assertNull($t->verify($token, 'reset-password', 'changed-fingerprint'));
        $this->assertNull($t->verify($token, 'reset-password'));
    }

    /**
     * The reset flow's actual sequence, and the bug it used to hide.
     *
     * AuthService::begin_password_reset() signs the token with the user's
     * password fingerprint, but reset_password() cannot know that fingerprint
     * until it has looked the user up — and the subject it needs for that
     * lookup lives inside the token. It used to call verify() with NO
     * fingerprint to read the subject, which can never match a token signed
     * with one, so every reset link died as INVALID_OR_EXPIRED_TOKEN and
     * password reset did not work for any account at all.
     *
     * peek() reads the claims without authorising anything, so the lookup can
     * happen before the real, fingerprint-bound verify().
     */
    public function testPeekReadsTheSubjectOfAFingerprintBoundToken()
    {
        $t = new SignedToken('test-signing-key');
        $fp = 'abc123def456';
        $token = $t->issue('user_01', 'reset-password', 600, $fp);

        // The unauthenticated read the user lookup depends on.
        $claims = $t->peek($token, 'reset-password');
        $this->assertIsArray($claims,
            'peek() must read a fingerprint-bound token — the whole reset flow depends on it');
        $this->assertSame('user_01', $claims['sub']);

        // And the authorising check still requires the right fingerprint.
        $this->assertIsArray($t->verify($token, 'reset-password', $fp));
        $this->assertNull($t->verify($token, 'reset-password', 'changed-fingerprint'),
            'peek() must not weaken the single-use binding');
    }

    public function testPeekStillRejectsExpiredAndMismatchedTokens()
    {
        $t = new SignedToken('test-signing-key');

        $expired = $this->issue_with_ttl($t, 'user_01', 'reset-password', -10);
        $this->assertNull($t->peek($expired, 'reset-password'),
            'an expired token must not be readable even unauthenticated');

        $token = $t->issue('user_01', 'verify-email', 600);
        $this->assertNull($t->peek($token, 'reset-password'),
            'peek() must honour the expected purpose');

        $this->assertNull($t->peek('garbage', 'reset-password'));
        $this->assertNull($t->peek('a.b.c', 'reset-password'));
        $this->assertNull($t->peek('', 'reset-password'));
    }

    public function testMalformedTokensRejected()
    {
        $t = new SignedToken('test-signing-key');
        $this->assertNull($t->verify('', 'verify-email'));
        $this->assertNull($t->verify('garbage', 'verify-email'));
        $this->assertNull($t->verify('a.b.c', 'verify-email'));
    }

    public function testTokensAreUrlSafe()
    {
        $t = new SignedToken('test-signing-key');
        $token = $t->issue('user_01', 'verify-email', 600);
        $this->assertDoesNotMatchRegularExpression('#[+/=]#', $token);
    }

    private function issue_with_ttl(SignedToken $t, $sub, $purpose, $ttl)
    {
        // Reach into the private sign routine via reflection to create an
        // already-expired token without sleeping.
        $now = time();
        $payload = array('sub' => (string)$sub, 'purpose' => $purpose, 'iat' => $now, 'exp' => $now + $ttl);
        $enc = new ReflectionClass($t);
        $encode = $enc->getMethod('url_safe_encode'); $encode->setAccessible(true);
        $sign = $enc->getMethod('sign'); $sign->setAccessible(true);
        $body = $encode->invoke($t, (string)json_encode($payload));
        return $body.'.'.$sign->invoke($t, $body, '');
    }
}
