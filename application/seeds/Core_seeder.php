<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Core seed — required for the app to boot in ANY environment (including production).
 *
 * Seeds: roles, permissions, role_permissions, price groups, currencies,
 * settings, feature flags, payment methods (disabled), email templates, default FAQs.
 * Contains no demo users, no fake orders, no license data.
 */
class Core_seeder extends Seeder {

    public function name() { return 'core'; }

    public function run() {
        $this->seed_roles_permissions();
        $this->seed_price_groups();
        $this->seed_currencies();
        $this->seed_settings();
        $this->seed_feature_flags();
        $this->seed_payment_methods();
        $this->seed_email_templates();
        $this->seed_faqs();
        $this->seed_vtu_catalogue();
        $this->seed_number_catalogue();
        $this->seed_identity_catalogue();
        $this->seed_giftcard_catalogue();
        $this->seed_marketplace_categories();
        $this->out('core seed complete');
    }

    /* ------------------------------------------------------------------ */

    public static function permission_catalog() {
        return array(
            'dashboard'  => array('reports.view'),
            'users'      => array('users.view','users.edit','users.impersonate','staff.manage','pricing.manage'),
            'services'   => array('services.view','services.manage','categories.manage'),
            'providers'  => array('providers.view','providers.manage','providers.sync'),
            'orders'     => array('orders.view','orders.edit','orders.refund','orders.cancel','orders.refill'),
            'vtu'        => array('vtu.view','vtu.manage','vtu.refund'),
            'numbers'    => array('numbers.view','numbers.manage','numbers.refund'),
            // identity.reveal is deliberately separate from identity.view:
            // seeing that a check happened and reading the person's details
            // are different levels of access (§22).
            'identity'   => array('identity.view','identity.manage','identity.refund','identity.reveal'),
            // giftcards.reveal is separate from giftcards.view for a sharper
            // reason than identity's: a gift card code is a bearer instrument,
            // so reading one is closer to opening the till than to reading a
            // record (§23).
            'giftcards'  => array('giftcards.view','giftcards.manage','giftcards.refund','giftcards.reveal'),
            // The platform is the only seller — there are no vendors at all:
            // marketplace.manage covers posting, pricing, featuring,
            // categorising and fulfilling the platform's own listings.
            // Fulfilment reveal and escrow resolution stay separate, sharper
            // grants.
            'marketplace'=> array('marketplace.view','marketplace.manage',
                                  'marketplace.moderate_listings','marketplace.resolve','marketplace.reveal'),
            'payments'   => array('payments.view','payments.manage','wallets.adjust'),
            'support'    => array('tickets.view','tickets.reply','tickets.manage'),
            'content'    => array('blog.manage','faq.manage','announcements.manage','media.manage'),
            'affiliates' => array('affiliates.view','affiliates.manage'),
            'system'     => array('settings.manage','appearance.manage','audit.view','blacklist.manage','api.manage'),
        );
    }

    public static function role_matrix() {
        return array(
            'SUPER_ADMIN' => '*',
            'ADMIN'       => array(
                // users.impersonate is intentionally not a default operational
                // grant. A SUPER_ADMIN may delegate it explicitly in the RBAC
                // matrix after accepting the read-only support policy.
                'reports.view','users.view','users.edit','staff.manage','pricing.manage',
                'services.view','services.manage','categories.manage',
                'providers.view','providers.manage','providers.sync',
                'orders.view','orders.edit','orders.refund','orders.cancel','orders.refill',
                'vtu.view','vtu.manage','vtu.refund',
                'numbers.view','numbers.manage','numbers.refund',
                'identity.view','identity.manage','identity.refund','identity.reveal',
                'giftcards.view','giftcards.manage','giftcards.refund','giftcards.reveal',
                'marketplace.view','marketplace.manage','marketplace.moderate_listings',
                'marketplace.resolve','marketplace.reveal',
                'payments.view','payments.manage','wallets.adjust',
                'tickets.view','tickets.reply','tickets.manage',
                'blog.manage','faq.manage','announcements.manage','media.manage',
                'affiliates.view','affiliates.manage',
                'settings.manage','appearance.manage','audit.view','blacklist.manage','api.manage',
            ),
            'STAFF'       => array(
                'reports.view','users.view','services.view','providers.view',
                'orders.view','orders.edit','orders.refill',
                'vtu.view','numbers.view',
                // Support can see that an identity check ran and how it went,
                // but not open the record. Reading a stranger's date of birth
                // is not needed to answer "did my check work?", and the
                // narrower default is the one worth shipping.
                'identity.view',
                // Support can chase a stuck gift card order and see whether it
                // was delivered, but not read the code. Answering "where is my
                // card?" does not require holding something spendable.
                'giftcards.view','giftcards.manage',
                // Staff may work the Marketplace catalogue queue, but cannot
                // reveal fulfilment or move escrow.
                'marketplace.view','marketplace.moderate_listings',
                'payments.view','tickets.view','tickets.reply','affiliates.view',
            ),
            'CUSTOMER'    => array(),
        );
    }

    private function seed_roles_permissions() {
        $descriptions = array(
            'SUPER_ADMIN' => 'Full unrestricted access (bypasses permission checks)',
            'ADMIN'       => 'Operational administrator',
            'STAFF'       => 'Support and order operations',
            'CUSTOMER'    => 'Panel customer / reseller',
        );
        $role_ids = array();
        foreach ($descriptions as $name => $desc) {
            $role_ids[$name] = $this->upsert('roles', array('name'=>$name), array('description'=>$desc,'is_system'=>1));
        }

        $perm_ids = array();
        foreach (self::permission_catalog() as $category => $keys) {
            foreach ($keys as $key) {
                $perm_ids[$key] = $this->upsert('permissions', array('perm_key'=>$key), array(
                    'category'    => $category,
                    'description' => ucfirst(str_replace(array('.','_'), array(' ', ' '), $key)),
                ));
            }
        }

        foreach (self::role_matrix() as $role => $keys) {
            if ($keys === '*') $keys = array_keys($perm_ids);
            foreach ($keys as $key) {
                if (!isset($perm_ids[$key])) continue;
                $this->insert_once('role_permissions', array(
                    'role_id'       => $role_ids[$role],
                    'permission_id' => $perm_ids[$key],
                ));
            }
        }
    }

    private function seed_price_groups() {
        $groups = array(
            array('Default',  'Standard retail pricing', 1),
            array('Silver',   'Volume tier 1',           0),
            array('Gold',     'Volume tier 2',           0),
            array('Reseller', 'API reseller pricing',    0),
        );
        foreach ($groups as $g) {
            $this->upsert('price_groups', array('name'=>$g[0]), array('description'=>$g[1], 'is_default'=>$g[2]));
        }
    }

    private function seed_currencies() {
        // Rates are "units of this currency per ₦1". NGN is the base, so it is
        // pinned at exactly 1 and every other rate is the reciprocal of its
        // naira price (₦1550 to the dollar => 0.00064516 USD per naira).
        $currencies = array(
            array('NGN','₦','Nigerian Naira',2,'1.00000000',1),
            array('USD','$','US Dollar',2,'0.00064516',0),
            array('EUR','€','Euro',2,'0.00059355',0),
            array('GBP','£','British Pound',2,'0.00050968',0),
            array('INR','₹','Indian Rupee',2,'0.05354839',0),
            array('BRL','R$','Brazilian Real',2,'0.00348387',0),
        );
        foreach ($currencies as $c) {
            $this->upsert('currencies', array('code'=>$c[0]), array(
                'symbol'=>$c[1], 'name'=>$c[2], 'decimal_precision'=>$c[3],
                'exchange_rate'=>$c[4], 'is_base'=>$c[5], 'is_active'=>1,
            ));
        }
    }

    /**
     * VTU networks and products (§9).
     *
     * Prices are placeholders an operator overrides from the admin panel; what
     * matters here is that the shape is right — airtime and electricity are
     * variable-amount rows (price NULL, bounds + discount), everything else is
     * fixed price. Seeded idempotently so re-running never duplicates.
     */
    public static function vtu_catalogue() {
        return array(
            // code, name, service_type, msisdn prefixes
            array('MTN',    'MTN',              'AIRTIME',     '0803,0806,0810,0813,0814,0816,0903,0906,0913,0916'),
            array('GLO',    'Glo',              'AIRTIME',     '0805,0807,0811,0815,0705,0905,0915'),
            array('AIRTEL', 'Airtel',           'AIRTIME',     '0802,0808,0812,0701,0708,0902,0907,0901,0912'),
            array('9MOBILE','9mobile',          'AIRTIME',     '0809,0817,0818,0908,0909'),
            array('MTN-DATA',    'MTN Data',    'DATA',        null),
            array('GLO-DATA',    'Glo Data',    'DATA',        null),
            array('AIRTEL-DATA', 'Airtel Data', 'DATA',        null),
            array('9MOBILE-DATA','9mobile Data','DATA',        null),
            array('DSTV',      'DSTV',              'CABLE',       null),
            array('GOTV',      'GOtv',              'CABLE',       null),
            array('STARTIMES', 'StarTimes',         'CABLE',       null),
            array('IKEDC',     'Ikeja Electric',    'ELECTRICITY', null),
            array('EKEDC',     'Eko Electric',      'ELECTRICITY', null),
            array('AEDC',      'Abuja Electric',    'ELECTRICITY', null),
            array('PHED',      'Port Harcourt Electric', 'ELECTRICITY', null),
            array('WAEC',      'WAEC',              'EXAM_PIN',    null),
            array('NECO',      'NECO',              'EXAM_PIN',    null),
            array('JAMB',      'JAMB',              'EXAM_PIN',    null),
        );
    }

    private function seed_vtu_catalogue() {
        $sort = 0;
        $ids = array();
        foreach (self::vtu_catalogue() as $n) {
            $ids[$n[0]] = $this->upsert('vtu_networks', array('code' => $n[0]), array(
                'public_id'       => windels_public_id(),
                'name'            => $n[1],
                'service_type'    => $n[2],
                'msisdn_prefixes' => $n[3],
                'is_active'       => 1,
                'sorting'         => $sort++,
            ));
        }

        // Variable-amount products: one per airtime network and per disco.
        foreach (self::vtu_catalogue() as $n) {
            if (!in_array($n[2], array('AIRTIME', 'ELECTRICITY'), TRUE)) continue;
            $is_airtime = $n[2] === 'AIRTIME';
            $this->upsert('vtu_products', array(
                'network_id'   => $ids[$n[0]],
                'service_type' => $n[2],
                'code'         => $n[0].'-'.$n[2],
            ), array(
                'public_id'        => windels_public_id(),
                'name'             => $n[1].' '.($is_airtime ? 'Airtime' : 'Units'),
                'discount_percent' => $is_airtime ? '2.0000' : '1.0000',
                'min_amount'       => $is_airtime ? '50.00000000' : '500.00000000',
                'max_amount'       => $is_airtime ? '50000.00000000' : '100000.00000000',
                'is_active'        => 1,
            ));
        }
    }

    /**
     * Virtual-number countries and services (§10, §11).
     *
     * Reference data only. Deliberately no `number_products`: a product needs
     * a price, and the price depends on what a vendor charges, which nobody
     * knows until a vendor is connected and synced. Seeding a priced product
     * would either invent a margin or ship something buyable for nothing —
     * the catalogue sync creates those rows, inactive, for an admin to price.
     */
    public static function number_countries() {
        return array(
            // code, name, dial prefix, flag
            array('NG', 'Nigeria',        '+234', '🇳🇬'),
            array('GH', 'Ghana',          '+233', '🇬🇭'),
            array('KE', 'Kenya',          '+254', '🇰🇪'),
            array('ZA', 'South Africa',   '+27',  '🇿🇦'),
            array('GB', 'United Kingdom', '+44',  '🇬🇧'),
            array('US', 'United States',  '+1',   '🇺🇸'),
            array('IN', 'India',          '+91',  '🇮🇳'),
        );
    }

    public static function number_services() {
        return array(
            array('WHATSAPP',  'WhatsApp'),
            array('TELEGRAM',  'Telegram'),
            array('FACEBOOK',  'Facebook'),
            array('INSTAGRAM', 'Instagram'),
            array('GOOGLE',    'Google'),
            array('TWITTER',   'X (Twitter)'),
            array('TIKTOK',    'TikTok'),
            array('DISCORD',   'Discord'),
            array('UBER',      'Uber'),
            array('AMAZON',    'Amazon'),
            array('OTHER',     'Any other service'),
        );
    }

    private function seed_number_catalogue() {
        $sort = 0;
        foreach (self::number_countries() as $c) {
            $this->upsert('number_countries', array('code' => $c[0]), array(
                'public_id'   => windels_public_id(),
                'name'        => $c[1],
                'dial_prefix' => $c[2],
                'flag_emoji'  => $c[3],
                'is_active'   => 1,
                'sorting'     => $sort++,
            ));
        }

        $sort = 0;
        foreach (self::number_services() as $s) {
            $this->upsert('number_services', array('code' => $s[0]), array(
                'public_id' => windels_public_id(),
                'name'      => $s[1],
                'is_active' => 1,
                'sorting'   => $sort++,
            ));
        }
    }

    /**
     * What identity lookups the panel sells (§22).
     *
     * Prices are left NULL on purpose. Every other reference table in the
     * panel seeds a working price, but a KYC lookup has a real per-query cost
     * that depends on the contract you signed with the vendor, and a guessed
     * default here would either sell below cost or look like a considered
     * number. The products seed inactive-with-no-price; Identity_product_model
     * ::active() hides unpriced rows, so the storefront stays empty until an
     * operator sets a price they have actually agreed. Same rule as a
     * catalogue sync, which also never invents a price.
     */
    public static function identity_products() {
        return array(
            // code, name, id_type, lookup_field, provider_code, description
            array('NIN_BASIC', 'NIN verification', 'NIN', 'IDENTIFIER', 'kyc/nin',
                  'Confirm a National Identification Number and return the registered name, date of birth and gender.'),
            array('BVN_BASIC', 'BVN verification', 'BVN', 'IDENTIFIER', 'kyc/bvn',
                  'Confirm a Bank Verification Number against the NIBSS record.'),
            array('NIN_PHONE', 'NIN by phone number', 'NIN', 'PHONE', 'kyc/nin/phone_number',
                  'Find the NIN record linked to a Nigerian phone number.'),
        );
    }

    private function seed_identity_catalogue() {
        $sort = 0;
        foreach (self::identity_products() as $p) {
            $this->upsert('identity_products', array('code' => $p[0]), array(
                'public_id'     => windels_public_id(),
                'name'          => $p[1],
                'id_type'       => $p[2],
                'lookup_field'  => $p[3],
                'provider_code' => $p[4],
                'description'   => $p[5],
                'is_active'     => 0,
                'sorting'       => $sort++,
            ));
        }
    }

    /**
     * Gift card brands (§23).
     *
     * Brands only. Deliberately no `giftcard_products`, for the same reason
     * there are no seeded `number_products`: a denomination needs a price, and
     * the price depends on the FX rate and the discount a vendor gives you,
     * which nobody knows until a vendor is connected and synced. Seeding a
     * priced $25 Amazon card would either invent a margin or — because the
     * naira/dollar rate moves — ship something sold well below cost.
     *
     * The brands are worth seeding because they are stable, they carry the
     * redeem instructions, and they give the catalogue sync something to
     * attach imported denominations to rather than inventing brand names from
     * vendor product strings.
     */
    public static function giftcard_brands() {
        return array(
            // code, name, redeem instructions
            array('AMAZON', 'Amazon',
                  'Go to amazon.com/redeem and enter the claim code to add it to your Amazon balance.'),
            array('APPLE', 'App Store & iTunes',
                  'Go to apple.com/redeem, or open the App Store, tap your profile and choose Redeem Gift Card.'),
            array('GOOGLE_PLAY', 'Google Play',
                  'Open the Google Play Store, tap your profile, choose Payments & subscriptions, then Redeem code.'),
            array('STEAM', 'Steam',
                  'Open Steam, choose Games then Redeem a Steam Wallet Code, and enter the code.'),
            array('NETFLIX', 'Netflix',
                  'Go to netflix.com/redeem and enter the code to add it to your Netflix account.'),
            array('SPOTIFY', 'Spotify',
                  'Go to spotify.com/redeem and enter the code to add Premium time to your account.'),
            array('XBOX', 'Xbox',
                  'Sign in at redeem.microsoft.com and enter the 25-character code.'),
            array('PLAYSTATION', 'PlayStation Store',
                  'Sign in to PlayStation Store, choose Redeem Codes, and enter the 12-digit code.'),
        );
    }

    private function seed_giftcard_catalogue() {
        $sort = 0;
        foreach (self::giftcard_brands() as $b) {
            $this->upsert('giftcard_brands', array('code' => $b[0]), array(
                'public_id'           => windels_public_id(),
                'name'                => $b[1],
                'redeem_instructions' => $b[2],
                'is_active'           => 1,
                'sorting'             => $sort++,
            ));
        }
    }

    public static function default_settings() {
        return array(
            // general
            array('site_name','WINDELS PANEL','general',1),
            array('site_tagline','Enterprise SMM Reseller Platform','general',1),
            array('support_email','support@windels.local','general',1),
            array('maintenance_mode',FALSE,'general',1),
            // homepage / appearance
            array('active_homepage','AURORA','homepage',1),
            array('brand_primary_color','#6366f1','branding',1),
            array('brand_logo_url',NULL,'branding',1),
            array('brand_favicon_url',NULL,'branding',1),
            array('default_theme','system','branding',1),
            // currency
            array('base_currency','NGN','currency',1),
            array('currency_display','symbol','currency',1),
            // orders
            array('min_deposit','500.00000000','payments',1),
            array('max_deposit','5000000.00000000','payments',1),
            array('order_auto_submit',TRUE,'orders',0),
            array('partial_refund_enabled',TRUE,'orders',0),
            // security
            array('registration_enabled',TRUE,'security',1),
            array('email_verification_required',TRUE,'security',0),
            array('admin_mfa_required',TRUE,'security',0),
            array('api_enabled',TRUE,'security',1),
            // affiliate
            array('referral_commission_percent','5.0000','affiliate',1),
            array('referral_commission_scope','LIFETIME','affiliate',1),
            array('referral_hold_hours',24,'affiliate',0),
            array('referral_min_payout','100.00000000','affiliate',0),
            // identity / KYC (§22). Retention is a setting rather than a
            // constant because the right number is a legal answer, not an
            // engineering one, and it differs by jurisdiction.
            array('identity_retention_days',30,'identity',0),
            // gift cards (§23). The sender name printed on the vendor's
            // receipt: it is what the recipient sees the card came from, so it
            // is a branding decision rather than a constant.
            array('giftcard_sender_name','WINDELS PANEL','giftcards',0),
            // Marketplace policy is snapshotted onto each order at purchase,
            // so later policy edits never rewrite an existing escrow split.
            array('marketplace_auto_release_hours',72,'marketplace',0),
        );
    }

    private function seed_settings() {
        foreach (self::default_settings() as $s) {
            list($key, $value, $category, $public) = $s;
            $this->insert_once('settings', array('setting_key'=>$key), array(
                'setting_value' => json_encode(array('value'=>$value)),
                'category'      => $category,
                'is_public'     => $public,
            ));
        }
    }

    /** Default shelves for the platform storefront; staff manage them in admin. */
    private function seed_marketplace_categories() {
        $defaults = array(
            array('Digital goods',   'DIGITAL_GOODS', 0),
            array('Gaming',          'GAMING',        1),
            array('Accounts',        'ACCOUNTS',      2),
            array('Software & keys', 'SOFTWARE_KEYS', 3),
        );
        foreach ($defaults as $d) {
            list($name, $slug, $sort) = $d;
            $this->insert_once('marketplace_categories', array('slug'=>$slug), array(
                'public_id'  => windels_public_id(),
                'name'       => $name,
                'status'     => 'ACTIVE',
                'sort_order' => $sort,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ));
        }
    }

    private function seed_feature_flags() {
        $flags = array(
            array('demo_mode', FALSE, 'Read-only demo data + blocked mutations (APP_ENV=demo)'),
            array('dripfeed', TRUE, 'Drip-feed orders'),
            array('subscriptions', TRUE, 'Subscription orders'),
            array('mass_order', TRUE, 'Mass order form'),
            array('reseller_api', TRUE, '/api/v1 reseller API'),
            array('affiliate_program', TRUE, 'Referral commissions'),
            array('marketplace', TRUE, 'Moderated customer marketplace and escrow'),
            array('tickets', TRUE, 'Support ticket system'),
            array('blog', TRUE, 'Public blog'),
        );
        foreach ($flags as $f) {
            $this->insert_once('feature_flags', array('flag_key'=>$f[0]), array(
                'enabled'     => $f[1] ? 1 : 0,
                'description' => $f[2],
            ));
        }
    }

    private function seed_payment_methods() {
        // All gateways disabled by default — credentials come from env, never the repo (§81/§62).
        $methods = array(
            array('manual','Manual / Bank Transfer','MANUAL',1,10),
            array('stripe','Stripe','STRIPE',0,20),
            array('paypal','PayPal','PAYPAL',0,30),
            array('paystack','Paystack','PAYSTACK',0,40),
            array('flutterwave','Flutterwave','FLUTTERWAVE',0,50),
            array('razorpay','Razorpay','RAZORPAY',0,60),
            array('coinpayments','CoinPayments','COINPAYMENTS',0,70),
        );
        foreach ($methods as $m) {
            $this->insert_once('payment_methods', array('code'=>$m[0]), array(
                'public_id'  => $this->pid(),
                'name'       => $m[1],
                'type'       => $m[2],
                'is_active'  => $m[3],
                'sorting'    => $m[4],
                'min_amount' => '500.00000000',
                'max_amount' => '5000000.00000000',
                'currencies' => json_encode(array('NGN')),
            ));
        }
    }

    private function seed_email_templates() {
        $templates = array(
            array('auth.verify_email', 'Verify your {{site_name}} account',
                '<p>Hi {{username}},</p><p>Confirm your email to activate your account:</p><p><a href="{{verify_url}}">Verify email</a></p>',
                array('site_name','username','verify_url')),
            array('auth.password_reset', 'Reset your {{site_name}} password',
                '<p>Hi {{username}},</p><p>Use the link below to set a new password. It expires in 60 minutes.</p><p><a href="{{reset_url}}">Reset password</a></p>',
                array('site_name','username','reset_url')),
            array('order.completed', 'Order {{order_id}} completed',
                '<p>Your order <strong>{{order_id}}</strong> for {{service_name}} is complete.</p><p>Quantity: {{quantity}} · Charge: {{charge}}</p>',
                array('order_id','service_name','quantity','charge')),
            array('order.partial', 'Order {{order_id}} partially delivered',
                '<p>Order <strong>{{order_id}}</strong> was partially delivered. {{remains}} units were not delivered and {{refund_amount}} has been refunded to your wallet.</p>',
                array('order_id','remains','refund_amount')),
            array('payment.credited', 'Wallet credited: {{amount}}',
                '<p>We received your payment of {{amount}}. Your new balance is {{balance}}.</p>',
                array('amount','balance')),
            array('ticket.replied', 'Support ticket {{ticket_id}} updated',
                '<p>Our team replied to your ticket <strong>{{subject}}</strong>.</p><p><a href="{{ticket_url}}">View ticket</a></p>',
                array('ticket_id','subject','ticket_url')),
        );
        foreach ($templates as $t) {
            $this->insert_once('email_templates', array('template_key'=>$t[0]), array(
                'subject'   => $t[1],
                'body_html' => $t[2],
                'body_text' => trim(strip_tags(str_replace('</p>', "\n", $t[2]))),
                'variables' => json_encode($t[3]),
                'is_active' => 1,
            ));
        }
    }

    private function seed_faqs() {
        $faqs = array(
            array('How fast are orders delivered?', 'Most services start within minutes. Each service card shows its average start time; drip-feed orders follow the interval you choose.', 'orders', 10),
            array('How do I add funds?', 'Open Dashboard → Add Funds, pick a payment method and follow the checkout. Your wallet is credited automatically once the payment is verified.', 'payments', 20),
            array('What is a partial order?', 'If a provider delivers only part of the quantity, the order is marked PARTIAL and the undelivered portion is refunded to your wallet automatically.', 'orders', 30),
            array('Do you offer an API for resellers?', 'Yes. Create an API key in Dashboard → API and call /api/v1 with the X-Api-Key header. Full docs are at /api/docs.', 'api', 40),
            array('Can I get a refill?', 'Services marked "Refill" support refill requests from the order detail page within the refill window.', 'orders', 50),
        );
        foreach ($faqs as $f) {
            $this->insert_once('faqs', array('question'=>$f[0]), array(
                'answer'=>$f[1], 'category'=>$f[2], 'sorting'=>$f[3], 'is_active'=>1,
            ));
        }
    }
}
