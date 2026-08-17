<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SignedToken — stateless, HMAC-signed single-purpose tokens.
 *
 * Used for email verification and password reset links so we do not need a
 * dedicated token table. The token is an URL-safe base64 of:
 *
 *   JSON({ sub, purpose, exp, iat, nonce? }) || "." || base64(hmac_sha256)
 *
 * The signing key is derived from APP_KEY (falling back to ENCRYPTION_KEY).
 * For password-reset tokens the caller may pass a "fingerprint" (a fragment of
 * the user's current password_hash) which is mixed into the signature; changing
 * the password therefore invalidates every outstanding reset token without a
 * server-side revocation list.
 *
 * This class has no CI dependency so it can be unit-tested in isolation.
 */
class SignedToken {

    const DEFAULT_TTL = 3600; // 60 minutes

    /** @var string */
    private $key;

    public function __construct($key = null) {
        if ($key === null || $key === '') {
            $key = getenv('APP_KEY') ?: getenv('ENCRYPTION_KEY') ?: 'change-me-app-key-in-env';
        }
        // Normalise to a 32-byte key regardless of input length.
        $this->key = hash('sha256', (string)$key, true);
    }

    /**
     * Issue a signed token.
     *
     * @param int|string $subject     user id / public id
     * @param string     $purpose     e.g. "verify-email", "reset-password"
     * @param int        $ttl         seconds until expiry
     * @param string     $fingerprint optional context bound into the signature
     * @return string URL-safe token
     */
    public function issue($subject, $purpose, $ttl = self::DEFAULT_TTL, $fingerprint = '') {
        $now = time();
        $payload = array(
            'sub'     => (string)$subject,
            'purpose' => (string)$purpose,
            'iat'     => $now,
            'exp'     => $now + max(60, (int)$ttl),
        );
        $body = $this->url_safe_encode((string)json_encode($payload));
        return $body . '.' . $this->sign($body, (string)$fingerprint);
    }

    /**
     * Verify a token. Returns the decoded payload array, or null when the token
     * is malformed, expired, purpose-mismatched, or has a bad signature.
     *
     * @param string $token
     * @param string $expected_purpose
     * @param string $fingerprint must match the one used at issue time
     * @return array|null
     */
    public function verify($token, $expected_purpose, $fingerprint = '') {
        if (!is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }
        list($body, $sig) = explode('.', $token, 2);
        if ($body === '' || $sig === '') {
            return null;
        }
        $expected = $this->sign($body, (string)$fingerprint);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $raw = $this->url_safe_decode($body);
        if ($raw === false || $raw === null) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['sub']) || empty($payload['purpose'])) {
            return null;
        }
        if (!isset($payload['exp']) || (int)$payload['exp'] < time()) {
            return null;
        }
        if ($payload['purpose'] !== (string)$expected_purpose) {
            return null;
        }
        return $payload;
    }

    /* ------------------------------- internals ------------------------------- */

    private function sign($body, $fingerprint) {
        $mac = hash_hmac('sha256', $body . '|' . $fingerprint, $this->key, true);
        return $this->url_safe_encode($mac);
    }

    private function url_safe_encode($bytes) {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function url_safe_decode($s) {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
