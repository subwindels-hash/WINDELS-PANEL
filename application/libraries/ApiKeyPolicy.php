<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Validation and normalization for reseller API-key policy.
 *
 * Null scopes mean full access for backwards compatibility with keys created
 * before scoped access shipped. A JSON array is an explicit allow-list; an
 * empty array therefore disables every endpoint without disclosing or rotating
 * the credential.
 */
class ApiKeyPolicy {
    const MIN_RATE_LIMIT = 1;
    const MAX_RATE_LIMIT = 10000;
    const MAX_IPS = 50;

    public static function scopes() {
        return array(
            'services.read'  => 'Read the active SMM service catalogue and resolved prices',
            'orders.read'    => 'Read orders, bulk statuses, and refill statuses',
            'orders.write'   => 'Place, refill, and cancel orders (wallet spending)',
            'account.read'   => 'Read the wallet balance',
            'referrals.read' => 'Read referral summary and commission totals',
        );
    }

    /** Parse exact IPv4/IPv6 addresses. CIDR is not accepted by the runtime. */
    public function parse_ip_whitelist($input) {
        if (is_array($input)) {
            $parts = $input;
        } else {
            $raw = trim((string)$input);
            $parts = $raw === '' ? array() : preg_split('/[\s,]+/', $raw);
        }
        $parts = array_values(array_filter(array_map(function ($ip) {
            return trim((string)$ip);
        }, $parts), 'strlen'));

        if (count($parts) > self::MAX_IPS) {
            return array('ok'=>false, 'error'=>'IP whitelist may contain at most '.self::MAX_IPS.' addresses.');
        }

        $valid = array();
        foreach ($parts as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return array('ok'=>false, 'error'=>'Invalid IP address: '.$ip);
            }
            // Canonical text keeps equivalent IPv6 spellings comparable while
            // preserving exact-address (not CIDR/range) semantics.
            $packed = @inet_pton($ip);
            $canonical = $packed === false ? false : @inet_ntop($packed);
            if ($canonical === false) return array('ok'=>false, 'error'=>'Invalid IP address: '.$ip);
            if (!in_array($canonical, $valid, true)) $valid[] = $canonical;
        }
        return array('ok'=>true, 'value'=>$valid);
    }

    public function validate_update(array $input) {
        $name = trim((string)($input['name'] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $length > 64) {
            return $this->bad('Name is required and must be 64 characters or fewer.');
        }

        $rate_raw = trim((string)($input['rate_limit_per_minute'] ?? ''));
        if (!preg_match('/^\d+$/', $rate_raw)) {
            return $this->bad('Rate limit must be a whole number.');
        }
        $rate = (int)$rate_raw;
        if ($rate < self::MIN_RATE_LIMIT || $rate > self::MAX_RATE_LIMIT) {
            return $this->bad('Rate limit must be between '.self::MIN_RATE_LIMIT.' and '.self::MAX_RATE_LIMIT.' requests per minute.');
        }

        $ips = $this->parse_ip_whitelist($input['ip_whitelist'] ?? '');
        if (empty($ips['ok'])) return $ips;

        // Require an explicit mode so a partial/malformed admin submission can
        // never silently broaden or replace the stored scope policy.
        $mode = strtolower(trim((string)($input['access_mode'] ?? '')));
        if (!in_array($mode, array('full', 'scoped'), true)) {
            return $this->bad('Choose full or scoped endpoint access.');
        }

        $scopes = null;
        if ($mode === 'scoped') {
            $raw_scopes = $input['scopes'] ?? array();
            if (!is_array($raw_scopes)) return $this->bad('Scopes must be submitted as a list.');
            $scopes = array_values(array_unique(array_map('strval', $raw_scopes)));
            $catalogue = self::scopes();
            foreach ($scopes as $scope) {
                if (!array_key_exists($scope, $catalogue)) {
                    return $this->bad('Unknown API scope: '.$scope);
                }
            }
        }

        $expiry = $this->expiry($input['expires_at'] ?? '');
        if (empty($expiry['ok'])) return $expiry;

        return array('ok'=>true, 'data'=>array(
            'name' => $name,
            'ip_whitelist' => $ips['value'] ? json_encode($ips['value']) : null,
            'scopes' => $scopes === null ? null : json_encode($scopes),
            'rate_limit_per_minute' => $rate,
            'expires_at' => $expiry['value'],
        ));
    }

    private function expiry($input) {
        $raw = trim((string)$input);
        if ($raw === '') return array('ok'=>true, 'value'=>null);

        $formats = array('Y-m-d\TH:i', 'Y-m-d H:i:s');
        $date = null;
        foreach ($formats as $format) {
            $candidate = DateTime::createFromFormat('!'.$format, $raw, new DateTimeZone('UTC'));
            $errors = DateTime::getLastErrors();
            if ($candidate && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $candidate->format($format) === $raw) {
                $date = $candidate;
                break;
            }
        }
        if (!$date) return $this->bad('Expiry must be a valid UTC date and time.');
        if ($date->getTimestamp() <= time()) return $this->bad('Expiry must be in the future. Revoke a key to stop it now.');
        return array('ok'=>true, 'value'=>$date->format('Y-m-d H:i:s'));
    }

    private function bad($message) {
        return array('ok'=>false, 'error'=>$message);
    }
}
