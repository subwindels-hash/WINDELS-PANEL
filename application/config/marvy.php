<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Config is parsed before helpers are autoloaded, so pull in env_bool()/env_str()
// directly. Both are no-ops if the helper is already loaded.
require_once APPPATH.'helpers/marvy_helper.php';

/*
 * MarvySocials — Application config
 * No license keys (§81). Homepage switcher (§60).
 */
$config['marvy'] = array(
    'name' => 'MarvySocials',
    // Public-facing brand used by the marketing site, the shared header/footer
    // and the on-site assistant. Single source of truth for every rendered
    // brand string via marvy_site_name().
    'public_name' => 'MarvySocials',
    'tagline' => 'Grow and manage your social presence',
    'public_tagline' => 'Social media growth services, VTU, virtual numbers, identity checks and gift cards from one prepaid dashboard',
    'support_email' => getenv('MAIL_FROM_ADDRESS') ?: 'support@marvysocials.com',
    'active_homepage' => 'AURORA', // AURORA | NEXUS | PULSE — overridden by settings table
    'homepages' => array('AURORA', 'NEXUS', 'PULSE'),
    'base_currency' => 'NGN',
    'maintenance' => FALSE,
);

/* Rate limit defaults (per-key/per-ip/per-endpoint, Redis-backed) */
$config['rate_limits'] = array(
    'api_global' => array('limit' => 60, 'window' => 60),
    'api_orders' => array('limit' => 30, 'window' => 60),
    // Failed API authentications per IP. Low on purpose: a legitimate reseller
    // fails auth once (a typo), a key guesser fails continuously.
    'api_auth'   => array('limit' => 20, 'window' => 300),
    'login' => array('limit' => 5, 'window' => 300),
    'register' => array('limit' => 3, 'window' => 600),
);

/* Upload constraints (§54) */
$config['upload'] = array(
    'max_size_kb' => 5120,
    'allowed_mimes' => array('image/jpeg','image/png','image/webp','image/gif','application/pdf'),
    'allowed_exts' => array('jpg','jpeg','png','webp','gif','pdf'),
);

/* Provider HTTP defaults (§63) */
$config['provider_http'] = array(
    'timeout' => 15,
    'connect_timeout' => 5,
    'max_retries' => 3,
    'backoff_ms' => array(500, 1500, 4000),
);

/* SSRF guard (SecureHttpClient): allow provider/webhook URLs that resolve to
   private or loopback addresses. Off by default; enable only for self-hosted
   deployments where providers genuinely live on the LAN. */
$config['http_allow_private_hosts'] = env_bool('HTTP_ALLOW_PRIVATE_HOSTS');

/* Gift cards (§23). Which markets the catalogue sync imports — Reloadly lists
   thousands of products across 140 countries, and importing all of them buries
   the handful an operator will actually price. Comma-separated ISO-2 codes. */
$config['giftcard_countries'] = env_str('GIFTCARD_COUNTRIES', 'US,GB,NG');

/* How long a placed gift card order may go without the vendor issuing a code
   before it is written off and refunded. The vendor has already billed us by
   then, so this number is a real cost/patience trade-off, not a timeout. */
$config['giftcard_give_up_minutes'] = 60;

/* Where cron jobs keep their exclusive lock files (see JobRunner). */
$config['cron_lock_dir'] = sys_get_temp_dir().'/marvy-locks';

/* Cron schedules (for crontab.example + Cron controller) */
$config['cron'] = array(
    'dripfeed' => '* * * * *',
    'vtu_status'             => '*/2 * * * *',
    'numbers_status'         => '* * * * *',
    'identity_purge'         => '30 3 * * *',
    'giftcard_codes'         => '*/2 * * * *',
    // Closes purchases no domain worker can settle (no provider reference, or
    // a provider that stopped answering) and returns the money.
    'service_recovery'       => '*/10 * * * *',
    'marketplace_release'    => '*/5 * * * *',
    'order_status' => '*/2 * * * *',
    'subscriptions' => '*/5 * * * *',
    'provider_health' => '*/5 * * * *',
    'refill_status' => '*/5 * * * *',
    'payment_reconciliation' => '*/5 * * * *',
    // Earnings sit PENDING until their holding period elapses; without this
    // sweep they would never become withdrawable.
    'earnings_release'       => '*/10 * * * *',
    'fundsvera_expire'       => '*/5 * * * *',
    'email_queue' => '*/5 * * * *',
    'analytics' => '0 * * * *',
    'provider_sync' => '*/60 * * * *',
    'affiliate_payouts' => '*/10 * * * *',
    // Rotates any transaction PIN older than pin_rotation_hours (24h default).
    // Runs every 15 minutes so a PIN is never overdue by more than that.
    'pin_rotation' => '*/15 * * * *',
);
