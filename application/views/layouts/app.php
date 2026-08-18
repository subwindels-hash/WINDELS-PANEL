<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_admin = isset($current_user) && in_array($current_user->role, array('SUPER_ADMIN','ADMIN','STAFF'), true);
$unread   = isset($unread) ? (int)$unread : 0;
$active   = isset($nav_active) ? $nav_active : trim($this->uri->uri_string(), '/');
$nav = $is_admin ? array(
    array('admin',              'Overview',   'reports.view',    'dashboard'),
    array('admin/orders',       'Orders',     'orders.view',     'shopping-bag'),
    array('admin/vtu',          'VTU',        'vtu.view',        'smartphone'),
    array('admin/numbers',      'Numbers',    'numbers.view',    'hash'),
    array('admin/identity',     'Identity',   'identity.view',   'badge-check'),
    array('admin/giftcards',    'Gift cards', 'giftcards.view',  'gift-card'),
    array('admin/analytics',    'Analytics',  'reports.view',    'chart'),
    array('admin/customers',    'Customers',  'users.view',      'users'),
    array('admin/catalogue',    'Catalogue',  'services.view',   'package'),
    array('admin/providers',    'Providers',  'providers.manage','server'),
    array('admin/payments',     'Payments',   'payments.view',   'credit-card'),
    array('admin/tickets',      'Tickets',    'tickets.view',    'message-square'),
    array('admin/affiliates',   'Affiliates', 'affiliates.view', 'gift'),
    array('admin/blog',         'Content',    'blog.manage',     'list'),
    array('admin/staff',        'Staff',      'staff.manage',    'shield'),
    array('admin/settings',     'Settings',   'settings.manage', 'settings'),
) : array(
    array('dashboard',           'Dashboard',  null, 'dashboard'),
    array('dashboard/new-order', 'New Order',  null, 'zap'),
    array('dashboard/orders',    'My Orders',  null, 'shopping-bag'),
    array('dashboard/history',   'History',    null, 'list'),
    array('dashboard/vtu',       'VTU',        null, 'smartphone'),
    array('dashboard/numbers',   'Numbers',    null, 'hash'),
    array('dashboard/identity',  'Identity',   null, 'badge-check'),
    array('dashboard/giftcards', 'Gift cards', null, 'gift-card'),
    array('dashboard/services',  'Services',   null, 'package'),
    array('dashboard/favorites', 'Favorites',  null, 'star'),
    array('dashboard/drip-feed', 'Drip-feed',  null, 'zap'),
    array('dashboard/subscriptions','Subscriptions', null, 'repeat'),
    array('dashboard/add-funds', 'Add Funds',  null, 'wallet'),
    array('dashboard/transactions','Transactions', null, 'list'),
    array('dashboard/tickets',   'Support',    null, 'message-square'),
    array('dashboard/referrals', 'Referrals',  null, 'gift'),
    array('dashboard/api',       'API',        null, 'key'),
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($title ?? 'WINDELS PANEL')?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap">
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
<link rel="stylesheet" href="<?=base_url('assets/css/design-system.css')?>">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<div class="flex min-h-screen">
  <!-- Sidebar (desktop) -->
  <aside class="w-64 shrink-0 border-r bg-white hidden md:flex flex-col">
    <div class="h-16 flex items-center px-6 border-b">
      <a href="<?=site_url()?>" class="font-bold tracking-tight">WINDELS PANEL</a>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5" aria-label="Primary">
      <?php foreach ($nav as $item): list($href,$label,$perm) = $item; ?>
        <?php if ($perm && !in_array('*', $permissions ?? array(), true) && !in_array($perm, $permissions ?? array(), true)) continue; ?>
        <?php $is_active = ($active === $href) || (strpos($active, $href.'/') === 0); ?>
        <a href="<?=site_url($href)?>"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm <?=$is_active ? 'bg-brand-50 text-brand-700 font-medium' : 'text-slate-600 hover:bg-slate-100'?>">
          <?php $this->load->view('partials/icon', array('name'=>$item[3] ?? 'circle', 'class'=>'w-[18px] h-[18px]')); ?>
          <span><?=htmlspecialchars($label)?></span>
        </a>
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
        <a href="<?=site_url('logout')?>" title="Log out" class="text-slate-400 hover:text-slate-700">
          <?php $this->load->view('partials/icon', array('name'=>'logout','class'=>'w-[18px] h-[18px]')); ?>
        </a>
      </div>
    </div>
  </aside>

  <!-- Main column -->
  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 border-b bg-white flex items-center justify-between px-4 md:px-6 sticky top-0 z-40">
      <h1 class="text-base md:text-lg font-semibold truncate"><?=htmlspecialchars($title ?? '')?></h1>
      <div class="flex items-center gap-2">
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
      <?php $flash_success = $this->session->flashdata('success'); ?>
      <?php $flash_error   = $this->session->flashdata('error'); ?>
      <?php // A change that went through but that the operator should look at
            // twice — selling below cost, a product hidden behind an inactive
            // parent. Distinct from an error: refusing these would refuse
            // legitimate decisions, and hiding them would let a typo ship.
            $flash_warning = $this->session->flashdata('warning'); ?>
      <?php if ($flash_success): ?>
        <div class="alert alert-success"><?=htmlspecialchars($flash_success)?></div>
      <?php endif; ?>
      <?php if ($flash_warning): ?>
        <div class="alert alert-warning"><?=htmlspecialchars($flash_warning)?></div>
      <?php endif; ?>
      <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?=htmlspecialchars($flash_error)?></div>
      <?php endif; ?>
      <?php $this->load->view($content_view, array_diff_key(get_defined_vars(), array_flip(array('content_view')))); ?>
    </main>
  </div>
</div>

<!-- Mobile bottom nav -->
<nav class="md:hidden fixed bottom-0 inset-x-0 border-t bg-white z-50 grid grid-cols-5" aria-label="Mobile">
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

<script src="<?=base_url('assets/js/app.js')?>"></script>
</body>
</html>
