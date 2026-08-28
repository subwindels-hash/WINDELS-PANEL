<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Authenticated shell — MarvySocials theme.
 *
 * Same contract as layouts/app.php (a controller passes `content_view`,
 * `title`, `current_user`, …); only the chrome differs. Everything the shell
 * needs beyond that — the navigation tree, branding, breadcrumbs, unread
 * count and page defaults — is derived by the shared bootstrap, so a
 * controller can switch between the two layouts without passing extra data.
 */
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
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-name" content="<?=htmlspecialchars($this->security->get_csrf_token_name())?>">
<meta name="csrf-token" content="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
<meta name="csrf-endpoint" content="<?=htmlspecialchars(site_url('csrf'))?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
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
<body class="min-h-screen bg-surface text-slate-900 antialiased ws-app-shell<?=!empty($impersonation['active']) ? ' impersonation-read-only' : ''?>">
<a class="ws-skip" href="#main">Skip to content</a>
<?php $this->load->view('partials/announcement'); ?>
<?php $this->load->view('partials/impersonation_banner', array(
  'impersonation' => $impersonation ?? array(),
  'current_user'  => $current_user ?? null,
)); ?>

<div class="ws-app-frame">
  <div class="ws-sidebar-backdrop" data-sidebar-close hidden></div>
  <aside class="ws-sidebar" id="ws-app-sidebar">
    <?php $this->load->view('partials/theme/sidebar', array(
      'nav_groups'   => $nav_groups,
      'active'       => $active,
      'permissions'  => $permissions ?? array(),
      'brand'        => $brand,
      'current_user' => $current_user ?? null,
    )); ?>
  </aside>

  <div class="ws-app-main">
    <?php $this->load->view('partials/theme/dashboard_header', array(
      'is_admin'     => $is_admin,
      'unread'       => $unread,
      'current_user' => $current_user ?? null,
    )); ?>

    <main id="main" class="ws-app-content ws-page-<?=htmlspecialchars($page_width)?>" tabindex="-1">
      <?php $this->load->view('partials/flash'); ?>
      <?php $this->load->view('partials/page/header', array(
          'title'            => $title ?? '',
          'page_description' => $page_description,
          'page_actions'     => $page_actions ?? array(),
          'breadcrumbs'      => $breadcrumbs,
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
  foreach ($mobile as $m): list($href,$icon,$label) = $m; $on = ($active === $href); ?>
    <a href="<?=site_url($href)?>" class="<?=$on ? 'is-active' : ''?>">
      <?php $this->load->view('partials/icon', array('name'=>$icon,'class'=>'w-5 h-5')); ?>
      <span><?=htmlspecialchars($label)?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>
