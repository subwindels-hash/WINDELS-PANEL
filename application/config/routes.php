<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$route['default_controller'] = 'home';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// Health
$route['health'] = 'health/index';
$route['health/live'] = 'health/live';
$route['health/ready'] = 'health/ready';

// Public
$route['services'] = 'services/index';
$route['services/(:any)'] = 'services/detail/$1';
$route['pricing'] = 'home/pricing';
$route['about'] = 'home/about';
$route['faq'] = 'home/faq';
$route['blog'] = 'home/blog';
$route['blog/(:any)'] = 'home/blog_detail/$1';
$route['contact'] = 'home/contact';
$route['design-system'] = 'home/styleguide';
$route['terms'] = 'home/terms';
$route['privacy'] = 'home/privacy';
$route['refund-policy'] = 'home/refund_policy';
$route['acceptable-use'] = 'home/acceptable_use';
$route['sitemap\.xml'] = 'home/sitemap';
$route['robots\.txt'] = 'home/robots';

// Auth
$route['login'] = 'auth/login';
$route['register'] = 'auth/register';
$route['logout'] = 'auth/logout';
$route['forgot-password'] = 'auth/forgot_password';
$route['reset-password/(:any)'] = 'auth/reset_password/$1';
$route['verify-email/resend'] = 'auth/verify_email_resend';
$route['verify-email/(:any)'] = 'auth/verify_email/$1';
$route['verify-email'] = 'auth/verify_email';
$route['auth/mfa/verify'] = 'auth/mfa_verify';
$route['auth/mfa/setup'] = 'auth/mfa_setup';

// Dashboard (customer)
$route['dashboard'] = 'dashboard/dashboard/index';
$route['dashboard/orders'] = 'dashboard/orders/index';
$route['dashboard/orders/create'] = 'dashboard/orders/create';
$route['dashboard/orders/(:any)/cancel'] = 'dashboard/orders/cancel/$1';
$route['dashboard/orders/(:any)/refill'] = 'dashboard/orders/refill/$1';
$route['dashboard/orders/(:any)'] = 'dashboard/orders/detail/$1';
$route['dashboard/new-order'] = 'dashboard/orders/new_order';
$route['dashboard/mass-order'] = 'dashboard/orders/mass_order';
$route['dashboard/drip-feed'] = 'dashboard/dripfeed/index';
$route['dashboard/drip-feed/create'] = 'dashboard/dripfeed/create';
$route['dashboard/drip-feed/(:any)/pause'] = 'dashboard/dripfeed/pause/$1';
$route['dashboard/drip-feed/(:any)/resume'] = 'dashboard/dripfeed/resume/$1';
$route['dashboard/drip-feed/(:any)/cancel'] = 'dashboard/dripfeed/cancel/$1';
$route['dashboard/drip-feed/(:any)'] = 'dashboard/dripfeed/detail/$1';
$route['dashboard/subscriptions'] = 'dashboard/subscriptions/index';
$route['dashboard/subscriptions/create'] = 'dashboard/subscriptions/create';
$route['dashboard/subscriptions/(:any)/pause'] = 'dashboard/subscriptions/pause/$1';
$route['dashboard/subscriptions/(:any)/resume'] = 'dashboard/subscriptions/resume/$1';
$route['dashboard/subscriptions/(:any)/cancel'] = 'dashboard/subscriptions/cancel/$1';
$route['dashboard/services'] = 'dashboard/services/index';
$route['dashboard/favorites'] = 'dashboard/services/favorites';
$route['dashboard/favorites/add/(:any)'] = 'dashboard/favorites/add/$1';
$route['dashboard/favorites/remove/(:any)'] = 'dashboard/favorites/remove/$1';
$route['dashboard/add-funds'] = 'dashboard/wallet/add_funds';
$route['dashboard/wallet/deposit'] = 'dashboard/wallet/deposit';
$route['dashboard/wallet/deposits'] = 'dashboard/wallet/deposits';
$route['dashboard/wallet/deposits/(:any)'] = 'dashboard/wallet/deposits/$1';
$route['dashboard/transactions'] = 'dashboard/wallet/transactions';
$route['dashboard/tickets'] = 'dashboard/tickets/index';
$route['dashboard/tickets/(:any)'] = 'dashboard/tickets/detail/$1';
$route['dashboard/api'] = 'dashboard/account/api_keys';
$route['dashboard/api/revoke/(:any)'] = 'dashboard/account/revoke_api_key/$1';
$route['dashboard/referrals'] = 'dashboard/referrals/index';
$route['dashboard/notifications'] = 'dashboard/notifications/index';
$route['dashboard/notifications/read'] = 'dashboard/notifications/mark_read';
$route['dashboard/profile'] = 'dashboard/account/profile';
$route['dashboard/security'] = 'dashboard/account/security';

// Admin
$route['admin'] = 'admin/dashboard/index';
$route['admin/orders'] = 'admin/orders/index';
$route['admin/orders/(:any)'] = 'admin/orders/detail/$1';
$route['admin/services'] = 'admin/services/index';
$route['admin/categories'] = 'admin/categories/index';
$route['admin/providers'] = 'admin/providers/index';
$route['admin/providers/create'] = 'admin/providers/create';
$route['admin/providers/(:any)/test'] = 'admin/providers/test/$1';
$route['admin/providers/(:any)/sync'] = 'admin/providers/sync/$1';
$route['admin/providers/(:any)/sync-balance'] = 'admin/providers/sync_balance/$1';
$route['admin/providers/(:any)'] = 'admin/providers/detail/$1';
$route['admin/customers'] = 'admin/users/customers';
$route['admin/customers/(:any)'] = 'admin/users/detail/$1';
$route['admin/wallets'] = 'admin/users/wallets';
$route['admin/payments'] = 'admin/payments/index';
$route['admin/refills'] = 'admin/refills/index';
$route['admin/cancellations'] = 'admin/cancellations/index';
$route['admin/drip-feed'] = 'admin/dripfeed/index';
$route['admin/subscriptions'] = 'admin/subscriptions/index';
$route['admin/tickets'] = 'admin/tickets/index';
$route['admin/affiliates'] = 'admin/affiliates/index';
$route['admin/blog'] = 'admin/blog/index';
$route['admin/faq'] = 'admin/faq/index';
$route['admin/announcements'] = 'admin/announcements/index';
$route['admin/staff'] = 'admin/staff/index';
$route['admin/audit-logs'] = 'admin/audit_logs/index';
$route['admin/appearance'] = 'admin/appearances/index';
$route['admin/appearance/homepage'] = 'admin/appearances/homepage';
$route['admin/settings'] = 'admin/settings/index';
$route['admin/blacklist'] = 'admin/blacklist/index';
$route['admin/media'] = 'admin/media/index';

// API v1 — reseller
$route['api/v1/services'] = 'api_v1/services';
$route['api/v1/services/(:any)'] = 'api_v1/service_detail/$1';
$route['api/v1/orders'] = 'api_v1/orders';
$route['api/v1/orders/status'] = 'api_v1/orders_status';
$route['api/v1/orders/(:any)'] = 'api_v1/order_detail/$1';
$route['api/v1/balance'] = 'api_v1/balance';
$route['api/v1/refills'] = 'api_v1/refills';
$route['api/v1/refills/(:any)'] = 'api_v1/refill_detail/$1';
$route['api/v1/cancellations'] = 'api_v1/cancellations';
$route['api/docs'] = 'api_v1/docs';
$route['api/docs/json'] = 'api_v1/docs_json';

// Webhooks
$route['webhook/(:any)'] = 'webhooks/index/$1';

// Installer (no license step per §81)
$route['install'] = 'install/index';

/*
 * CLI-only controllers — deliberately NOT routed on the web (§66):
 *   php index.php migrate [latest|version <n>|fresh|status]
 *   php index.php seed    [core|demo|all|list]
 *   php index.php cron    <job>
 * They extend Cron_Controller, which hard-fails any non-CLI request.
 */
