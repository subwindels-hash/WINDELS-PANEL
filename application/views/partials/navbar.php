<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Shared public navigation. This is the ONLY place the public header nav is
// defined. Individual pages must not render their own header/nav.
$cu = $current_user ?? null;
$is_staff = $cu && in_array($cu->role, array('SUPER_ADMIN','ADMIN','STAFF'), true);
$path = isset($this->uri) ? trim((string)$this->uri->uri_string(), '/') : '';
$site = function_exists('windels_site_name') ? windels_site_name() : 'WINDELS PANEL';
$links = array(
    array('services', 'Services'),
    array('pricing', 'Pricing'),
    array('faq', 'FAQ'),
    array('blog', 'Blog'),
    array('contact', 'Contact'),
    array('about', 'About'),
);
$active = function ($href) use ($path) {
    if ($href === '') return $path === '';
    return $path === $href || strpos($path, $href.'/') === 0;
};
?>
<nav class="ws-public-nav ws-sticky-below-announce" aria-label="Primary">
  <div class="ws-public-nav-inner">
    <a class="ws-brand" href="<?=site_url()?>" aria-label="<?=htmlspecialchars($site)?> home">
      <?php $this->load->view('partials/brand_logo', array('variant'=>'horizontal','height'=>32)); ?>
      <span class="sr-only"><?=htmlspecialchars($site)?></span>
    </a>

    <div class="ws-nav-links" role="list">
      <?php foreach ($links as $item): ?>
        <a href="<?=site_url($item[0])?>" class="nav-link <?=$active($item[0]) ? 'is-active' : ''?>"
           <?=$active($item[0]) ? 'aria-current="page"' : ''?>><?=htmlspecialchars($item[1])?></a>
      <?php endforeach; ?>
    </div>

    <div class="ws-nav-actions">
      <button type="button" class="btn btn-ghost btn-sm ws-nav-desktop" data-theme-toggle
              aria-label="Toggle light or dark theme" title="Toggle theme">
        <span data-theme-toggle-label>Dark</span>
      </button>
      <div class="ws-nav-desktop row" style="gap:.4rem">
        <?php if ($cu): ?>
          <a class="btn btn-secondary btn-sm" href="<?=site_url($is_staff ? 'admin' : 'dashboard')?>">
            <?=$is_staff ? 'Admin' : 'Dashboard'?>
          </a>
          <form method="post" action="<?=site_url('logout')?>" class="m-0">
            <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
            <button type="submit" class="btn btn-ghost btn-sm">Log out</button>
          </form>
        <?php else: ?>
          <a class="btn btn-ghost btn-sm" href="<?=site_url('login')?>">Login</a>
          <a class="btn btn-primary btn-sm" href="<?=site_url('register')?>">Sign up</a>
        <?php endif; ?>
      </div>
      <button type="button" class="ws-nav-toggle" data-nav-toggle aria-controls="ws-nav-panel" aria-expanded="false" aria-label="Open menu">
        <span data-nav-toggle-label>Menu</span>
      </button>
    </div>
  </div>

  <div id="ws-nav-panel" class="ws-nav-panel" hidden>
    <?php foreach ($links as $item): ?>
      <a href="<?=site_url($item[0])?>"><?=htmlspecialchars($item[1])?></a>
    <?php endforeach; ?>
    <?php if ($cu): ?>
      <a href="<?=site_url($is_staff ? 'admin' : 'dashboard')?>"><?=$is_staff ? 'Admin' : 'Dashboard'?></a>
      <form method="post" action="<?=site_url('logout')?>" class="m-0">
        <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
        <button type="submit" class="btn btn-ghost">Log out</button>
      </form>
    <?php else: ?>
      <a href="<?=site_url('login')?>">Login</a>
      <a href="<?=site_url('register')?>">Sign up</a>
    <?php endif; ?>
  </div>
</nav>
