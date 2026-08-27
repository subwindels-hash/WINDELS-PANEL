<?php defined('BASEPATH') OR exit('No direct script access allowed');
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
    )),
    array('User management', array(
        array('admin/customers',    'All users',   'users.view',      'users'),
        array('admin/staff',        'Staff',      'staff.manage',    'shield'),
        array('admin/staff/permissions','Roles & permissions','staff.manage','shield'),
        array('admin/affiliates',   'Affiliates', 'affiliates.view', 'gift'),
    )),
    array('Finance', array(
        array('admin/payments',     'Payments',   'payments.view',   'credit-card'),
        array('admin/payouts',      'Payouts',    'payouts.review',  'wallet'),
    )),
    array('Content', array(
        array('admin/pages',        'Website pages','content.pages', 'globe'),
        array('admin/blog',         'Blog',    'blog.manage',     'list'),
        array('admin/media',        'Media',      'media.manage',    'star'),
    )),
    array('Developer', array(
        array('admin/api-keys',     'API / reseller API','api.manage',     'key'),
    )),
    array('Support', array(
        array('admin/tickets',      'Support tickets',    'tickets.view',    'message-square'),
    )),
    array('System', array(
        array('admin/settings',     'Settings',   'settings.manage', 'settings'),
        array('admin/categories',   'Categories & logs', 'audit.view',      'globe'),
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($title ?? 'MarvySocials')?></title>
<?php if (!empty($brand['brand_favicon_url'])): ?>
<link rel="icon" href="<?=htmlspecialchars($brand['brand_favicon_url'])?>">
<?php else: ?>
<?php $this->load->view('partials/icons_meta'); ?>
<?php endif; ?>
<meta name="csrf-name" content="<?=htmlspecialchars($this->security->get_csrf_token_name())?>">
<meta name="csrf-token" content="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
<meta name="csrf-endpoint" content="<?=htmlspecialchars(site_url('csrf'))?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap">
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
<link rel="stylesheet" href="<?=base_url('assets/css/design-system.css')?>">
<?php if (!empty($brand['brand_primary_color'])): ?>
<style><?=':root{--ws-primary:'.htmlspecialchars($brand['brand_primary_color']).'}'?></style>
<?php endif; ?>
<?php if (!empty($impersonation['active'])): ?>
<style>
.impersonation-read-only main form[method="post" i] { opacity:.48; pointer-events:none; filter:grayscale(.35); }
</style>
<?php endif; ?>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased ws-app-shell<?=!empty($impersonation['active']) ? ' impersonation-read-only' : ''?>">
<?php $this->load->view('partials/announcement'); ?>
<?php if (!empty($impersonation['active'])): ?>
<?php
  $__imp_actor = $impersonation['actor'] ?? null;
  $__imp_ctx = $impersonation['context'] ?? array();
  $__imp_minutes = max(0, (int)ceil(((int)($__imp_ctx['expires_at'] ?? time()) - time()) / 60));
?>
<div role="alert" aria-live="assertive" class="ws-impersonation-banner">
  <div class="row justify-between" style="align-items:center;gap:1rem;flex-wrap:wrap;max-width:90rem;margin:0 auto">
    <div>
      <strong style="display:block;letter-spacing:.03em">
        Administrator Mode — You are currently viewing this account as an administrator.
      </strong>
      <span class="text-sm">
        Staff: <?=htmlspecialchars((string)($__imp_actor->username ?? 'staff'))?> ·
        Customer: <?=htmlspecialchars((string)($current_user->username ?? 'customer'))?> ·
        hard expiry in approximately <?=$__imp_minutes?> minute<?=$__imp_minutes === 1 ? '' : 's'?>.
      </span>
    </div>
    <form method="post" action="<?=site_url('impersonation/stop')?>" style="margin:0">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
             value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
      <button class="btn btn-sm" type="submit" style="background:#fff;color:#7f1d1d;border:2px solid #fff;font-weight:700;white-space:nowrap">
        Return to Admin Dashboard
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="ws-app-frame">
  <div class="ws-sidebar-backdrop" data-sidebar-close hidden></div>
  <aside class="ws-sidebar" id="ws-app-sidebar">
    <?php $this->load->view('partials/navigation/sidebar', array(
      'nav_groups' => $nav_groups,
      'active' => $active,
      'permissions' => $permissions ?? array(),
      'brand' => $brand,
      'current_user' => $current_user,
    )); ?>
  </aside>

  <div class="ws-app-main">
    <header class="ws-topbar">
      <div class="ws-topbar-left">
        <button type="button" class="btn btn-ghost btn-sm ws-sidebar-toggle" data-sidebar-toggle aria-controls="ws-app-sidebar" aria-expanded="false">Menu</button>
        <span class="ws-topbar-title"><?=htmlspecialchars($title ?? '')?></span>
      </div>
      <div class="ws-topbar-right">
        <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle
                aria-label="Toggle light or dark theme" title="Toggle theme">
          <span data-theme-toggle-label>Dark</span>
        </button>
        <a href="<?=site_url('dashboard/notifications')?>"
           class="relative p-2 rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Notifications">
          <?php $this->load->view('partials/icon', array('name'=>'bell','class'=>'w-5 h-5')); ?>
          <?php if ($unread > 0): ?>
            <span class="ws-unread"><?=$unread > 99 ? '99+' : $unread?></span>
          <?php endif; ?>
        </a>
        <a href="<?=site_url($is_admin ? 'admin' : 'dashboard')?>" class="hidden sm:inline-flex btn btn-ghost btn-sm">
          <?php $this->load->view('partials/icon', array('name'=>'user','class'=>'w-4 h-4')); ?>
          <?=htmlspecialchars($current_user->username ?? '')?>
        </a>
      </div>
    </header>

    <main id="main" class="ws-app-content ws-page-<?=htmlspecialchars($page_width)?>" tabindex="-1">
      <?php $this->load->view('partials/flash'); ?>
      <?php $this->load->view('partials/page/header', array(
          'title' => $title ?? '',
          'page_description' => $page_description,
          'page_actions' => $page_actions ?? array(),
          'breadcrumbs' => $breadcrumbs,
          'hide_page_header' => $hide_page_header ?? false,
      )); ?>
      <div class="ws-page-body">
        <?php $this->load->view($content_view, array_diff_key(get_defined_vars(), array_flip(array('content_view')))); ?>
      </div>
    </main>
  </div>
</div>

<nav class="ws-mobile-tabbar" aria-label="Mobile">
  <?php
  $mobile = array(
    array('dashboard','dashboard','Home'),
    array('dashboard/new-order','zap','Order'),
    array('dashboard/orders','shopping-bag','Orders'),
    array('dashboard/add-funds','wallet','Funds'),
    array('dashboard/profile','user','Account'),
  );
  if ($is_admin) {
    $mobile = array(
      array('admin','dashboard','Home'),
      array('admin/orders','shopping-bag','Orders'),
      array('admin/customers','users','Users'),
      array('admin/payments','wallet','Pay'),
      array('admin/settings','settings','More'),
    );
  }
  foreach ($mobile as $m): list($href,$icon,$label) = $m; $on = ($active===$href); ?>
    <a href="<?=site_url($href)?>" class="<?=$on?'is-active':''?>">
      <?php $this->load->view('partials/icon', array('name'=>$icon,'class'=>'w-5 h-5')); ?>
      <span><?=htmlspecialchars($label)?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>
