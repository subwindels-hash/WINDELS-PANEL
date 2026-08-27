<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$route['default_controller'] = 'home';
$route['404_override'] = 'home/not_found';
$route['translate_uri_dashes'] = FALSE;

// First-run setup. Open without a token only while no SUPER_ADMIN exists;
// afterwards VP_SETUP_TOKEN is required or the route 404s.
$route['setup'] = 'setup/index';
$route['setup/admin'] = 'setup/admin';

// Current CSRF token for JavaScript (GET, no side effects). This is what lets
// a reply box, a chat widget or any fetch() post more than once without the
// page being reloaded between messages.
$route['csrf'] = 'csrf/index';

// Embedded site operator (no third-party AI).
$route['assistant'] = 'chat/index';
$route['assistant/chat'] = 'chat/message';
$route['assistant/welcome'] = 'chat/welcome';

// Health
$route['health'] = 'health/index';
$route['health/live'] = 'health/live';
$route['health/ready'] = 'health/ready';

// Public
$route['services'] = 'services/index';
$route['services/(:any)'] = 'services/detail/$1';

// Shop: public storefront, cart and checkout. Reuses the existing
// marketplace catalogue/payment architecture — see Shop.php's class comment.
$route['shop'] = 'shop/index';
$route['shop/gift-cards'] = 'shop/gift_cards';
$route['shop/product/(:any)'] = 'shop/product/$1';
$route['cart'] = 'cart/index';
$route['cart/add'] = 'cart/add';
$route['cart/update'] = 'cart/update';
$route['cart/remove'] = 'cart/remove';
$route['cart/coupon'] = 'cart/coupon';
$route['checkout'] = 'checkout/index';
$route['checkout/place'] = 'checkout/place';
$route['downloads/file'] = 'downloads/file';
$route['dashboard/downloads'] = 'dashboard/downloads/index';
$route['dashboard/downloads/(:any)/link'] = 'dashboard/downloads/link/$1';
$route['pricing'] = 'home/pricing';
$route['about'] = 'home/about';
$route['faq'] = 'home/faq';
$route['blog'] = 'blog/index';
$route['blog/category/(:any)'] = 'blog/index?category=$1';
$route['blog/(:any)'] = 'blog/post/$1';
// Same URL, different verb: the form posts back to /contact so a validation
// error re-renders the page the visitor is already on. Both keys are declared
// together because CI3 verb routing needs an array here — assigning
// $route['contact']['post'] on top of a string value is a fatal in PHP 8.
$route['contact']['get']  = 'home/contact';
$route['contact']['post'] = 'home/contact_submit';
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
// Staff-only sign-in. Must be declared before the /admin dashboard route so
// unauthenticated operators land on a real login form, not the admin gate.
$route['admin/login'] = 'auth/admin_login';
// The only state-changing request allowed inside a read-only impersonation.
$route['impersonation/stop'] = 'impersonation/stop';
$route['forgot-password'] = 'auth/forgot_password';
$route['reset-password/(:any)'] = 'auth/reset_password/$1';
$route['verify-email/resend'] = 'auth/verify_email_resend';
$route['verify-email/(:any)'] = 'auth/verify_email/$1';
$route['verify-email'] = 'auth/verify_email';
$route['auth/mfa/verify'] = 'auth/mfa_verify';
$route['auth/mfa/setup'] = 'auth/mfa_setup';
$route['auth/mfa/confirm'] = 'auth/mfa_confirm';
$route['auth/mfa/disable'] = 'auth/mfa_disable';

// Dashboard (customer)
$route['dashboard'] = 'dashboard/dashboard/index';
$route['dashboard/orders'] = 'dashboard/orders/index';
$route['dashboard/orders/create'] = 'dashboard/orders/create';
$route['dashboard/orders/(:any)/cancel'] = 'dashboard/orders/cancel/$1';
$route['dashboard/orders/(:any)/refill'] = 'dashboard/orders/refill/$1';
$route['dashboard/orders/(:any)'] = 'dashboard/orders/detail/$1';
$route['dashboard/new-order'] = 'dashboard/orders/new_order';
// Named mass-order submission must precede the GET form route.
$route['dashboard/mass-order/create'] = 'dashboard/orders/mass_create';
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

// Marketplace: customers browse and buy only. Selling is staff-side
// (admin/marketplace). Named order actions precede the listing catch-all.
$route['dashboard/marketplace'] = 'dashboard/marketplace/index';
$route['dashboard/marketplace/orders'] = 'dashboard/marketplace/orders';
$route['dashboard/marketplace/orders/(:any)/reveal'] = 'dashboard/marketplace/reveal/$1';
$route['dashboard/marketplace/orders/(:any)/accept'] = 'dashboard/marketplace/accept/$1';
$route['dashboard/marketplace/orders/(:any)/dispute'] = 'dashboard/marketplace/dispute/$1';
$route['dashboard/marketplace/orders/(:any)'] = 'dashboard/marketplace/order/$1';
$route['dashboard/marketplace/(:any)/buy'] = 'dashboard/marketplace/buy/$1';
$route['dashboard/marketplace/(:any)'] = 'dashboard/marketplace/listing/$1';
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
$route['admin/orders/failed'] = 'admin/orders/failed';
$route['admin/orders'] = 'admin/orders/index';
// Action routes must precede the catch-all detail route below.
$route['admin/orders/(:any)/status'] = 'admin/orders/status/$1';
$route['admin/orders/(:any)/cancel'] = 'admin/orders/cancel/$1';
$route['admin/orders/(:any)/refund'] = 'admin/orders/refund/$1';
$route['admin/orders/(:any)/submit'] = 'admin/orders/submit/$1';
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

// Marketplace: the platform is the only seller. Staff post and manage listings
// here, fulfil orders, resolve disputes and moderate; customers can only buy.
// Static/named actions precede the (:any) wildcards.
$route['admin/marketplace'] = 'admin/marketplace/index';
$route['admin/marketplace/categories'] = 'admin/marketplace/categories';
$route['admin/marketplace/categories/save'] = 'admin/marketplace/save_category';
$route['admin/marketplace/categories/(:any)/save'] = 'admin/marketplace/save_category/$1';
$route['admin/marketplace/categories/(:any)/status'] = 'admin/marketplace/category_status/$1';
$route['admin/marketplace/listings/new'] = 'admin/marketplace/listing_form';
$route['admin/marketplace/listings/save'] = 'admin/marketplace/save_listing';
$route['admin/marketplace/listings/bulk'] = 'admin/marketplace/listings_bulk';
$route['admin/marketplace/listings/(:any)/edit'] = 'admin/marketplace/listing_form/$1';
$route['admin/marketplace/listings/(:any)/save'] = 'admin/marketplace/save_listing/$1';
$route['admin/marketplace/listings/(:any)/status'] = 'admin/marketplace/listing_status/$1';
$route['admin/marketplace/listings/(:any)/feature'] = 'admin/marketplace/listing_feature/$1';
$route['admin/marketplace/listings/(:any)/moderate'] = 'admin/marketplace/moderate_listing/$1';
$route['admin/marketplace/listings/(:any)/digital-file'] = 'admin/marketplace/digital_file/$1';
$route['admin/marketplace/listings/(:any)/physical'] = 'admin/marketplace/physical_details/$1';

// Admin Shop hub: downloads, shipments, shipping methods, coupons, reviews.
$route['admin/shop'] = 'admin/shop/index';
$route['admin/shop/downloads'] = 'admin/shop/downloads';
$route['admin/shop/downloads/(:any)/revoke'] = 'admin/shop/revoke_download/$1';
$route['admin/shop/downloads/(:any)/restore'] = 'admin/shop/restore_download/$1';
$route['admin/shop/shipments'] = 'admin/shop/shipments';
$route['admin/shop/shipments/(:any)/status'] = 'admin/shop/update_shipment/$1';
$route['admin/shop/shipments/(:any)/refund'] = 'admin/shop/refund_shipment/$1';
$route['admin/shop/shipments/(:any)'] = 'admin/shop/shipment/$1';
$route['admin/shop/shipping-methods'] = 'admin/shop/shipping_methods';
$route['admin/shop/shipping-methods/save'] = 'admin/shop/save_shipping_method';
$route['admin/shop/shipping-methods/(:any)/status'] = 'admin/shop/shipping_method_status/$1';
$route['admin/shop/coupons'] = 'admin/shop/coupons';
$route['admin/shop/coupons/save'] = 'admin/shop/save_coupon';
$route['admin/shop/coupons/(:any)/status'] = 'admin/shop/coupon_status/$1';
$route['admin/shop/coupons/(:any)/visibility'] = 'admin/shop/coupon_visibility/$1';
$route['admin/shop/reviews'] = 'admin/shop/reviews';
$route['admin/shop/reviews/(:any)/moderate'] = 'admin/shop/moderate_review/$1';
$route['admin/marketplace/orders/(:any)/deliver'] = 'admin/marketplace/deliver/$1';
$route['admin/marketplace/orders/(:any)/reveal'] = 'admin/marketplace/reveal/$1';
$route['admin/marketplace/orders/(:any)/resolve'] = 'admin/marketplace/resolve/$1';
$route['admin/marketplace/orders/(:any)'] = 'admin/marketplace/order/$1';
// Catalogue: pricing and shelf control for every product domain.
// Action routes must precede the catch-all detail route below.
$route['admin/catalogue'] = 'admin/catalogue/index';
$route['admin/catalogue/(:any)/create'] = 'admin/catalogue/create/$1';
$route['admin/catalogue/(:any)/(:any)/update'] = 'admin/catalogue/update/$1/$2';
$route['admin/catalogue/(:any)/(:any)/status'] = 'admin/catalogue/status/$1/$2';
$route['admin/catalogue/(:any)/(:any)'] = 'admin/catalogue/edit/$1/$2';
$route['admin/catalogue/(:any)'] = 'admin/catalogue/domain/$1';
// Customer-facing SMM services. Every named mutation must precede the
// public-id catch-all or "create" would be treated as a service ID.
$route['admin/services'] = 'admin/services/index';
$route['admin/services/create'] = 'admin/services/create';
$route['admin/services/(:any)/update'] = 'admin/services/update/$1';
$route['admin/services/(:any)/archive'] = 'admin/services/archive/$1';
$route['admin/services/(:any)/pricing/group/(:num)'] = 'admin/services/group_rate/$1/$2';
$route['admin/services/(:any)/pricing/user'] = 'admin/services/user_rate/$1';
$route['admin/services/(:any)'] = 'admin/services/edit/$1';
// Reseller API key policy and usage. Writes precede the detail catch-all.
$route['admin/api-keys'] = 'admin/api_keys/index';
$route['admin/api-keys/(:any)/policy'] = 'admin/api_keys/update/$1';
$route['admin/api-keys/(:any)/revoke'] = 'admin/api_keys/revoke/$1';
$route['admin/api-keys/(:any)'] = 'admin/api_keys/show/$1';
// System: categories, blacklist and the (read-only) audit trail.
$route['admin/categories'] = 'admin/system/categories';
$route['admin/categories/save'] = 'admin/system/save_category';
$route['admin/categories/(:any)/save'] = 'admin/system/save_category/$1';
$route['admin/categories/(:any)/delete'] = 'admin/system/delete_category/$1';
$route['admin/providers'] = 'admin/providers/index';
$route['admin/providers/create'] = 'admin/providers/create';
$route['admin/providers/(:any)/test'] = 'admin/providers/test/$1';
$route['admin/providers/(:any)/sync'] = 'admin/providers/sync/$1';
$route['admin/providers/(:any)/sync-balance'] = 'admin/providers/sync_balance/$1';
$route['admin/providers/(:any)'] = 'admin/providers/detail/$1';
$route['admin/customers'] = 'admin/users/customers';
$route['admin/wallets'] = 'admin/users/wallets';
// Action routes must precede the catch-all detail route below.
$route['admin/customers/(:any)/impersonate'] = 'admin/users/impersonate/$1';
$route['admin/customers/(:any)/status'] = 'admin/users/status/$1';
$route['admin/customers/(:any)/role'] = 'admin/users/role/$1';
$route['admin/customers/(:any)/price-group'] = 'admin/users/price_group/$1';
$route['admin/customers/(:any)/adjust'] = 'admin/users/adjust/$1';
// Credential maintenance. All three are resets, never reveals: staff can
// clear a PIN or send a reset link, and can never read either secret.
$route['admin/customers/(:any)/pin-reset'] = 'admin/users/pin_reset/$1';
$route['admin/customers/(:any)/pin-unlock'] = 'admin/users/pin_unlock/$1';
$route['admin/customers/(:any)/password-reset'] = 'admin/users/password_reset/$1';
$route['admin/customers/(:any)/force-logout'] = 'admin/users/force_logout/$1';
$route['admin/customers/(:any)/revoke-keys'] = 'admin/users/revoke_keys/$1';
$route['admin/customers/(:any)'] = 'admin/users/detail/$1';
$route['admin/payments'] = 'admin/payments/index';
$route['admin/payments/webhooks'] = 'admin/payments/webhooks';
$route['admin/payments/webhooks/(:num)/reprocess'] = 'admin/payments/reprocess_webhook/$1';
$route['admin/payments/(:any)/approve'] = 'admin/payments/approve/$1';
$route['admin/payments/(:any)/reject'] = 'admin/payments/reject/$1';
$route['admin/payments/(:any)'] = 'admin/payments/detail/$1';
// Order operations: four queues over the existing engines, one controller.
// Action routes must precede each catch-all.
$route['admin/refills'] = 'admin/operations/refills';
$route['admin/refills/(:any)/request'] = 'admin/operations/refill_request/$1';
$route['admin/cancellations'] = 'admin/operations/cancellations';
$route['admin/cancellations/(:any)/cancel'] = 'admin/operations/cancel/$1';
$route['admin/drip-feed'] = 'admin/operations/dripfeed';
$route['admin/drip-feed/(:any)/(pause|resume|cancel)'] = 'admin/operations/dripfeed_action/$1/$2';
$route['admin/subscriptions'] = 'admin/operations/subscriptions';
$route['admin/subscriptions/(:any)/(pause|resume|cancel)'] = 'admin/operations/subscription_action/$1/$2';
$route['admin/messages'] = 'admin/tickets/messages';
$route['admin/tickets'] = 'admin/tickets/index';
$route['admin/tickets/(:any)/reply'] = 'admin/tickets/reply/$1';
$route['admin/tickets/(:any)/assign'] = 'admin/tickets/assign/$1';
$route['admin/tickets/(:any)/status'] = 'admin/tickets/status/$1';
$route['admin/tickets/(:any)/priority'] = 'admin/tickets/priority/$1';
$route['admin/tickets/(:any)'] = 'admin/tickets/detail/$1';
$route['admin/affiliates'] = 'admin/affiliates/index';
$route['admin/affiliates/payout'] = 'admin/affiliates/payout';
$route['admin/affiliates/(:num)/rate'] = 'admin/affiliates/rate/$1';
// Content: blog, FAQ and announcements share one controller.
// Action routes must precede the catch-all edit route in each block.
$route['admin/blog'] = 'admin/content/domain/blog';
$route['admin/blog/new'] = 'admin/content/create_form/blog';
$route['admin/blog/create'] = 'admin/content/create/blog';
$route['admin/blog/(:any)/update'] = 'admin/content/update/blog/$1';
$route['admin/blog/(:any)/delete'] = 'admin/content/delete/blog/$1';
$route['admin/blog/(:any)'] = 'admin/content/edit/blog/$1';
$route['admin/faq'] = 'admin/content/domain/faq';
$route['admin/faq/new'] = 'admin/content/create_form/faq';
$route['admin/faq/create'] = 'admin/content/create/faq';
$route['admin/faq/(:any)/update'] = 'admin/content/update/faq/$1';
$route['admin/faq/(:any)/status'] = 'admin/content/status/faq/$1';
$route['admin/faq/(:any)/delete'] = 'admin/content/delete/faq/$1';
$route['admin/faq/(:any)'] = 'admin/content/edit/faq/$1';
$route['admin/announcements'] = 'admin/content/domain/announcements';
$route['admin/announcements/new'] = 'admin/content/create_form/announcements';
$route['admin/announcements/create'] = 'admin/content/create/announcements';
$route['admin/announcements/(:any)/update'] = 'admin/content/update/announcements/$1';
$route['admin/announcements/(:any)/status'] = 'admin/content/status/announcements/$1';
$route['admin/announcements/(:any)/delete'] = 'admin/content/delete/announcements/$1';
$route['admin/announcements/(:any)'] = 'admin/content/edit/announcements/$1';
$route['admin/staff'] = 'admin/staff/index';
// Action routes must precede the catch-all below.
$route['admin/staff/permissions'] = 'admin/staff/permissions';
$route['admin/staff/permissions/(:any)'] = 'admin/staff/save_permissions/$1';
$route['admin/audit-logs'] = 'admin/system/audit_logs';
$route['admin/appearance'] = 'admin/media/appearance';
$route['admin/appearance/save'] = 'admin/media/save_appearance';
// Administrator-managed public pages (Terms, Privacy, Refund, About, ...).
// These exist so policy text can change without a code deploy.
$route['admin/pages'] = 'admin/content/pages';
$route['admin/pages/(:any)/reset'] = 'admin/content/page_reset/$1';
$route['admin/pages/(:any)/save'] = 'admin/content/page_save/$1';
$route['admin/pages/(:any)'] = 'admin/content/page_edit/$1';

$route['admin/settings'] = 'admin/settings/index';
$route['admin/settings/save'] = 'admin/settings/save';
$route['admin/settings/flags'] = 'admin/settings/flags';
$route['admin/email-templates'] = 'admin/content/email_templates';
$route['admin/email-templates/(:num)'] = 'admin/content/save_email_template/$1';
$route['admin/administrators'] = 'admin/staff/administrators';
$route['admin/logs'] = 'admin/system/logs';
$route['admin/api-logs'] = 'admin/system/api_logs';
$route['admin/refunds'] = 'admin/orders/refunds';
$route['admin/blacklist'] = 'admin/system/blacklist';
$route['admin/blacklist/(:any)/add'] = 'admin/system/blacklist_add/$1';
$route['admin/blacklist/(:any)/(:num)/remove'] = 'admin/system/blacklist_remove/$1/$2';
$route['admin/media'] = 'admin/media/index';
$route['admin/media/upload'] = 'admin/media/upload';
$route['admin/media/(:any)/delete'] = 'admin/media/delete/$1';

// API v1 — reseller
$route['api/v1/services'] = 'api_v1/services';
$route['api/v1/services/(:any)'] = 'api_v1/service_detail/$1';
$route['api/v1/orders'] = 'api_v1/orders';
// Named bulk actions must precede the public-order-id catch-all.
$route['api/v1/orders/mass'] = 'api_v1/create_mass_order';
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
// Gateway callbacks. POST for signed-body gateways; Blockonomics uses an
// authenticated GET (see Webhooks::GET_CALLBACK_GATEWAYS), so the route must
// not be verb-restricted here — the controller enforces the per-gateway rule.
$route['webhook/(:any)'] = 'webhooks/index/$1';

// Fundsvera's configured callback URL. Same handler as /webhook/fundsvera —
// this is the path the provider profile points at, kept explicit so the URL an
// operator pastes into their Fundsvera dashboard is stable and greppable.
$route['api/payments/webhooks/fundsvera'] = 'webhooks/index/fundsvera';
$route['api/payments/fundsvera/initialize'] = 'payments/initialize';
$route['api/payments/history'] = 'payments/history';
$route['api/payments/(:any)'] = 'payments/show/$1';

// Referral, earnings and withdrawal APIs (session-authenticated; the reseller
// API with its own key auth stays under /api/v1).
$route['api/referrals/my-code'] = 'referral_api/my_code';
$route['api/referrals/validate'] = 'referral_api/validate';
$route['api/referrals/dashboard'] = 'referral_api/dashboard';
$route['api/referrals/history'] = 'referral_api/history';
$route['api/earnings'] = 'referral_api/earnings';
$route['api/earnings/history'] = 'referral_api/earnings_history';
$route['api/withdrawals'] = 'referral_api/withdrawals';
$route['api/withdrawals/history'] = 'referral_api/withdrawals_history';

// Customer earnings dashboard.
$route['dashboard/earnings'] = 'dashboard/earnings/index';
$route['dashboard/earnings/history'] = 'dashboard/earnings/history';
$route['dashboard/earnings/withdraw'] = 'dashboard/earnings/withdraw';
$route['dashboard/earnings/payouts/(:any)/cancel'] = 'dashboard/earnings/cancel_payout/$1';

// Admin: payouts, the earnings ledger and referral review.
$route['admin/payouts'] = 'admin/payouts/index';
$route['admin/payouts/(:any)/approve'] = 'admin/payouts/approve/$1';
$route['admin/payouts/(:any)/reject'] = 'admin/payouts/reject/$1';
$route['admin/payouts/(:any)/paid'] = 'admin/payouts/paid/$1';
$route['admin/payouts/(:any)'] = 'admin/payouts/detail/$1';
$route['admin/currencies'] = 'admin/currencies/index';
$route['admin/currencies/active'] = 'admin/currencies/set_active';
$route['admin/currencies/default'] = 'admin/currencies/set_default';
$route['admin/currencies/rate'] = 'admin/currencies/set_rate';
$route['admin/earnings'] = 'admin/payouts/earnings';
$route['admin/earnings/(:any)/reverse'] = 'admin/payouts/reverse_earning/$1';
$route['admin/referrals'] = 'admin/payouts/referrals';
$route['admin/referrals/(:any)/review'] = 'admin/payouts/review_signup/$1';
$route['admin/campaigns'] = 'admin/payouts/create_campaign';
$route['admin/campaigns/(:any)/status'] = 'admin/payouts/campaign_status/$1';

// No web installer: provisioning is CLI-only (preflight / migrate / seed),
// so /install intentionally falls through to 404.

/*
 * CLI-only controllers — deliberately NOT routed on the web (§66):
 *   php index.php migrate [latest|version <n>|fresh|status]
 *   php index.php seed    [core|demo|all|list]
 *   php index.php cron    <job>
 * They extend Cron_Controller, which hard-fails any non-CLI request.
 */
