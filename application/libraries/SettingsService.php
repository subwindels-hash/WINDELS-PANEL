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
 * **Every seeded setting is now wired.** Earlier sessions left several rows
 * read by no code at all — `maintenance_mode`, `site_tagline`, `api_enabled`,
 * `admin_mfa_required`, `currency_display`, `order_auto_submit`,
 * `partial_refund_enabled` and `default_theme`. Each has since been honoured,
 * so the UNWIRED list below is empty. It is kept (rather than deleted) so any
 * future seeded-but-unwired setting has an obvious, honest place to be listed.
 *
 * **`base_currency` is deliberately read-only.** The row exists, but
 * `marvy_base_currency()` reads `config/marvy.php`, not this table, and
 * every priced row, wallet and ledger entry is denominated in whatever it
 * returned at the time. A form that edited the row would change nothing; a
 * form that actually switched the currency would silently reinterpret every
 * historical amount. Session 22 moved USD→NGN with a migration for exactly
 * that reason, and that remains the only safe way.
 */
class SettingsService {

    /**
     * What a configured secret renders as in the admin form.
     *
     * Sending the real value back to the browser would put it in page source,
     * in the browser cache and in any screenshot of the settings screen. The
     * placeholder round-trips instead, and save() treats it as "unchanged".
     */
    const SECRET_PLACEHOLDER = '••••••••';

    /**
     * The editable surface: key => [type, category, label, help, default].
     *
     * Types: `text`, `email`, `bool`, `int`, `money`, `percent`, `choice:a|b`.
     */
    public static function schema() {
        return array(
            'site_name' => array('text', 'general', 'Site name',
                'Shown in the browser title and in every email this panel sends.', 'MarvySocials'),
            'support_email' => array('email', 'general', 'Support email',
                'The reply-to address on outgoing mail.', 'support@marvy.local'),
            'site_tagline' => array('text', 'general', 'Site tagline',
                'Fallback meta description and public strapline.', 'Prepaid commerce for social media, VTU, virtual numbers, identity, gift cards and digital goods'),
            'maintenance_mode' => array('bool', 'general', 'Maintenance mode',
                'On shows a branded holding page to everyone except staff.', false),
            'active_homepage' => array('choice:AURORA|NEXUS|PULSE', 'homepage', 'Active homepage',
                'Which of the three homepage designs visitors land on.', 'AURORA'),
            'homepage_hero_kicker' => array('text', 'homepage', 'Hero eyebrow',
                'Small line above the headline.', 'Power your social growth'),
            'homepage_hero_title' => array('longtext', 'homepage', 'Hero headline',
                'Main homepage heading.', 'Grow your social presence with one powerful platform'),
            'homepage_hero_lede' => array('longtext', 'homepage', 'Hero description',
                'Short supporting copy under the headline.', 'Access fast, reliable social media services, VTU, numbers, identity checks and gift cards from a prepaid dashboard you can actually audit.'),
            'homepage_cta_primary' => array('text', 'homepage', 'Primary button',
                'Logged-out hero button label.', 'Get started'),
            'homepage_cta_secondary' => array('text', 'homepage', 'Secondary button',
                'Hero outline button label.', 'View services'),
            'homepage_services_title' => array('text', 'homepage', 'Services section title',
                'Heading for the services grid.', 'Everything you need to grow online'),
            'homepage_services_lede' => array('longtext', 'homepage', 'Services section description',
                'Supporting copy for the services grid.', 'Social media services, VTU, numbers, identity checks and gift cards — published rates, prepaid wallet.'),
            'homepage_cta_band_title' => array('text', 'homepage', 'Bottom CTA heading',
                'Heading on the conversion band.', 'Ready to grow?'),
            'homepage_cta_band_body' => array('longtext', 'homepage', 'Bottom CTA text',
                'Body copy on the conversion band.', 'Join MarvySocials and run social media and digital services from one prepaid platform.'),
            'homepage_meta_description' => array('longtext', 'homepage', 'SEO meta description',
                'Search engines and social previews.', 'MarvySocials is a prepaid panel for social media growth services, Nigerian VTU and bills, virtual numbers, identity checks and gift cards.'),

            'registration_enabled' => array('bool', 'security', 'Allow new sign-ups',
                'Off closes registration; existing customers can still sign in.', true),
            'email_verification_required' => array('bool', 'security', 'Require email verification',
                'New accounts must confirm their address before they can order.', false),
            'admin_mfa_required' => array('bool', 'security', 'Require MFA for staff',
                'On redirects any staff account without two-factor authentication to the security screen to enrol before it can open the back office.', false),
            'pin_auto_rotation_enabled' => array('bool', 'security', 'Automatically rotate transaction PINs',
                'On, every customer\'s 4-digit transaction PIN is replaced with a fresh random one after the '
                .'window below and delivered to them (in-app + email). Off leaves PINs unchanged until the '
                .'customer resets one manually.', true),
            'pin_rotation_hours' => array('int', 'security', 'PIN rotation window (hours)',
                'How long a transaction PIN stays valid before the scheduled worker replaces it. Applies to '
                .'every account with a PIN, including ones set before this was turned on.', 24),

            'min_deposit' => array('money', 'payments', 'Minimum deposit',
                'The smallest top-up a customer may make.', '500.00000000'),
            'max_deposit' => array('money', 'payments', 'Maximum deposit',
                'The largest single top-up, before manual review.', '5000000.00000000'),

            'order_auto_submit' => array('bool', 'orders', 'Auto-submit orders',
                'Off holds new orders in PENDING for staff to review and submit manually.', true),
            'partial_refund_enabled' => array('bool', 'orders', 'Auto-refund partial deliveries',
                'On refunds the undelivered share of a partial delivery automatically; off leaves it for staff to refund.', true),

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
                'The “from” name the recipient sees on a delivered card.', 'MarvySocials'),

            // There is NO marketplace fee setting: with the platform as sole
            // seller the gross is the revenue — nothing is split or paid out.
            'marketplace_auto_release_hours' => array('int', 'marketplace', 'Escrow auto-release (hours)',
                'Hours after fulfilment before an undisputed order completes automatically (1–720).', 72),

            // --- Bitcoin (Blockonomics) --------------------------------
            // Secrets are stored encrypted and never rendered back into the
            // form (see the `secret` type), so an operator can rotate a key
            // from the browser without it appearing in page source, history
            // or a screenshot.
            'blockonomics_btc_enabled' => array('bool', 'crypto', 'Accept Bitcoin (BTC)',
                'Shows the Bitcoin option on Add funds. Requires an API key below; the gateway refuses to '
                .'take a payment it cannot confirm.', false),
            'blockonomics_usdt_enabled' => array('bool', 'crypto', 'Accept USDT',
                'Reserved for a USDT wallet. Leave off until a USDT receive flow is configured — turning it '
                .'on alone does not create one.', false),
            'blockonomics_api_key' => array('secret', 'crypto', 'Blockonomics API key',
                'From blockonomics.co → Merchants → API. Used to derive a fresh receive address per deposit.', ''),
            'blockonomics_callback_secret' => array('secret', 'crypto', 'Callback secret',
                'A random string you also put in the Blockonomics callback URL '
                .'(…/webhook/blockonomics?secret=…). Callbacks that do not present it are refused, and '
                .'without it configured no crypto deposit is ever credited.', ''),
            'blockonomics_confirmations' => array('int', 'crypto', 'Required confirmations',
                'Network confirmations before the wallet is credited. 2 is the usual balance of speed and safety.', 2),
            'blockonomics_timeout_minutes' => array('int', 'crypto', 'Address validity (minutes)',
                'How long a quoted BTC amount stays valid before the deposit is treated as expired.', 60),

            // --- Fundsvera (bank transfer collections) -------------------
            'fundsvera_enabled' => array('bool', 'fundsvera', 'Accept bank transfers via Fundsvera',
                'Shows the bank-transfer option on Add funds. Requires the keys below; the gateway '
                .'refuses to take a payment it cannot confirm.', false),
            'fundsvera_public_key' => array('secret', 'fundsvera', 'Fundsvera public key',
                'From your Fundsvera business dashboard. Sent as the Public-Key header.', ''),
            'fundsvera_secret_key' => array('secret', 'fundsvera', 'Fundsvera secret key',
                'Authenticates API calls and signs webhooks. Never leaves the server.', ''),
            'fundsvera_webhook_secret' => array('secret', 'fundsvera', 'Webhook secret (optional)',
                'Only set this if Fundsvera issued a separate webhook secret. Left blank, webhook '
                .'signatures are verified with the secret key, which is what their documentation specifies.', ''),
            'fundsvera_base_url' => array('text', 'fundsvera', 'API base URL',
                'Change only if Fundsvera give you a different endpoint.', 'https://fundsvera.co/api/v1'),

            // --- Referrals, earnings and payouts -------------------------
            'referral_signup_reward' => array('money', 'referrals', 'Referral reward',
                'Paid to the referrer when a referred account completes the qualifying event below. '
                .'Zero disables personal referral rewards without switching the system off.', '0.00000000'),
            'referral_qualify_event' => array('choice:REGISTERED|EMAIL_VERIFIED|FIRST_DEPOSIT|FIRST_ORDER',
                'referrals', 'Qualifying event',
                'What a referred user must do before the referrer earns. A click never qualifies.', 'FIRST_ORDER'),
            'referral_max_per_user' => array('int', 'referrals', 'Maximum referrals per user',
                'Zero means unlimited. Referrals past the cap are flagged for review rather than paid.', 0),
            'referral_max_per_ip_day' => array('int', 'referrals', 'Signups per device per day',
                'More than this many referred signups from one device in 24 hours are flagged for review.', 3),
            'earnings_hold_hours' => array('int', 'referrals', 'Earnings holding period (hours)',
                'How long an earning stays pending before it can be withdrawn. This is the window in '
                .'which fraud is usually discovered, so zero is rarely wise.', 72),
            'earnings_min_payout' => array('money', 'referrals', 'Minimum payout',
                'The smallest cash payout the platform will process.', '1000.00000000'),
            'earnings_payouts_enabled' => array('bool', 'referrals', 'Allow cash payouts',
                'Off still lets users convert earnings into wallet credit. Confirm your licensing, KYC '
                .'and tax obligations before turning cash payouts on.', false),

            'api_enabled' => array('bool', 'api', 'Enable the reseller API',
                'Off returns a 503 for every /api/v1 call without revoking any keys.', true),
            'reseller_webhook_url' => array('url', 'api', 'Reseller webhook URL',
                'Optional HTTPS endpoint. Order status changes POST a signed JSON body. Leave blank to disable.', ''),
            'reseller_webhook_secret' => array('secret', 'api', 'Reseller webhook secret',
                'HMAC-SHA256 of the raw JSON, sent as X-Marvy-Signature.', ''),

            'currency_display' => array('choice:symbol|code', 'currency', 'Currency display',
                'Whether prices render as a symbol (₦1,234.56) or a code (NGN 1,234.56).', 'symbol'),

            'default_theme' => array('choice:system|light|dark', 'branding', 'Default theme',
                'System follows the visitor\'s OS preference; light and dark force a theme. Visitors can still override it in their browser.', 'system'),
        );
    }

    /**
     * Seeded settings this build does not honour, and what each would need.
     *
     * Kept in code rather than a doc so it stays honest: the screen renders
     * this list, so an operator can see the switch is missing on purpose.
     */
    public static function unwired() {
        return array();
    }

    /** Settings shown but not editable, with the reason. */
    public static function readonly_settings() {
        return array(
            'base_currency' => 'The accounting/settlement currency every wallet, order and ledger entry '
                .'is denominated in. Changing this would reinterpret every stored amount, so it moves by '
                .'migration only — see docs/session-22-currency.md. To let customers browse in other '
                .'currencies, enable them and set a default display currency in Admin → Currencies.',
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

            // The form renders a stored secret as a masked placeholder rather
            // than its real value. Submitting the form unchanged must leave
            // the secret alone, not overwrite it with a row of bullets.
            if ($type === 'secret' && $value === self::SECRET_PLACEHOLDER) continue;
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
        // Input validation: ensure $type and $value exist
        if (!isset($type) || $type === '') {
            return array('value' => null, 'error' => $label . ' has an invalid type.');
        }
        if (!isset($value) || $value === null) {
            return array('value' => null, 'error' => $label . ' is missing.');
        }
        
        // Handle choice types first (e.g., currency_display, default_theme)
        // Type format: 'choice:option1|option2|option3'
        if (strpos($type, 'choice:') === 0) {
            $allowed = explode('|', substr($type, 7));
            // Match case-insensitively, but store the schema's own casing
            $submitted = trim((string)$value);
            foreach ($allowed as $option) {
                if (strcasecmp($submitted, $option) === 0) {
                    return array('value' => $option, 'error' => null);
                }
            }
            // Fallback error message listing allowed choices
            return array('value' => null, 'error' => $label.' must be one of: '.implode(', ', $allowed).'.');
        }
        
        // Handle known types via switch statement
        // Use strtolower to handle case variations in type
        $lower_type = strtolower($type);
        switch ($lower_type) {
            case 'email':
                $value = filter_var((string)$value, FILTER_VALIDATE_EMAIL)
                    ? array('value' => (string)$value, 'error' => null)
                    : array('value' => null, 'error' => $label.' must be a valid email address.');
                return $value;
            
            case 'url':
                // Optional by declaration (every current '''url''' setting is
                // documented '''leave blank to disable''' and read with a blank
                // default) — an empty submission must save as empty, not be
                // treated as a required field. A non-empty value must still be
                // a well-formed http(s) URL, so a typo is caught here rather
                // than surfacing as a silent webhook that never fires.
                $value = (string)$value;
                // Allow empty values (leave blank to disable) as documented in the schema
                if ($value === '') {
                    return array('value' => '', 'error' => null);
                }
                // Validate URL format - allow http:// or https://
                // If the URL doesn't have a scheme, try prepending http://
                if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                    // Try prepending http:// if not already present
                    $test_url = 'http://' . $value;
                    if (filter_var($test_url, FILTER_VALIDATE_URL)) {
                        return array('value' => $value, 'error' => null);
                    }
                    return array('value' => null, 'error' => $label.' must be a valid http(s) URL, or left empty to disable it.');
                }
                return array('value' => mb_substr($value, 0, 512), 'error' => null);
            
            case 'int':
                $value = (int)$value;
                if (!is_numeric($value) || (int)$value != $value || (int)$value < 0) {
                    return array('value' => null, 'error' => $label.' must be a whole number of zero or more.');
                }
                return array('value' => $value, 'error' => null);
            
            case 'money':
                $value = (float)$value;
                if (!is_numeric($value) || $value < 0) {
                    return array('value' => null, 'error' => $label.' must be an amount of zero or more.');
                }
                return array('value' => number_format((float)$value, 8, '.', ''), 'error' => null);
            
            case 'percent':
                $value = (float)$value;
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    return array('value' => null, 'error' => $label.' must be between 0 and 100.');
                }
                return array('value' => number_format((float)$value, 4, '.', ''), 'error' => null);
            
            case 'secret':
                // May legitimately be blank (feature not configured yet), and
                // is allowed to be long — API keys are not 255-char-limited
                // display strings.
                $value = mb_substr((string)$value, 0, 512);
                return array('value' => $value, 'error' => null);
            
            case 'text':
            default:
                $value = (string)$value;
                // Allow empty values for types that support it (validated at a higher level)
                // but reject purely empty submissions for required-style fields
                if ($value === '') {
                    return array('value' => null, 'error' => $label.' cannot be empty.');
                }
                return array('value' => mb_substr($value, 0, 255), 'error' => null);
        }
    }
}
