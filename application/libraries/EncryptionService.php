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

    /**
     * Decrypt, or NULL — never the ciphertext back.
     *
     * decrypt() deliberately returns its input unchanged when the payload is
     * too short or the GCM tag does not verify. That fallback exists because
     * provider API keys predate this class and some rows are still plaintext,
     * so reading one has to keep working. It is exactly the wrong behaviour
     * for anything written *after* encryption became mandatory: a caller that
     * cannot tell "here is the plaintext" from "this did not decrypt" will
     * happily render a base64 blob into a page, or worse, treat a corrupted
     * record as a valid one.
     *
     * Identity results (§22) use this instead. A failure here means the key
     * changed, the row was tampered with, or the retention sweep scrubbed it —
     * all of which must read as "no result", not as data.
     *
     * @return string|null plaintext, or NULL when it could not be recovered
     */
    public function open($b64){
        if ($b64 === null || $b64 === '') return null;

        $raw = base64_decode((string)$b64, TRUE);
        if ($raw === FALSE || strlen($raw) < 28) return null;

        $iv = substr($raw,0,12); $tag=substr($raw,12,16); $ct=substr($raw,28);
        $pt = openssl_decrypt($ct,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);
        return $pt !== FALSE ? $pt : null;
    }

    /**
     * A searchable fingerprint of a value we refuse to store (§22).
     *
     * NINs and BVNs are looked up by equality ("have we checked this one
     * before?", "is this the identifier on that receipt?") but must not be
     * recoverable from the database. An HMAC over the value answers equality
     * without ever holding the value.
     *
     * Three properties matter, and each rules out a simpler option:
     *
     *  - **Keyed, not a bare hash.** A NIN is 11 digits. A plain sha256 of an
     *    11-digit number is brute-forceable in seconds — the whole keyspace is
     *    10^11 — so an unkeyed digest would be a reversible copy of the NIN
     *    wearing a hat. The HMAC key is ENCRYPTION_KEY, which is not in the
     *    database, so a dumped table alone yields nothing.
     *  - **Context-separated.** The same digits can be a NIN and, in another
     *    scheme, something else. Binding the context means hashes from
     *    different domains never collide into a cross-reference.
     *  - **Normalised first.** ' 123 456 78901 ' and '12345678901' are the
     *    same identifier to a human and to the vendor, so they must be the
     *    same index here, or the duplicate check silently misses.
     *
     * @param string $value   the raw identifier; never logged, never stored
     * @param string $context what kind of identifier it is, e.g. 'NIN'
     * @return string 64-char hex, sized for a CHAR(64) column
     */
    public function blind_index($value, $context = 'IDENTITY'){
        $normalised = strtoupper(preg_replace('/\s+/', '', (string)$value));
        return hash_hmac('sha256', strtoupper((string)$context).':'.$normalised, $this->key);
    }
}
