<?php
use PHPUnit\Framework\TestCase;

/**
 * Totp tests — RFC 6238/4226 vector, enrolment helpers and recovery codes.
 * No database or CI3 required.
 */
class TotpTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        require_once self::$root.'/application/libraries/Totp.php';
    }

    public function testGeneratedSecretIsBase32()
    {
        $secret = Totp::generate_secret();
        $this->assertSame(32, strlen($secret)); // 20 bytes -> 32 base32 chars (no padding)
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testProvisioningUriFormat()
    {
        $uri = Totp::provisioning_uri('JBSWY3DPEHPK3PXP', 'user@example.com', 'WINDELS');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=WINDELS', $uri);
        $this->assertStringContainsString('digits=6', $uri);
    }

    public function testVerifyAcceptsCurrentCode()
    {
        $secret = Totp::generate_secret();
        // Deterministic timestamp: 59 seconds into the epoch period boundary
        // from the RFC 6238 appendix B style — compute the expected code ourselves.
        $time = 1111111111;
        $code = Totp::now($secret, $time);
        $this->assertTrue(Totp::verify($secret, $code, 1, $time));
    }

    public function testVerifyRejectsWrongCode()
    {
        $secret = Totp::generate_secret();
        $this->assertFalse(Totp::verify($secret, '000000', 0, 1111111111));
    }

    public function testVerifyRejectsMalformedInput()
    {
        $secret = Totp::generate_secret();
        $this->assertFalse(Totp::verify($secret, 'abc', 1));
        $this->assertFalse(Totp::verify($secret, '12345', 1));
        $this->assertFalse(Totp::verify('not-base32!', '123456', 1));
    }

    public function testClockSkewWindow()
    {
        $secret = Totp::generate_secret();
        // Generate a code for one period in the past; with window=1 it verifies.
        $past = time() - 30;
        $code = Totp::now($secret, $past);
        $this->assertTrue(Totp::verify($secret, $code, 1, time()));
        // Two periods in the past is outside the window.
        $far = time() - 60;
        $farCode = Totp::now($secret, $far);
        $this->assertFalse(Totp::verify($secret, $farCode, 1, time()));
    }

    public function testRfc6238AppendixBSha1Vector()
    {
        // Seed "12345678901234567890" (ASCII) per RFC 6238 Appendix B, T=59, sha1.
        // The standard expects 94287082 for 8 digits; we use 6 digits so the
        // expected truncated value is 287082.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of that seed
        $code = Totp::now($secret, 59);
        $this->assertSame('287082', $code);
        $this->assertTrue(Totp::verify($secret, $code, 1, 59));
    }

    public function testRecoveryCodesAreGeneratedAndConsumedOnce()
    {
        $codes = Totp::generate_recovery_codes(4);
        $this->assertCount(4, $codes['plain']);
        $this->assertCount(4, $codes['hashed']);
        foreach ($codes['plain'] as $plain) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $plain);
        }

        // Consume the second code — it must verify and the set shrink by one.
        $json = json_encode($codes['hashed']);
        $remaining = Totp::consume_recovery_code($json, $codes['plain'][1]);
        $this->assertIsArray($remaining);
        $this->assertCount(3, $remaining);

        // The same code cannot be used twice.
        $second = Totp::consume_recovery_code(json_encode($remaining), $codes['plain'][1]);
        $this->assertFalse($second);
    }

    public function testRecoveryCodeRejectsGarbage()
    {
        $codes = Totp::generate_recovery_codes(2);
        $this->assertFalse(Totp::consume_recovery_code(json_encode($codes['hashed']), 'NOPE-0000'));
        $this->assertFalse(Totp::consume_recovery_code('not-json', 'NOPE-0000'));
    }
}
