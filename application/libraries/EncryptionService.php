<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * EncryptionService — AES-256-GCM for secrets held at rest.
 *
 * This protects provider API keys (StandardSmmAdapter) and TOTP MFA secrets
 * (AuthService). Both are game-over if they leak.
 *
 * The key comes from ENCRYPTION_KEY. There used to be a hardcoded fallback
 * ('change-me-32-byte-key-replace!!'), which meant a deployment that forgot to
 * set the variable encrypted every secret with a key published in this repo —
 * indistinguishable from plaintext to anyone who reads the source. In
 * production that is now a hard boot failure (§61); outside production we fall
 * back to a clearly-marked development key so local setup stays frictionless.
 */
class EncryptionService {

    /** Weak keys that must never be used to protect real secrets. */
    const REJECTED_KEYS = array(
        'change-me-32-byte-key-replace!!',
        'change-me-32-byte-key-replace-in-env',
        'change-me-32-byte-base64-key-please-replace',
        'base64:change-me-32chars-encryption-key-for-at-rest',
    );

    /** Shortest key we will accept in production. */
    const MIN_KEY_LENGTH = 32;

    private $key;

    public function __construct() {
        $this->key = hash('sha256', self::resolve_key(), TRUE);
    }

    /**
     * Pick the raw key material, refusing to protect production data with a
     * placeholder. Static so deployment preflight can validate without
     * constructing the service.
     */
    public static function resolve_key($environment = null) {
        $env = $environment !== null
            ? $environment
            : (defined('ENVIRONMENT') ? ENVIRONMENT : 'production');
        $key = getenv('ENCRYPTION_KEY');
        $key = ($key === false) ? '' : trim($key);

        $problem = self::key_problem($key);
        if ($problem === null) return $key;

        if ($env === 'production') {
            // Loud, not silent: a bad key here is unrecoverable once secrets
            // have been written with it.
            throw new RuntimeException(
                'ENCRYPTION_KEY is '.$problem.'. Refusing to start in production: '
                .'provider API keys and MFA secrets would be encrypted with a key '
                .'that is public in the source tree. Generate one with '
                .'`openssl rand -base64 32` and set ENCRYPTION_KEY.'
            );
        }
        // Development/testing: usable, but never mistaken for a real key.
        return 'insecure-development-key-do-not-use-in-production';
    }

    /**
     * Why this key is unusable, or NULL when it is fine.
     * Public so the preflight check and its tests can share one definition.
     */
    public static function key_problem($key) {
        $key = trim((string)$key);
        if ($key === '') return 'not set';
        if (in_array($key, self::REJECTED_KEYS, TRUE)) return 'still the placeholder from .env.example';
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            return 'too short (needs at least '.self::MIN_KEY_LENGTH.' characters)';
        }
        return null;
    }

    public function encrypt($plain){
        $iv = random_bytes(12);
        $tag=''; $ct = openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);
        return base64_encode($iv.$tag.$ct);
    }

    public function decrypt($b64){
        $raw = base64_decode($b64);
        if (strlen($raw) < 28) return $b64; // fallback for plain
        $iv = substr($raw,0,12); $tag=substr($raw,12,16); $ct=substr($raw,28);
        $pt = openssl_decrypt($ct,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);
        return $pt !== FALSE ? $pt : $b64;
    }
}
