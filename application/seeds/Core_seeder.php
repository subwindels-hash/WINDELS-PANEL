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
                'reports.view','users.view','users.edit','staff.manage','pricing.manage',
                'services.view','services.manage','categories.manage',
                'providers.view','providers.manage','providers.sync',
                'orders.view','orders.edit','orders.refund','orders.cancel','orders.refill',
                'payments.view','payments.manage','wallets.adjust',
                'tickets.view','tickets.reply','tickets.manage',
                'blog.manage','faq.manage','announcements.manage','media.manage',
                'affiliates.view','affiliates.manage',
                'settings.manage','appearance.manage','audit.view','blacklist.manage','api.manage',
            ),
            'STAFF'       => array(
                'reports.view','users.view','services.view','providers.view',
                'orders.view','orders.edit','orders.refill',
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
        $currencies = array(
            array('USD','$','US Dollar',2,'1.00000000',1),
            array('EUR','€','Euro',2,'0.92000000',0),
            array('GBP','£','British Pound',2,'0.79000000',0),
            array('NGN','₦','Nigerian Naira',2,'1550.00000000',0),
            array('INR','₹','Indian Rupee',2,'83.00000000',0),
            array('BRL','R$','Brazilian Real',2,'5.40000000',0),
        );
        foreach ($currencies as $c) {
            $this->upsert('currencies', array('code'=>$c[0]), array(
                'symbol'=>$c[1], 'name'=>$c[2], 'decimal_precision'=>$c[3],
                'exchange_rate'=>$c[4], 'is_base'=>$c[5], 'is_active'=>1,
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
            array('base_currency','USD','currency',1),
            array('currency_display','symbol','currency',1),
            // orders
            array('min_deposit','5.00000000','payments',1),
            array('max_deposit','10000.00000000','payments',1),
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
            array('referral_min_payout','0.01000000','affiliate',0),
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

    private function seed_feature_flags() {
        $flags = array(
            array('demo_mode', FALSE, 'Read-only demo data + blocked mutations (APP_ENV=demo)'),
            array('dripfeed', TRUE, 'Drip-feed orders'),
            array('subscriptions', TRUE, 'Subscription orders'),
            array('mass_order', TRUE, 'Mass order form'),
            array('reseller_api', TRUE, '/api/v1 reseller API'),
            array('affiliate_program', TRUE, 'Referral commissions'),
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
                'min_amount' => '5.00000000',
                'max_amount' => '10000.00000000',
                'currencies' => json_encode(array('USD')),
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
