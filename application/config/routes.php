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
$route['blog'] = 'blog/index';
$route['blog/category/(:any)'] = 'blog/index?category=$1';
$route['blog/(:any)'] = 'blog/post/$1';
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
// VTU (§9). Specific segments must precede the (:any) receipt route, or
// 'history' would be read as a transaction public_id.
$route['dashboard/vtu'] = 'dashboard/vtu/index';
$route['dashboard/vtu/history'] = 'dashboard/vtu/history';
$route['dashboard/vtu/verify'] = 'dashboard/vtu/verify';
$route['dashboard/vtu/buy/(:any)'] = 'dashboard/vtu/buy/$1';
$route['dashboard/vtu/products/(:any)/(:any)'] = 'dashboard/vtu/products/$1/$2';
$route['dashboard/vtu/receipt/(:any)'] = 'dashboard/vtu/receipt/$1';
$route['dashboard/vtu/airtime'] = 'dashboard/vtu/airtime';
$route['dashboard/vtu/data'] = 'dashboard/vtu/data';
$route['dashboard/vtu/cable'] = 'dashboard/vtu/cable';
$route['dashboard/vtu/electricity'] = 'dashboard/vtu/electricity';
$route['dashboard/vtu/education'] = 'dashboard/vtu/education';
// Virtual numbers (§10, §11). Same rule as VTU: named segments first, or
// 'history' would be read as a reservation public_id.
$route['dashboard/numbers'] = 'dashboard/numbers/index';
$route['dashboard/numbers/history'] = 'dashboard/numbers/history';
$route['dashboard/numbers/rent'] = 'dashboard/numbers/rent';
$route['dashboard/numbers/(:any)/check'] = 'dashboard/numbers/check/$1';
$route['dashboard/numbers/(:any)/cancel'] = 'dashboard/numbers/cancel/$1';
$route['dashboard/numbers/(:any)/release'] = 'dashboard/numbers/release/$1';
$route['dashboard/numbers/(:any)/report'] = 'dashboard/numbers/report/$1';
$route['dashboard/numbers/(:any)'] = 'dashboard/numbers/detail/$1';
// Identity verification (§22). Named segments before the catch-all detail.
$route['dashboard/identity'] = 'dashboard/identity/index';
$route['dashboard/identity/history'] = 'dashboard/identity/history';
$route['dashboard/identity/verify'] = 'dashboard/identity/verify';
$route['dashboard/identity/(:any)/reveal'] = 'dashboard/identity/reveal/$1';
$route['dashboard/identity/(:any)'] = 'dashboard/identity/detail/$1';

// Gift cards (§23). Same rule again: fixed segments and two-segment actions
// come before the catch-all, or /history routes into detail().
// Unified purchase history (§20) — every domain in one list.
$route['dashboard/history'] = 'dashboard/history/index';

$route['dashboard/giftcards'] = 'dashboard/giftcards/index';
$route['dashboard/giftcards/history'] = 'dashboard/giftcards/history';
$route['dashboard/giftcards/buy'] = 'dashboard/giftcards/buy';
$route['dashboard/giftcards/(:any)/reveal/(:any)'] = 'dashboard/giftcards/reveal/$1/$2';
$route['dashboard/giftcards/(:any)'] = 'dashboard/giftcards/detail/$1';
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
$route['dashboard/tickets/create'] = 'dashboard/tickets/create';
$route['dashboard/tickets/(:any)/reply'] = 'dashboard/tickets/reply/$1';
$route['dashboard/tickets/(:any)/close'] = 'dashboard/tickets/close/$1';
$route['dashboard/tickets/(:any)'] = 'dashboard/tickets/detail/$1';
$route['dashboard/api'] = 'dashboard/account/api_keys';
$route['dashboard/api/revoke/(:any)'] = 'dashboard/account/revoke_api_key/$1';
$route['dashboard/referrals'] = 'dashboard/referrals/index';
$route['dashboard/referrals/commissions'] = 'dashboard/referrals/commissions';
$route['dashboard/notifications'] = 'dashboard/notifications/index';
$route['dashboard/notifications/read'] = 'dashboard/notifications/mark_read';
$route['dashboard/profile'] = 'dashboard/account/profile';
$route['dashboard/security'] = 'dashboard/account/security';

// Admin
$route['admin'] = 'admin/dashboard/index';
$route['admin/orders'] = 'admin/orders/index';
// Action routes must precede the catch-all detail route below.
$route['admin/orders/(:any)/status'] = 'admin/orders/status/$1';
$route['admin/orders/(:any)/cancel'] = 'admin/orders/cancel/$1';
$route['admin/orders/(:any)/refund'] = 'admin/orders/refund/$1';
$route['admin/orders/(:any)'] = 'admin/orders/detail/$1';
$route['admin/vtu'] = 'admin/vtu/index';
// Action routes must precede the catch-all detail route below.
$route['admin/vtu/(:any)/recheck'] = 'admin/vtu/recheck/$1';
$route['admin/vtu/(:any)/refund'] = 'admin/vtu/refund/$1';
$route['admin/vtu/(:any)'] = 'admin/vtu/detail/$1';
$route['admin/numbers'] = 'admin/numbers/index';
// Action routes must precede the catch-all detail route below.
$route['admin/numbers/(:any)/recheck'] = 'admin/numbers/recheck/$1';
$route['admin/numbers/(:any)/release'] = 'admin/numbers/release/$1';
$route['admin/numbers/(:any)/refund'] = 'admin/numbers/refund/$1';
$route['admin/numbers/(:any)'] = 'admin/numbers/detail/$1';
$route['admin/identity'] = 'admin/identity/index';
// Action routes must precede the catch-all detail route below.
$route['admin/identity/(:any)/reveal'] = 'admin/identity/reveal/$1';
$route['admin/identity/(:any)/refund'] = 'admin/identity/refund/$1';
$route['admin/identity/(:any)/purge'] = 'admin/identity/purge/$1';
$route['admin/identity/(:any)'] = 'admin/identity/detail/$1';
$route['admin/analytics'] = 'admin/analytics/index';
$route['admin/giftcards'] = 'admin/giftcards/index';
$route['admin/giftcards/(:any)/collect'] = 'admin/giftcards/collect/$1';
$route['admin/giftcards/(:any)/abandon'] = 'admin/giftcards/abandon/$1';
$route['admin/giftcards/(:any)/refund'] = 'admin/giftcards/refund/$1';
$route['admin/giftcards/(:any)/reveal/(:any)'] = 'admin/giftcards/reveal/$1/$2';
$route['admin/giftcards/(:any)'] = 'admin/giftcards/detail/$1';
// Catalogue: pricing and shelf control for every product domain.
// Action routes must precede the catch-all detail route below.
$route['admin/catalogue'] = 'admin/catalogue/index';
$route['admin/catalogue/(:any)/create'] = 'admin/catalogue/create/$1';
$route['admin/catalogue/(:any)/(:any)/update'] = 'admin/catalogue/update/$1/$2';
$route['admin/catalogue/(:any)/(:any)/status'] = 'admin/catalogue/status/$1/$2';
$route['admin/catalogue/(:any)/(:any)'] = 'admin/catalogue/edit/$1/$2';
$route['admin/catalogue/(:any)'] = 'admin/catalogue/domain/$1';
$route['admin/categories'] = 'admin/categories/index';
$route['admin/providers'] = 'admin/providers/index';
$route['admin/providers/create'] = 'admin/providers/create';
$route['admin/providers/(:any)/test'] = 'admin/providers/test/$1';
$route['admin/providers/(:any)/sync'] = 'admin/providers/sync/$1';
$route['admin/providers/(:any)/sync-balance'] = 'admin/providers/sync_balance/$1';
$route['admin/providers/(:any)'] = 'admin/providers/detail/$1';
$route['admin/customers'] = 'admin/users/customers';
$route['admin/wallets'] = 'admin/users/wallets';
// Action routes must precede the catch-all detail route below.
$route['admin/customers/(:any)/status'] = 'admin/users/status/$1';
$route['admin/customers/(:any)/role'] = 'admin/users/role/$1';
$route['admin/customers/(:any)/price-group'] = 'admin/users/price_group/$1';
$route['admin/customers/(:any)/adjust'] = 'admin/users/adjust/$1';
$route['admin/customers/(:any)'] = 'admin/users/detail/$1';
$route['admin/payments'] = 'admin/payments/index';
$route['admin/payments/(:any)/approve'] = 'admin/payments/approve/$1';
$route['admin/payments/(:any)/reject'] = 'admin/payments/reject/$1';
$route['admin/payments/(:any)'] = 'admin/payments/detail/$1';
$route['admin/refills'] = 'admin/refills/index';
$route['admin/cancellations'] = 'admin/cancellations/index';
$route['admin/drip-feed'] = 'admin/dripfeed/index';
$route['admin/subscriptions'] = 'admin/subscriptions/index';
$route['admin/tickets'] = 'admin/tickets/index';
$route['admin/tickets/(:any)/reply'] = 'admin/tickets/reply/$1';
$route['admin/tickets/(:any)/assign'] = 'admin/tickets/assign/$1';
$route['admin/tickets/(:any)/status'] = 'admin/tickets/status/$1';
$route['admin/tickets/(:any)/priority'] = 'admin/tickets/priority/$1';
$route['admin/tickets/(:any)'] = 'admin/tickets/detail/$1';
$route['admin/affiliates'] = 'admin/affiliates/index';
$route['admin/affiliates/payout'] = 'admin/affiliates/payout';
$route['admin/affiliates/(:num)/rate'] = 'admin/affiliates/rate/$1';
$route['admin/blog'] = 'admin/blog/index';
$route['admin/faq'] = 'admin/faq/index';
$route['admin/announcements'] = 'admin/announcements/index';
$route['admin/staff'] = 'admin/staff/index';
$route['admin/audit-logs'] = 'admin/audit_logs/index';
$route['admin/appearance'] = 'admin/appearances/index';
$route['admin/appearance/homepage'] = 'admin/appearances/homepage';
$route['admin/settings'] = 'admin/settings/index';
$route['admin/settings/save'] = 'admin/settings/save';
$route['admin/blacklist'] = 'admin/blacklist/index';
$route['admin/media'] = 'admin/media/index';

// API v1 — reseller
$route['api/v1/services'] = 'api_v1/services';
$route['api/v1/services/(:any)'] = 'api_v1/service_detail/$1';
$route['api/v1/orders'] = 'api_v1/orders';
$route['api/v1/orders/status'] = 'api_v1/orders_status';
$route['api/v1/orders/(:any)'] = 'api_v1/order_detail/$1';
$route['api/v1/balance'] = 'api_v1/balance';
$route['api/v1/referrals'] = 'api_v1/referrals';
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
