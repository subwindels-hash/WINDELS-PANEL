<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * WINDELS PANEL — Application config
 * No license keys (§81). Homepage switcher (§60).
 */
$config['windels'] = array(
    'name' => 'WINDELS PANEL',
    'tagline' => 'Enterprise SMM Reseller Platform',
    'support_email' => getenv('MAIL_FROM_ADDRESS') ?: 'support@windels.local',
    'active_homepage' => 'AURORA', // AURORA | NEXUS | PULSE — overridden by settings table
    'homepages' => array('AURORA', 'NEXUS', 'PULSE'),
    'base_currency' => 'USD',
    'maintenance' => FALSE,
);

/* Rate limit defaults (per-key/per-ip/per-endpoint, Redis-backed) */
$config['rate_limits'] = array(
    'api_global' => array('limit' => 60, 'window' => 60),
    'api_orders' => array('limit' => 30, 'window' => 60),
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

/* Cron schedules (for crontab.example + Cron controller) */
$config['cron'] = array(
    'dripfeed' => '* * * * *',
    'order_status' => '*/2 * * * *',
    'provider_health' => '*/5 * * * *',
    'refill_status' => '*/5 * * * *',
    'payment_reconciliation' => '*/5 * * * *',
    'email_queue' => '*/5 * * * *',
    'analytics' => '0 * * * *',
    'provider_sync' => '*/60 * * * *',
);
