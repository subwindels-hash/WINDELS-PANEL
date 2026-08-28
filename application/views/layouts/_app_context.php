<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Shared authenticated-shell bootstrap.
 *
 * Every app layout (layouts/app.php, layouts/app_theme.php) `require`s this
 * file so the navigation tree, branding, breadcrumbs and page defaults are
 * computed in exactly one place. It is a plain PHP include (not a CI view) on
 * purpose: it must populate the *including* layout's variable scope.
 *
 * Expects (all optional): $current_user, $nav_active, $title, $unread,
 * $page_description, $page_actions, $breadcrumbs, $page_width.
 * Defines: $is_admin, $unread, $active, $nav_groups, $brand,
 * $page_description, $page_actions, $breadcrumbs, $page_width.
 */

/**
 * Snapshot of exactly what the controller passed in. The shell computes its
 * own `$active`, `$brand`, `$unread`, … for the chrome, and those names also
 * exist in some content views with a completely different meaning (the
 * virtual-numbers page has `$active` rentals, the gift-card page a `$brand`
 * row). Rendering the content view with the raw layout scope would silently
 * overwrite the controller's data with the chrome's, so layouts re-apply this
 * snapshot last — controller data always wins inside the page body.
 */
$__controller_vars = get_defined_vars();
unset($__controller_vars['content_view']);

$is_admin = isset($current_user) && in_array($current_user->role, array('SUPER_ADMIN','ADMIN','STAFF'), true);
$unread   = isset($unread) ? (int)$unread : 0;
$active   = isset($nav_active) ? $nav_active : trim($this->uri->uri_string(), '/');
$nav_groups = $is_admin ? array(
    array('Overview', array(
        array('admin',              'Dashboard',  'reports.view',    'dashboard'),
        array('admin/analytics',    'Analytics',  'reports.view',    'chart'),
    )),
    array('Order management', array(
        array('admin/orders',       'Orders',     'orders.view',     'shopping-bag'),
        array('admin/orders/failed','Failed orders','orders.view',   'shopping-bag'),
        array('admin/refunds',      'Refunds',    'orders.refund',   'repeat'),
        array('admin/refills',      'Refills / operations', 'orders.refill', 'repeat'),
    )),
    array('Products & services', array(
        array('admin/services',     'SMM services','services.view',   'zap'),
        array('admin/catalogue',    'Service catalogue',  'services.view',   'package'),
        array('admin/providers',    'Providers',  'providers.view',  'server'),
        array('admin/vtu',          'VTU',        'vtu.view',        'smartphone'),
        array('admin/numbers',      'Phone numbers','numbers.view',    'hash'),
        array('admin/identity',     'Identity services','identity.view',   'badge-check'),
        array('admin/giftcards',    'Gift cards', 'giftcards.view',  'gift-card'),
        array('admin/marketplace',  'Marketplace','marketplace.view','shopping-bag'),
        array('admin/shop',         'Shop',       'marketplace.view','shopping-bag'),
    )),
    array('User management', array(
        array('admin/customers',    'All users',   'users.view',      'users'),
        array('admin/administrators','Administrators','staff.manage', 'shield'),
        array('admin/staff',        'Staff',      'staff.manage',    'shield'),
        array('admin/staff/permissions','Roles & permissions','staff.manage','shield'),
        array('admin/affiliates',   'Affiliates', 'affiliates.view', 'gift'),
    )),
    array('Finance', array(
        array('admin/payments',     'Payments',   'payments.view',   'credit-card'),
        array('admin/payments/methods', 'Deposit methods', 'payments.view', 'wallet'),
        array('admin/payouts',      'Payouts',    'payouts.review',  'wallet'),
        array('admin/currencies',   'Currencies', 'settings.manage', 'wallet'),
    )),
    array('Content', array(
        array('admin/pages',        'Website pages','content.pages', 'globe'),
        array('admin/blog',         'Blog',    'blog.manage',     'list'),
        array('admin/email-templates','Email templates','settings.manage','list'),
        array('admin/mail-queue',   'Mail queue', 'settings.manage', 'message-square'),
        array('admin/media',        'Media',      'media.manage',    'star'),
    )),
    array('Developer', array(
        array('admin/api-keys',     'API / reseller API','api.manage',     'key'),
        array('admin/api-logs',     'API logs',   'api.manage',     'list'),
    )),
    array('Support', array(
        array('admin/tickets',      'Support tickets',    'tickets.view',    'message-square'),
        array('admin/messages',     'Customer messages',  'tickets.view',    'message-square'),
    )),
    array('System', array(
        array('admin/settings',     'Settings',   'settings.manage', 'settings'),
        array('admin/settings/flags','Feature flags','settings.manage', 'settings'),
        array('admin/categories',   'Categories & logs', 'audit.view',      'globe'),
        array('admin/logs',         'System logs','audit.view',      'list'),
        array('admin/cron',         'Cron jobs',  'audit.view',      'clock'),
        // Self-service profile lives in the customer shell (/dashboard/*),
        // which staff can reach too — email, names and the avatar are edited
        // there exactly like a customer edits their own. The link just makes
        // the page discoverable from the admin sidebar.
        array('dashboard/profile',  'My profile', null, 'user'),
        array('dashboard/security', 'Security', null,      'shield'),
    )),
) : array(
    array('Main', array(
        array('dashboard',           'Dashboard',  null, 'dashboard'),
    )),
    array('Orders', array(
        array('dashboard/new-order', 'New order',  null, 'zap'),
        array('dashboard/mass-order','Mass order', null, 'list'),
        array('dashboard/orders',    'My orders',  null, 'shopping-bag'),
        array('dashboard/drip-feed', 'Drip feed',  null, 'zap'),
        array('dashboard/subscriptions','Subscriptions', null, 'repeat'),
        array('dashboard/history',   'Order history',    null, 'list'),
    )),
    array('Wallet', array(
        array('dashboard/add-funds', 'Add funds',  null, 'wallet'),
        array('dashboard/transactions','Transactions', null, 'list'),
    )),
    array('Services', array(
        array('dashboard/services',  'SMM services',   null, 'package'),
        array('dashboard/favorites', 'Favorites',  null, 'star'),
        array('dashboard/vtu',       'VTU',        null, 'smartphone'),
        array('dashboard/numbers',   'Phone numbers',    null, 'hash'),
        array('dashboard/identity',  'Identity verification',   null, 'badge-check'),
        array('dashboard/giftcards', 'Gift cards', null, 'gift-card'),
        array('dashboard/marketplace','Marketplace',null,'shopping-bag'),
        array('shop',                 'Shop',       null, 'shopping-bag'),
        array('dashboard/marketplace/orders', 'My Shop Orders', null, 'shopping-bag'),
        array('dashboard/downloads', 'Downloads',  null, 'list'),
    )),
    array('Growth', array(
        array('dashboard/referrals', 'Referrals',  null, 'gift'),
        array('dashboard/earnings',  'Earnings',   null, 'wallet'),
    )),
    array('Developer', array(
        array('dashboard/api',       'API',        null, 'key'),
    )),
    array('Support & account', array(
        array('dashboard/tickets',   'Support tickets',    null, 'message-square'),
        array('dashboard/notifications','Notifications', null, 'bell'),
        array('dashboard/security',  'Security',   null, 'shield'),
        array('dashboard/profile',   'Profile / settings', null, 'user'),
    )),
);

if (!$is_admin) {
    $mass_order_enabled = false;
    try {
        $CI =& get_instance();
        $CI->load->model('Feature_flag_model');
        $mass_order_enabled = $CI->Feature_flag_model->enabled('mass_order');
    } catch (Exception $e) { /* hidden until the feature table is available */ }
    if (!$mass_order_enabled) {
        foreach ($nav_groups as $gi => $group) {
            $nav_groups[$gi][1] = array_values(array_filter($group[1], function ($item) {
                return $item[0] !== 'dashboard/mass-order';
            }));
        }
    }

    // Hide customer-facing nav items for product modules turned off in
    // Admin → Settings → Feature flags. Off never deletes existing data —
    // it only stops new activity and hides the entry point, same contract
    // as mass_order above.
    $hide_routes = array();
    if (!marvy_feature_enabled('dripfeed', true))      $hide_routes[] = 'dashboard/drip-feed';
    if (!marvy_feature_enabled('subscriptions', true)) $hide_routes[] = 'dashboard/subscriptions';
    if (!marvy_feature_enabled('tickets', true))       $hide_routes[] = 'dashboard/tickets';
    if (!marvy_feature_enabled('marketplace', true)) {
        $hide_routes[] = 'dashboard/marketplace';
        $hide_routes[] = 'shop';
        $hide_routes[] = 'dashboard/marketplace/orders';
        $hide_routes[] = 'dashboard/downloads';
    }
    if ($hide_routes) {
        foreach ($nav_groups as $gi => $group) {
            $nav_groups[$gi][1] = array_values(array_filter($group[1], function ($item) use ($hide_routes) {
                return !in_array($item[0], $hide_routes, true);
            }));
        }
    }
}

$brand = array('brand_primary_color' => null, 'brand_logo_url' => null, 'brand_favicon_url' => null);
try {
    $CI =& get_instance();
    $CI->load->model('Setting_model');
    foreach (array_keys($brand) as $__k) $brand[$__k] = $CI->Setting_model->get($__k);
} catch (Exception $e) { /* defaults stand */ }

$default_descriptions = array(
    'dashboard' => 'Monitor your account activity, orders, wallet, and recent activity.',
    'admin' => 'Monitor revenue, queues, provider health, and work that needs attention.',
);
if (empty($page_description)) {
    $page_description = $default_descriptions[$active] ?? 'Manage this area of your MarvySocials account.';
}
if (empty($page_actions) && !$is_admin && ($active === 'dashboard' || $active === '')) {
    $page_actions = array(
        array('label' => 'Add funds', 'href' => site_url('dashboard/add-funds'), 'class' => 'btn btn-secondary'),
        array('label' => 'New order', 'href' => site_url('dashboard/new-order'), 'class' => 'btn btn-primary'),
    );
}
if (empty($breadcrumbs)) {
    $breadcrumbs = array(
        array('label' => $is_admin ? 'Admin' : 'Dashboard', 'href' => site_url($is_admin ? 'admin' : 'dashboard')),
        array('label' => $title ?? 'Page'),
    );
}

$page_width = $page_width ?? 'wide';
