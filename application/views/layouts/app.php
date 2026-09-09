<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Navigation, branding and page defaults for the authenticated shell.
require __DIR__.'/_app_context.php';
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
<?php
// Full-access impersonation must NOT grey out forms — the operator chose a
// session that can write. Read-only sessions keep the visual lock below so
// the disabled forms match the boundary enforced server-side.
$__imp_mode = !empty($impersonation['context']['mode'])
    ? (string)$impersonation['context']['mode'] : 'READ_ONLY';
if (!empty($impersonation['active']) && $__imp_mode !== 'FULL_ACCESS'): ?>
<style>
.impersonation-read-only main form[method="post" i] { opacity:.48; pointer-events:none; filter:grayscale(.35); }
</style>
<?php endif; ?>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased ws-app-shell<?=!empty($impersonation['active']) ? ($__imp_mode === 'FULL_ACCESS' ? ' impersonation-full-access' : ' impersonation-read-only') : ''?>">
<?php $this->load->view('partials/announcement'); ?>
<?php $this->load->view('partials/impersonation_banner', array(
  'impersonation' => $impersonation ?? array(),
  'current_user'  => $current_user ?? null,
)); ?>

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
        <?php
          $__page_vars = array_diff_key(get_defined_vars(), array_flip(array('content_view', '__controller_vars', '__page_vars')));
          $this->load->view($content_view, array_merge($__page_vars, $__controller_vars));
        ?>
      </div>
    </main>

    <?php $this->load->view('partials/app_footer'); ?>
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
