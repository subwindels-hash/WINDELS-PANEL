<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_admin = isset($current_user) && in_array($current_user->role, array('SUPER_ADMIN','ADMIN','STAFF'), true);
$nav = $is_admin ? array(
    array('admin',              'Overview',   'reports.view'),
    array('admin/orders',       'Orders',     'orders.view'),
    array('admin/customers',    'Customers',  'users.view'),
    array('admin/services',     'Services',   'services.manage'),
    array('admin/providers',    'Providers',  'providers.manage'),
    array('admin/payments',     'Payments',   'payments.view'),
    array('admin/tickets',      'Tickets',    'tickets.view'),
    array('admin/settings',     'Settings',   'settings.manage'),
) : array(
    array('dashboard',           'Dashboard',  null),
    array('dashboard/new-order', 'New Order',  null),
    array('dashboard/orders',    'My Orders',  null),
    array('dashboard/services',  'Services',   null),
    array('dashboard/add-funds', 'Add Funds',  null),
    array('dashboard/tickets',   'Support',    null),
    array('dashboard/api',       'API',        null),
);
$active = trim($this->uri->uri_string(), '/');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($title ?? 'WINDELS PANEL')?></title>
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<div class="flex min-h-screen">
  <aside class="w-64 shrink-0 border-r bg-white hidden md:flex flex-col">
    <div class="h-16 flex items-center px-6 border-b">
      <a href="<?=site_url()?>" class="font-bold tracking-tight">WINDELS PANEL</a>
    </div>
    <nav class="flex-1 px-3 py-4 space-y-1">
      <?php foreach ($nav as $item): list($href,$label,$perm) = $item; ?>
        <?php if ($perm && !in_array('*', $permissions ?? array(), true) && !in_array($perm, $permissions ?? array(), true)) continue; ?>
        <?php $is_active = $active === $href || strpos($active, $href.'/') === 0; ?>
        <a href="<?=site_url($href)?>"
           class="block rounded-lg px-3 py-2 text-sm <?=$is_active ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100'?>">
          <?=htmlspecialchars($label)?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="border-t p-4 text-sm">
      <div class="font-medium text-slate-800"><?=htmlspecialchars($current_user->username ?? '')?></div>
      <div class="text-xs text-slate-500"><?=htmlspecialchars($current_user->role ?? '')?></div>
      <a href="<?=site_url('logout')?>" class="mt-2 inline-block text-indigo-600 hover:text-indigo-700">Log out</a>
    </div>
  </aside>

  <div class="flex-1 flex flex-col">
    <header class="h-16 border-b bg-white flex items-center justify-between px-6">
      <h1 class="text-lg font-semibold"><?=htmlspecialchars($title ?? '')?></h1>
      <div class="text-sm text-slate-500"><?=htmlspecialchars($current_user->email ?? '')?></div>
    </header>

    <main class="flex-1 p-6">
      <?php $flash_success = $this->session->flashdata('success'); ?>
      <?php $flash_error   = $this->session->flashdata('error'); ?>
      <?php if ($flash_success): ?>
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?=htmlspecialchars($flash_success)?></div>
      <?php endif; ?>
      <?php if ($flash_error): ?>
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?=htmlspecialchars($flash_error)?></div>
      <?php endif; ?>
      <?php $this->load->view($content_view, array_diff_key(get_defined_vars(), array_flip(array('content_view')))); ?>
    </main>
  </div>
</div>
<script src="<?=base_url('assets/js/app.js')?>"></script>
</body>
</html>
