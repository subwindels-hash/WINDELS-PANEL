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
            'mail_transport' => array('choice:mail|smtp|log', 'email', 'How email is sent',
                'mail = PHP mail() (what a cPanel account has working out of the box), smtp = the server '
                .'configured in .env, log = write the message to the log and send nothing (development). '
                .'Use Send test on the Mail queue screen to prove it before relying on it.', 'mail'),
            'mail_from_email' => array('text', 'email', 'From address',
                'The address customers see. Use one on your own domain or providers will reject the mail.', ''),
            'mail_from_name' => array('text', 'email', 'From name',
                'The sender name shown in the inbox. Defaults to the site name.', ''),
            'notification_emails_enabled' => array('bool', 'general', 'Send notification emails',
                'Off keeps the in-app inbox working but stops outbound email for order, deposit and '
                .'support events. Account email (verification, password reset) is never affected.', true),
            'deposit_grace_minutes' => array('int', 'payments', 'Wait for the callback (minutes)',
                'How long a pending deposit is left alone before reconciliation asks the gateway what '
                .'happened to it. Too short wastes API calls on customers still typing their card details.', 20),
            'deposit_expiry_days' => array('int', 'payments', 'Close unpaid deposits after (days)',
                'A deposit still unpaid after this many days is closed — but only when the gateway could '
                .'be reached and confirmed no payment. An unreachable gateway, or a short payment, never '
                .'closes a deposit automatically.', 7),

            'order_auto_submit' => array('bool', 'orders', 'Auto-submit orders',
                'Off holds new orders in PENDING for staff to review and submit manually.', true),
            'partial_refund_enabled' => array('bool', 'orders', 'Auto-refund partial deliveries',
                'On refunds the undelivered share of a partial delivery automatically; off leaves it for staff to refund.', true),
            'refill_window_days' => array('int', 'orders', 'Refill guarantee window (days)',
                'How long after completion a customer may ask for a refill. Providers honour their own '
                .'window and refuse anything older, so asking outside it only produces a refusal the '
                .'customer has to read. 0 means no limit.', 30),
            'refill_abandon_hours' => array('int', 'orders', 'Close unanswered refills after (hours)',
                'A refill the provider never settles is closed as failed after this long and the customer '
                .'is told. Leaving it open shows them a top-up that is never coming and leaves staff a '
                .'queue that can never be cleared.', 168),

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

            // --- Hosted card / wallet gateways ---------------------------
            // One block per gateway: an enable switch, the credentials, and the
            // callback secret that decides whether a webhook can move money.
            // Every value is also readable from the environment (PAYSTACK_
            // SECRET_KEY, STRIPE_SECRET_KEY, …) so containers can inject them;
            // the env value wins over anything typed here.
            'paystack_enabled' => array('bool', 'gateways', 'Accept cards via Paystack',
                'Shows Paystack on Add funds. The gateway refuses to take a payment it cannot confirm, '
                .'so this does nothing until the secret key below is set.', false),
            'paystack_secret_key' => array('secret', 'gateways', 'Paystack secret key',
                'From dashboard.paystack.com → Settings → API Keys (sk_live_… / sk_test_…). Also verifies '
                .'the webhook signature — set your webhook URL to /webhook/paystack.', ''),
            'paystack_public_key' => array('secret', 'gateways', 'Paystack public key',
                'Optional. Only needed if you later add an inline checkout.', ''),

            'flutterwave_enabled' => array('bool', 'gateways', 'Accept cards via Flutterwave',
                'Shows Flutterwave on Add funds. Requires the secret key and the webhook hash below.', false),
            'flutterwave_secret_key' => array('secret', 'gateways', 'Flutterwave secret key',
                'From the Flutterwave dashboard → Settings → API (FLWSECK-…).', ''),
            'flutterwave_secret_hash' => array('secret', 'gateways', 'Flutterwave webhook hash',
                'The "secret hash" you set on the Flutterwave webhook page. Flutterwave sends it back in the '
                .'verif-hash header; without it configured no Flutterwave callback is ever credited.', ''),

            'stripe_enabled' => array('bool', 'gateways', 'Accept cards via Stripe',
                'Shows Stripe Checkout on Add funds. Requires the secret key and the endpoint signing secret.', false),
            'stripe_secret_key' => array('secret', 'gateways', 'Stripe secret key',
                'From dashboard.stripe.com → Developers → API keys (sk_live_… / sk_test_…).', ''),
            'stripe_webhook_secret' => array('secret', 'gateways', 'Stripe endpoint signing secret',
                'The whsec_… value Stripe shows when you add /webhook/stripe as an endpoint. Signatures are '
                .'checked with a 5-minute tolerance, so a captured callback cannot be replayed later.', ''),

            'paypal_enabled' => array('bool', 'gateways', 'Accept PayPal',
                'Shows PayPal on Add funds. Requires the REST app credentials below.', false),
            'paypal_client_id' => array('secret', 'gateways', 'PayPal client ID',
                'From developer.paypal.com → Apps & Credentials → your REST app.', ''),
            'paypal_client_secret' => array('secret', 'gateways', 'PayPal client secret',
                'The secret for the same REST app. Used for OAuth2 and to verify webhooks.', ''),
            'paypal_webhook_id' => array('secret', 'gateways', 'PayPal webhook ID',
                'The ID PayPal assigns the webhook you point at /webhook/paypal. PayPal verifies its own '
                .'callbacks, and without this ID none can be trusted.', ''),
            'paypal_sandbox' => array('bool', 'gateways', 'Use the PayPal sandbox',
                'Routes API calls to api-m.sandbox.paypal.com for testing with sandbox credentials.', false),

            'razorpay_enabled' => array('bool', 'gateways', 'Accept cards via Razorpay',
                'Shows Razorpay on Add funds. Requires the key pair and webhook secret below.', false),
            'razorpay_key_id' => array('secret', 'gateways', 'Razorpay key ID',
                'From dashboard.razorpay.com → Settings → API Keys (rzp_live_… / rzp_test_…).', ''),
            'razorpay_key_secret' => array('secret', 'gateways', 'Razorpay key secret',
                'The secret half of the same key pair.', ''),
            'razorpay_webhook_secret' => array('secret', 'gateways', 'Razorpay webhook secret',
                'Set on the Razorpay webhook page for /webhook/razorpay. This is NOT the key secret.', ''),

            'coinpayments_enabled' => array('bool', 'gateways', 'Accept crypto via CoinPayments',
                'Shows CoinPayments on Add funds. Deposits credit only after the network confirmations '
                .'CoinPayments reports as complete.', false),
            'coinpayments_public_key' => array('secret', 'gateways', 'CoinPayments public key',
                'From coinpayments.net → Account → API Keys.', ''),
            'coinpayments_private_key' => array('secret', 'gateways', 'CoinPayments private key',
                'Signs API calls. Never leaves the server.', ''),
            'coinpayments_merchant_id' => array('secret', 'gateways', 'CoinPayments merchant ID',
                'From Account → Account Settings. Checked on every IPN so another merchant\'s callback '
                .'cannot credit your wallets.', ''),
            'coinpayments_ipn_secret' => array('secret', 'gateways', 'CoinPayments IPN secret',
                'The IPN secret set alongside the IPN URL /webhook/coinpayments. Without it no crypto '
                .'deposit is ever credited.', ''),
            'coinpayments_accept_coin' => array('text', 'gateways', 'Coin customers pay in',
                'Ticker CoinPayments should collect, e.g. BTC, LTCT (testnet), USDT.TRC20.', 'BTC'),

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
            // Shown, never editable: every wallet, order and ledger entry is
            // already denominated in it, so it moves by migration only.
            'base_currency'       => 'Fixed for the ledger. Redenominating is a migration, not a setting.',
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
                $value = trim((string)$value);
                // Allow empty values (leave blank to disable) as documented in the schema.
                if ($value === '') {
                    return array('value' => '', 'error' => null);
                }
                // A non-empty value must be a complete http(s) URL. Anything
                // else is refused rather than "repaired": a webhook stored as
                // `not-a-url` (or as a scheme-less host) never fires, and a
                // silent dead endpoint is far worse than a validation error.
                $host = parse_url($value, PHP_URL_HOST);
                $host_ok = $host !== null && $host !== false && $host !== ''
                    && (strpos($host, '.') !== false || strtolower($host) === 'localhost');
                if (!preg_match('#^https?://#i', $value) || !filter_var($value, FILTER_VALIDATE_URL) || !$host_ok) {
                    return array('value' => null, 'error' => $label.' must be a valid http(s) URL, or left empty to disable it.');
                }
                return array('value' => mb_substr($value, 0, 512), 'error' => null);
            
            case 'int':
                // Validate BEFORE casting: (int)'abc' is 0, so casting first
                // silently accepted any text as the number zero.
                $raw = trim((string)$value);
                if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
                    return array('value' => null, 'error' => $label.' must be a whole number of zero or more.');
                }
                return array('value' => (int)$raw, 'error' => null);
            
            case 'money':
                $raw = trim((string)$value);
                if ($raw === '' || !is_numeric($raw) || (float)$raw < 0) {
                    return array('value' => null, 'error' => $label.' must be an amount of zero or more.');
                }
                return array('value' => number_format((float)$raw, 8, '.', ''), 'error' => null);
            
            case 'percent':
                $raw = trim((string)$value);
                if ($raw === '' || !is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100) {
                    return array('value' => null, 'error' => $label.' must be between 0 and 100.');
                }
                return array('value' => number_format((float)$raw, 4, '.', ''), 'error' => null);
            
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
