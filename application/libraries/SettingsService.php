<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SettingsService — the schema behind Admin → Settings.
 *
 * The `settings` table is free-form key/value JSON, which is right for storage
 * and useless for a form: nothing there says whether `referral_hold_hours` is
 * a number, whether `maintenance_mode` is a checkbox, or what happens if
 * someone types "yes" into it. This class is the missing description, so the
 * screen renders and validates itself from one declaration.
 *
 * Two rules shaped it, both learned from auditing what was already seeded.
 *
 * **Only wired settings appear.** Session 02 seeded 25 rows; a grep showed
 * eight of them — `site_tagline`, `maintenance_mode`, `currency_display`,
 * `default_theme`, `brand_*`, `order_auto_submit`, `partial_refund_enabled`,
 * `admin_mfa_required`, `api_enabled` — are read by no code at all. Putting
 * them on a form would be worse than omitting them: an operator would switch
 * "maintenance mode" on, see it save, and watch the site stay up. Each is
 * listed in UNWIRED below with the work it would take to honour it, so the
 * omission is a documented decision rather than an oversight.
 *
 * **`base_currency` is deliberately read-only.** The row exists, but
 * `windels_base_currency()` reads `config/windels.php`, not this table, and
 * every priced row, wallet and ledger entry is denominated in whatever it
 * returned at the time. A form that edited the row would change nothing; a
 * form that actually switched the currency would silently reinterpret every
 * historical amount. Session 22 moved USD→NGN with a migration for exactly
 * that reason, and that remains the only safe way.
 */
class SettingsService {

    /**
     * The editable surface: key => [type, category, label, help, default].
     *
     * Types: `text`, `email`, `bool`, `int`, `money`, `percent`, `choice:a|b`.
     */
    public static function schema() {
        return array(
            'site_name' => array('text', 'general', 'Site name',
                'Shown in the browser title and in every email this panel sends.', 'WINDELS PANEL'),
            'support_email' => array('email', 'general', 'Support email',
                'The reply-to address on outgoing mail.', 'support@windels.local'),
            'active_homepage' => array('choice:AURORA|NEXUS|PULSE', 'homepage', 'Active homepage',
                'Which of the three homepage designs visitors land on.', 'AURORA'),

            'registration_enabled' => array('bool', 'security', 'Allow new sign-ups',
                'Off closes registration; existing customers can still sign in.', true),
            'email_verification_required' => array('bool', 'security', 'Require email verification',
                'New accounts must confirm their address before they can order.', true),

            'min_deposit' => array('money', 'payments', 'Minimum deposit',
                'The smallest top-up a customer may make.', '500.00000000'),
            'max_deposit' => array('money', 'payments', 'Maximum deposit',
                'The largest single top-up, before manual review.', '5000000.00000000'),

            'referral_commission_percent' => array('percent', 'affiliate', 'Referral commission',
                'Percentage of a referred customer’s spend paid to the referrer.', '5.0000'),
            'referral_commission_scope' => array('choice:LIFETIME|FIRST_ORDER', 'affiliate', 'Commission scope',
                'LIFETIME pays on every order; FIRST_ORDER pays once.', 'LIFETIME'),
            'referral_hold_hours' => array('int', 'affiliate', 'Commission hold (hours)',
                'How long a commission stays pending before it can be paid out.', 24),
            'referral_min_payout' => array('money', 'affiliate', 'Minimum payout',
                'Commissions smaller than this keep accumulating until they clear it.', '100.00000000'),

            'identity_retention_days' => array('int', 'identity', 'Identity result retention (days)',
                'How long an encrypted NIN/BVN result is kept before the purge worker deletes it. '
                .'A legal answer, not an engineering one — check your jurisdiction.', 30),
            'giftcard_sender_name' => array('text', 'giftcards', 'Gift card sender name',
                'The “from” name the recipient sees on a delivered card.', 'WINDELS PANEL'),

            // There is NO marketplace fee setting: with the platform as sole
            // seller the gross is the revenue — nothing is split or paid out.
            'marketplace_auto_release_hours' => array('int', 'marketplace', 'Escrow auto-release (hours)',
                'Hours after fulfilment before an undisputed order completes automatically (1–720).', 72),
        );
    }

    /**
     * Seeded settings this build does not honour, and what each would need.
     *
     * Kept in code rather than a doc so it stays honest: the screen renders
     * this list, so an operator can see the switch is missing on purpose.
     */
    public static function unwired() {
        return array(
            'site_tagline'           => 'Nothing reads it; the homepages carry their own copy.',
            'maintenance_mode'       => 'Needs a gate in MY_Controller that shows a holding page to non-staff.',
            'default_theme'          => 'No theme switcher exists yet.',
            'currency_display'       => 'windels_money() always prints a symbol.',
            'order_auto_submit'      => 'OrderService always submits to the provider immediately.',
            'partial_refund_enabled' => 'Partial refunds are always on; the state machine has no switch.',
            'admin_mfa_required'     => 'Needs enforcement in Admin_Controller, which would lock out admins without MFA.',
            'api_enabled'            => 'Needs a gate in Api_Controller.',
        );
    }

    /** Settings shown but not editable, with the reason. */
    public static function readonly_settings() {
        return array(
            'base_currency' => 'Changing this would reinterpret every stored amount. '
                .'It moves by migration only — see docs/session-22-currency.md.',
            // Wired, but edited on their own screen rather than as text fields:
            // a logo is chosen from the media library, not typed as a URL.
            'brand_primary_color' => 'Set in Admin → Appearance.',
            'brand_logo_url'      => 'Set in Admin → Appearance, from the media library.',
            'brand_favicon_url'   => 'Set in Admin → Appearance, from the media library.',
        );
    }

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('Setting_model');
    }

    /** Current values for every editable key, falling back to the default. */
    public function current() {
        $out = array();
        foreach (self::schema() as $key => $def) {
            $out[$key] = $this->ci->Setting_model->get($key, $def[4]);
        }
        return $out;
    }

    /** The editable schema grouped by category, for rendering. */
    public function grouped() {
        $out = array();
        foreach (self::schema() as $key => $def) {
            $out[$def[1]][$key] = $def;
        }
        return $out;
    }

    /**
     * Validate and persist a form submission.
     *
     * Only keys present in both the schema and the input are touched, so a
     * form that renders one category cannot blank the others. Returns
     * ok/error/changed, where `changed` is the before/after pairs for the
     * audit log — settings change rarely and matter a lot, so "who raised the
     * commission" must stay answerable.
     */
    public function save(array $input) {
        $schema  = self::schema();
        $errors  = array();
        $changed = array();
        $clean   = array();

        foreach ($schema as $key => $def) {
            list($type, , $label, , $default) = $def;

            // A bool that is off is absent from a POST body, so checkboxes are
            // only considered when the form declares it rendered them.
            $submitted = array_key_exists($key, $input);
            if ($type === 'bool') {
                $rendered = array_key_exists('__rendered_'.$key, $input);
                if (!$rendered) continue;
                $clean[$key] = $submitted && in_array((string)$input[$key], array('1','on','true'), true);
                continue;
            }
            if (!$submitted) continue;

            $value = is_string($input[$key]) ? trim($input[$key]) : $input[$key];
            $res   = $this->coerce($type, $value, $label);
            if ($res['error'] !== null) { $errors[] = $res['error']; continue; }
            $clean[$key] = $res['value'];
        }

        if ($errors) {
            return array('ok' => false, 'error' => implode(' ', $errors), 'changed' => array());
        }

        // Cross-field rules, checked against the merged result rather than the
        // submission, so raising the minimum in isolation still gets caught.
        $merged = array_merge($this->current(), $clean);
        if (bccomp((string)$merged['min_deposit'], (string)$merged['max_deposit'], 8) > 0) {
            return array('ok' => false, 'changed' => array(),
                'error' => 'The minimum deposit cannot be larger than the maximum.');
        }
        if ((int)$merged['marketplace_auto_release_hours'] < 1
            || (int)$merged['marketplace_auto_release_hours'] > 720) {
            return array('ok' => false, 'changed' => array(),
                'error' => 'Marketplace escrow auto-release must be between 1 and 720 hours.');
        }

        foreach ($clean as $key => $value) {
            $before = $this->ci->Setting_model->get($key, $schema[$key][4]);
            if ((string)$before === (string)$value) continue;
            $this->ci->Setting_model->set($key, $value, $schema[$key][1]);
            $changed[$key] = array('before' => $before, 'after' => $value);
        }

        return array('ok' => true, 'error' => null, 'changed' => $changed);
    }

    /** Type coercion and per-type validation, in one place. */
    private function coerce($type, $value, $label) {
        if (strpos($type, 'choice:') === 0) {
            $allowed = explode('|', substr($type, 7));
            $value = strtoupper((string)$value);
            return in_array($value, $allowed, true)
                ? array('value' => $value, 'error' => null)
                : array('value' => null, 'error' => $label.' must be one of: '.implode(', ', $allowed).'.');
        }
        switch ($type) {
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL)
                    ? array('value' => $value, 'error' => null)
                    : array('value' => null, 'error' => $label.' must be a valid email address.');
            case 'int':
                if (!is_numeric($value) || (int)$value != $value || (int)$value < 0) {
                    return array('value' => null, 'error' => $label.' must be a whole number of zero or more.');
                }
                return array('value' => (int)$value, 'error' => null);
            case 'money':
                if (!is_numeric($value) || (float)$value < 0) {
                    return array('value' => null, 'error' => $label.' must be an amount of zero or more.');
                }
                return array('value' => number_format((float)$value, 8, '.', ''), 'error' => null);
            case 'percent':
                if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
                    return array('value' => null, 'error' => $label.' must be between 0 and 100.');
                }
                return array('value' => number_format((float)$value, 4, '.', ''), 'error' => null);
            case 'text':
            default:
                $value = (string)$value;
                if ($value === '') {
                    return array('value' => null, 'error' => $label.' cannot be empty.');
                }
                return array('value' => mb_substr($value, 0, 255), 'error' => null);
        }
    }
}
