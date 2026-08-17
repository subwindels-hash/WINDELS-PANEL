<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Totp — RFC 6238 time-based one-time passwords (TOTP) / RFC 4226 HOTP.
 *
 * Used for the MFA step in Auth::login and the mfa setup flow. Secrets are
 * generated, stored encrypted at rest (EncryptionService) and never logged.
 *
 * Pure PHP (ext-openssl + hash_hmac); no CI dependency, so it is unit-testable.
 */
class Totp {

    const PERIOD    = 30;
    const DIGITS    = 6;
    const ALGORITHM = 'sha1';
    const SECRET_BYTES = 20; // 160-bit secret -> 32 base32 chars
    const ISSUER    = 'WINDELS PANEL';
    const WINDOW    = 1;  // accept +/-1 period for clock skew

    /**
     * Generate a new random base32-encoded secret.
     */
    public static function generate_secret() {
        return self::base32_encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * otpauth:// provisioning URI for QR-code enrolment.
     */
    public static function provisioning_uri($secret, $account, $issuer = self::ISSUER) {
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query(array(
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ));
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Verify a user-supplied 6-digit code against a base32 secret, allowing a
     * small time window either side of now for clock drift. Constant-time.
     */
    public static function verify($secret, $code, $window = self::WINDOW, $time = null) {
        $code = preg_replace('/\s+/', '', (string)$code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $key = self::base32_decode($secret);
        if ($key === false || $key === '') {
            return false;
        }
        $counter = (int)floor(($time !== null ? (int)$time : time()) / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::hotp($key, $counter + $i);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate the current code — exposed for tests and recovery flows.
     */
    public static function now($secret, $time = null) {
        $counter = (int)floor(($time !== null ? (int)$time : time()) / self::PERIOD);
        return self::hotp(self::base32_decode($secret), $counter);
    }

    /**
     * Generate N hashed recovery codes (plaintext returned once, hashes stored).
     *
     * @return array{plain: string[], hashed: string[]}
     */
    public static function generate_recovery_codes($count = 8) {
        $plain = array();
        $hashed = array();
        for ($i = 0; $i < $count; $i++) {
            // XXXX-XXXX format, unambiguous alphabet.
            $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $alpha[random_int(0, strlen($alpha) - 1)];
                if ($j === 3) {
                    $code .= '-';
                }
            }
            $plain[]  = $code;
            $hashed[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return array('plain' => $plain, 'hashed' => $hashed);
    }

    /**
     * Consume a recovery code against a JSON array of hashes. The matching hash
     * is removed (single-use). Returns the updated hashed array, or false.
     */
    public static function consume_recovery_code($hashed_codes_json, $submitted) {
        $hashes = json_decode((string)$hashed_codes_json, true);
        if (!is_array($hashes)) {
            return false;
        }
        $submitted = trim((string)$submitted);
        foreach ($hashes as $i => $hash) {
            if (password_verify($submitted, $hash)) {
                array_splice($hashes, $i, 1);
                return array_values($hashes);
            }
        }
        return false;
    }

    /* ------------------------------ RFC 4226 ------------------------------ */

    private static function hotp($key, $counter) {
        // 64-bit big-endian counter (the 'J' flag is available on PHP 8.1+;
        // fall back to manual packing on older builds).
        if (PHP_VERSION_ID >= 80100) {
            $bin = pack('J', $counter);
        } else {
            $bin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        }
        $hash = hash_hmac(self::ALGORITHM, $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary =
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
            ( ord($hash[$offset + 3]) & 0xFF);
        $otp = $binary % pow(10, self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /* ------------------------------ base32 ------------------------------ */

    private static function base32_encode($bytes) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= $alphabet[bindec($chunk)];
        }
        // Pad to a multiple of 8 per RFC 4648.
        if (strlen($out) % 8) {
            $out .= str_repeat('=', 8 - (strlen($out) % 8));
        }
        return $out;
    }

    private static function base32_decode($b32) {
        if (!preg_match('/^[A-Z2-7]+=*$/', strtoupper((string)$b32))) {
            return false;
        }
        $b32 = strtoupper(rtrim((string)$b32, '='));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($b32) as $ch) {
            $bits .= str_pad(decbin(strpos($alphabet, $ch)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
