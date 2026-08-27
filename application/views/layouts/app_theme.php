<?php defined('BASEPATH') OR exit('No direct script access allowed');?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($title ?? 'MarvySocials')?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
<link rel="stylesheet" href="<?=base_url('assets/css/design-system.css')?>">
<?php if (!empty($brand['brand_primary_color'])): ?>
<style>
:root{--ws-primary:<?=htmlspecialchars($brand['brand_primary_color'])?>}
</style>
<?php endif; ?>
</head>
<body class="min-h-screen bg-gradient-to-b from-[#0a0a0f] via-[#12121a] to-[#0a0a0f] text-slate-900 antialiased">
<?php $this->load->view('partials/theme/announcement'); ?>
<div class="relative z-10">
  <!-- Sidebar -->
  <aside class="ws-sidebar" style="width: 260px;">
    <?php $this->load->view('partials/theme/sidebar', array(
      'nav_groups' => $nav_groups,
      'active' => $active,
      'permissions' => $permissions ?? array(),
      'brand' => $brand,
      'current_user' => $current_user,
    )); ?>
  </aside>

  <div class="ws-app-main flex flex-col min-h-screen">
    <!-- Header -->
    <header class="border-b border-white/10 bg-[gradient_legacy] from-[#0a0a0f] to-[#12121a] shadow-lg">
      <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <a href="<?=site_url()?>" class="flex items-center gap-2">
            <img src="<?=base_url('assets/images/logo-white.png')?>" alt="WINDELS" class="w-6 h-6">
            <span class="text-xl font-bold tracking-widest text-purple-400">WINDELSOCIALS</span>
          </a>
        </div>
        <div class="hidden sm:flex items-center gap-6">
          <?php foreach(['dashboard','orders','wallet','earnings'] as $route): ?>
            <a href="<?=site_url('dashboard/'.$route)?>"
               class="text-slate-400 hover:text-white transition-colors font-medium"
               style="color: #fff;"><?=ucfirst($route)?></a>
          <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-3">
          <button class="btn btn-ghost btn-sm theme-toggle dark:hidden" aria-label="Toggle light mode">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="5"></circle>
              <line x1="12" y1="1" x2="12" y2="3"></line>
              <line x1="12" y1="21" x2="12" y2="23"></line>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
              <line x1="18.36" y1="18.36" x2="21.78" y2="21.78"></line>
              <line x1="1" y1="12" x2="3" y2="12"></line>
              <line x1="21" y1="12" x2="23" y2="12"></line>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
              <line x1="18.36" y1="5.64" x2="21.78" y2="4.22"></line>
            </svg>
          </button>
          <button class="btn btn-ghost btn-sm hidden sm:block" aria-label="Open menu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="3" y1="6" x2="21" y2="6"></line>
              <line x1="3" y1="12" x2="21" y2="12"></line>
              <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
          </button>
          <a href="<?=site_url('auth/logout')?>"
             class="btn btn-ghost text-sm px-4 py-2 rounded-lg hover:bg-white/10 transition-colors">
            Log out
          </a>
        </div>
      </div>
    </header>

    <main id="main" class="ws-app-main flex-1 px-6 py-4">
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

<!-- Mobile tabbar -->
<nav class="ws-mobile-tabbar" style="background: rgba(10,10,15,0.8); backdrop-blur-sm;">
  <?php
  $mobile = array(
    array('dashboard','dashboard','Home'),
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
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1">
        <?php $this->load->view('partials/icon', array('name'=>$icon,'class'=>'w-5 h-5')); ?>
      </svg>
      <span><?=htmlspecialchars($label)?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>