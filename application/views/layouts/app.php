<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_admin = isset($current_user) && in_array($current_user->role, array('SUPER_ADMIN','ADMIN','STAFF'), true);
$unread   = isset($unread) ? (int)$unread : 0;
$active   = isset($nav_active) ? $nav_active : trim($this->uri->uri_string(), '/');
$nav_groups = $is_admin ? array(
    array('Overview', array(
        array('admin',              'Overview',   'reports.view',    'dashboard'),
        array('admin/analytics',    'Analytics',  'reports.view',    'chart'),
    )),
    array('Orders', array(
        array('admin/orders',       'Orders',     'orders.view',     'shopping-bag'),
        array('admin/refills',      'Operations', 'orders.refill',   'repeat'),
        array('admin/vtu',          'VTU',        'vtu.view',        'smartphone'),
        array('admin/numbers',      'Numbers',    'numbers.view',    'hash'),
        array('admin/identity',     'Identity',   'identity.view',   'badge-check'),
        array('admin/giftcards',    'Gift cards', 'giftcards.view',  'gift-card'),
        array('admin/marketplace',  'Marketplace','marketplace.view','shopping-bag'),
    )),
    array('Catalogue', array(
        array('admin/services',     'SMM services','services.view',   'zap'),
        array('admin/catalogue',    'Catalogue',  'services.view',   'package'),
        array('admin/providers',    'Providers',  'providers.view',  'server'),
        array('admin/customers',    'Customers',   'users.view',      'users'),
    )),
    array('Money', array(
        array('admin/payments',     'Payments',   'payments.view',   'credit-card'),
        array('admin/payouts',      'Payouts',    'payouts.review',  'wallet'),
        array('admin/affiliates',   'Affiliates', 'affiliates.view', 'gift'),
    )),
    array('Site', array(
        array('admin/tickets',      'Tickets',    'tickets.view',    'message-square'),
        array('admin/api-keys',     'Reseller API','api.manage',     'key'),
        array('admin/blog',         'Content',    'blog.manage',     'list'),
        array('admin/pages',        'Website pages','content.pages', 'globe'),
        array('admin/media',        'Media',      'media.manage',    'star'),
        array('admin/staff',        'Staff',      'staff.manage',    'shield'),
        array('admin/categories',   'System',     'audit.view',      'globe'),
        array('admin/settings',     'Settings',   'settings.manage', 'settings'),
        array('dashboard/security', 'Account & security', null,      'shield'),
    )),
) : array(
    array('Orders', array(
        array('dashboard',           'Dashboard',  null, 'dashboard'),
        array('dashboard/new-order', 'New Order',  null, 'zap'),
        array('dashboard/mass-order','Mass Order', null, 'list'),
        array('dashboard/orders',    'My Orders',  null, 'shopping-bag'),
        array('dashboard/drip-feed', 'Drip-feed',  null, 'zap'),
        array('dashboard/subscriptions','Subscriptions', null, 'repeat'),
        array('dashboard/history',   'History',    null, 'list'),
    )),
    array('Wallet', array(
        array('dashboard/add-funds', 'Add Funds',  null, 'wallet'),
        array('dashboard/transactions','Transactions', null, 'list'),
    )),
    array('Catalogue', array(
        array('dashboard/services',  'Services',   null, 'package'),
        array('dashboard/favorites', 'Favorites',  null, 'star'),
        array('dashboard/vtu',       'VTU',        null, 'smartphone'),
        array('dashboard/numbers',   'Numbers',    null, 'hash'),
        array('dashboard/identity',  'Identity',   null, 'badge-check'),
        array('dashboard/giftcards', 'Gift cards', null, 'gift-card'),
        array('dashboard/marketplace','Marketplace',null,'shopping-bag'),
    )),
    array('Growth', array(
        array('dashboard/referrals', 'Referrals',  null, 'gift'),
        array('dashboard/earnings',  'Earnings',   null, 'wallet'),
    )),
    array('Account', array(
        array('dashboard/tickets',   'Support',    null, 'message-square'),
        array('dashboard/api',       'API',        null, 'key'),
        array('dashboard/security',  'Security',   null, 'shield'),
    )),
);

// Keep disabled modules out of customer navigation as well as guarding their
// controllers. Read defensively because the installer can render before the
// feature_flags table exists.
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
$nav = array();
foreach ($nav_groups as $group) {
    foreach ($group[1] as $item) $nav[] = $item;
}

// Branding, set from Admin -> Appearance. Read defensively: the layout also
// renders on the CLI-ish paths and during install, before settings exist.
$brand = array('brand_primary_color' => null, 'brand_logo_url' => null, 'brand_favicon_url' => null);
try {
    $CI =& get_instance();
    $CI->load->model('Setting_model');
    foreach (array_keys($brand) as $__k) $brand[$__k] = $CI->Setting_model->get($__k);
} catch (Exception $e) { /* defaults stand */ }
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
<?php // CSRF token for JavaScript: assets/js/app.js reads these and attaches the
      // token to every same-origin fetch/XHR, so a page that posts more than
      // once (reply box, chat widget, retry) never sends a retired token. ?>
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
/* UX guard only; MY_Controller remains the authoritative server-side gate. */
.impersonation-read-only main form[method="post" i] { opacity:.48; pointer-events:none; filter:grayscale(.35); }
</style>
<?php endif; ?>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased<?=!empty($impersonation['active']) ? ' impersonation-read-only' : ''?>">
<?php $this->load->view('partials/announcement'); ?>
<?php if (!empty($impersonation['active'])): ?>
<?php
  $__imp_actor = $impersonation['actor'] ?? null;
  $__imp_ctx = $impersonation['context'] ?? array();
  $__imp_minutes = max(0, (int)ceil(((int)($__imp_ctx['expires_at'] ?? time()) - time()) / 60));
?>
<div role="alert" aria-live="assertive" style="position:sticky;top:var(--ws-announce-h);z-index:1000;background:#7f1d1d;color:#fff;border-bottom:4px solid #fbbf24;padding:.75rem 1rem;box-shadow:0 4px 12px rgba(0,0,0,.3)">
  <div class="row justify-between" style="align-items:center;gap:1rem;flex-wrap:wrap;max-width:90rem;margin:0 auto">
    <div>
      <strong style="display:block;letter-spacing:.03em">
        Administrator Mode — You are currently viewing this account as an administrator.
      </strong>
      <span class="text-sm">
        Staff: <?=htmlspecialchars((string)($__imp_actor->username ?? 'staff'))?> ·
        Customer: <?=htmlspecialchars((string)($current_user->username ?? 'customer'))?> ·
        hard expiry in approximately <?=$__imp_minutes?> minute<?=$__imp_minutes === 1 ? '' : 's'?>.
        Every viewed page is audited; all changes and non-dashboard routes are blocked.
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
<div class="flex min-h-screen">
  <!-- Sidebar (desktop) -->
  <aside class="w-64 shrink-0 border-r bg-surface hidden md:flex flex-col">
    <div class="h-16 flex items-center px-6 border-b">
      <a href="<?=site_url()?>" class="ws-brand">
        <?php if (!empty($brand['brand_logo_url'])): ?>
          <img src="<?=htmlspecialchars($brand['brand_logo_url'])?>" alt="MarvySocials"
               style="max-height:2rem;max-width:10rem">
        <?php else: ?>
          <?php $this->load->view('partials/brand_logo', array('variant'=>'icon','height'=>32,'force_legacy'=>true)); ?>
          <span class="font-bold tracking-tight">MARVYSOCIALS</span>
        <?php endif; ?>
      </a>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Primary">
      <?php foreach ($nav_groups as $group): if (empty($group[1])) continue; ?>
        <div class="ws-nav-group">
          <span class="ws-nav-group-label"><?=htmlspecialchars($group[0])?></span>
          <?php foreach ($group[1] as $item): list($href,$label,$perm) = $item; ?>
            <?php if ($perm && !in_array('*', $permissions ?? array(), true) && !in_array($perm, $permissions ?? array(), true)) continue; ?>
            <?php $is_active = ($active === $href) || ($href !== 'admin' && $href !== 'dashboard' && strpos($active, $href.'/') === 0); ?>
            <a href="<?=site_url($href)?>"
               class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm <?=$is_active ? 'bg-brand-50 text-brand-700 font-medium' : 'text-slate-600 hover:bg-slate-100'?>">
              <?php $this->load->view('partials/icon', array('name'=>$item[3] ?? 'circle', 'class'=>'w-[18px] h-[18px]')); ?>
              <span><?=htmlspecialchars($label)?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="border-t p-4">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 grid place-items-center font-semibold text-sm">
          <?=htmlspecialchars(strtoupper(substr($current_user->username ?? 'U', 0, 1)))?>
        </div>
        <div class="min-w-0 flex-1">
          <div class="font-medium text-slate-800 truncate text-sm"><?=htmlspecialchars($current_user->username ?? '')?></div>
          <div class="text-xs text-slate-500 truncate"><?=htmlspecialchars($current_user->email ?? '')?></div>
        </div>
        <form method="post" action="<?=site_url('logout')?>" class="m-0">
          <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
          <button type="submit" title="Log out" class="text-slate-400 hover:text-slate-700 bg-transparent border-0 p-0 cursor-pointer">
            <?php $this->load->view('partials/icon', array('name'=>'logout','class'=>'w-[18px] h-[18px]')); ?>
          </button>
        </form>
      </div>
    </div>
  </aside>

  <!-- Main column -->
  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 border-b bg-surface flex items-center justify-between px-4 md:px-6 sticky ws-sticky-below-announce z-40">
      <h1 class="text-base md:text-lg font-semibold truncate"><?=htmlspecialchars($title ?? '')?></h1>
      <div class="flex items-center gap-2">
        <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle
                aria-label="Toggle light or dark theme" title="Toggle theme">
          <span data-theme-toggle-label>Dark</span>
        </button>
        <a href="<?=site_url('dashboard/notifications')?>"
           class="relative p-2 rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Notifications">
          <?php $this->load->view('partials/icon', array('name'=>'bell','class'=>'w-5 h-5')); ?>
          <?php if ($unread > 0): ?>
            <span class="absolute top-1 right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold grid place-items-center"><?=$unread > 99 ? '99+' : $unread?></span>
          <?php endif; ?>
        </a>
        <a href="<?=site_url($is_admin ? 'admin' : 'dashboard')?>" class="hidden sm:inline-flex btn btn-ghost btn-sm">
          <?php $this->load->view('partials/icon', array('name'=>'user','class'=>'w-4 h-4')); ?>
          <?=htmlspecialchars($current_user->username ?? '')?>
        </a>
      </div>
    </header>

    <main class="flex-1 p-4 md:p-6 pb-24 md:pb-6">
      <?php $this->load->view('partials/flash'); ?>
      <?php $this->load->view($content_view, array_diff_key(get_defined_vars(), array_flip(array('content_view')))); ?>
    </main>
  </div>
</div>

<!-- Mobile bottom nav -->
<nav class="md:hidden fixed bottom-0 inset-x-0 border-t bg-surface z-50 grid grid-cols-5" aria-label="Mobile">
  <?php
  $mobile = array(
    array('dashboard','dashboard','Dashboard'),
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
      array('admin/payments','wallet','Payments'),
      array('admin/settings','settings','More'),
    );
  }
  foreach ($mobile as $m): list($href,$icon,$label) = $m; $on = ($active===$href); ?>
    <a href="<?=site_url($href)?>" class="flex flex-col items-center justify-center gap-0.5 py-2 text-[11px] <?=$on?'text-brand-600':'text-slate-500'?>">
      <?php $this->load->view('partials/icon', array('name'=>$icon,'class'=>'w-5 h-5')); ?>
      <span><?=htmlspecialchars($label)?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>
