<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Themed top bar for the authenticated shell.
 *
 * Expects: $is_admin, $unread, $current_user. Every link points at a route
 * declared in config/routes.php, and logout is a POST form (the session must
 * never be destroyed by a GET a third party can prime).
 */
$__user = $current_user ?? null;
$__unread = (int)($unread ?? 0);
$__links = ($is_admin ?? false)
  ? array(
      array('admin', 'Dashboard'),
      array('admin/orders', 'Orders'),
      array('admin/customers', 'Users'),
      array('admin/payments', 'Payments'),
    )
  : array(
      array('dashboard', 'Dashboard'),
      array('dashboard/orders', 'Orders'),
      array('dashboard/add-funds', 'Add funds'),
      array('dashboard/earnings', 'Earnings'),
    );
?>
<header class="ws-topbar">
  <div class="ws-topbar-left">
    <button type="button" class="btn btn-ghost btn-sm ws-sidebar-toggle" data-sidebar-toggle
            aria-controls="ws-app-sidebar" aria-expanded="false">Menu</button>
    <a href="<?=site_url()?>" class="ws-brand flex items-center gap-2">
      <?php $this->load->view('partials/brand_logo', array('variant'=>'icon','height'=>26,'force_legacy'=>true)); ?>
      <span class="font-bold tracking-tight ws-brand-word"><?=htmlspecialchars(function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials')?></span>
    </a>
  </div>

  <nav class="hidden sm:flex items-center gap-6" aria-label="Quick links">
    <?php foreach ($__links as $l): ?>
      <a href="<?=site_url($l[0])?>" class="text-sm font-medium hover:text-purple-400 transition-colors"><?=htmlspecialchars($l[1])?></a>
    <?php endforeach; ?>
  </nav>

  <div class="ws-topbar-right">
    <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle
            aria-label="Toggle light or dark theme" title="Toggle theme">
      <span data-theme-toggle-label>Dark</span>
    </button>
    <a href="<?=site_url('dashboard/notifications')?>" class="relative p-2 rounded-lg hover:bg-white/10" aria-label="Notifications">
      <?php $this->load->view('partials/icon', array('name'=>'bell','class'=>'w-5 h-5')); ?>
      <?php if ($__unread > 0): ?>
        <span class="ws-unread"><?=$__unread > 99 ? '99+' : $__unread?></span>
      <?php endif; ?>
    </a>
    <a href="<?=site_url(($is_admin ?? false) ? 'admin' : 'dashboard/profile')?>" class="hidden sm:inline-flex btn btn-ghost btn-sm">
      <?php $this->load->view('partials/icon', array('name'=>'user','class'=>'w-4 h-4')); ?>
      <?=htmlspecialchars($__user->username ?? '')?>
    </a>
    <form method="post" action="<?=site_url('logout')?>" class="m-0">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
             value="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
      <button type="submit" class="btn btn-ghost btn-sm">Log out</button>
    </form>
  </div>
</header>
