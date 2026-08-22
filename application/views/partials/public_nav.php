<?php defined('BASEPATH') OR exit('No direct script access allowed');
$cu = $current_user ?? null;
$is_staff = $cu && in_array($cu->role, array('SUPER_ADMIN','ADMIN','STAFF'), true);
$path = isset($this->uri) ? trim((string)$this->uri->uri_string(), '/') : '';
$links = array(
    array('services', 'Services'),
    array('pricing', 'Pricing'),
    array('faq', 'FAQ'),
    array('blog', 'Blog'),
    array('contact', 'Contact'),
);
$active = function ($href) use ($path) {
    if ($href === '') return $path === '';
    return $path === $href || strpos($path, $href.'/') === 0;
};
?>
<a class="ws-skip" href="#main">Skip to content</a>
<nav class="ws-public-nav ws-sticky-below-announce" aria-label="Primary">
  <div class="ws-public-nav-inner">
    <a class="ws-brand" href="<?=site_url()?>">
      <?php $this->load->view('partials/brand_logo', array('variant'=>'horizontal','height'=>32)); ?>
      <span class="sr-only">WINDELS PANEL</span>
    </a>

    <div class="ws-nav-links" role="list">
      <?php foreach ($links as $item): ?>
        <a href="<?=site_url($item[0])?>" class="<?=$active($item[0]) ? 'is-active' : ''?>"
           <?=$active($item[0]) ? 'aria-current="page"' : ''?>><?=htmlspecialchars($item[1])?></a>
      <?php endforeach; ?>
    </div>

    <div class="ws-nav-actions">
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
          <a class="btn btn-ghost btn-sm" href="<?=site_url('login')?>">Log in</a>
          <a class="btn btn-primary btn-sm" href="<?=site_url('register')?>">Create account</a>
        <?php endif; ?>
      </div>
      <button type="button" class="ws-nav-toggle" data-nav-toggle aria-controls="ws-nav-panel" aria-expanded="false">Menu</button>
    </div>
  </div>

  <div id="ws-nav-panel" class="ws-nav-panel" hidden>
    <?php foreach ($links as $item): ?>
      <a href="<?=site_url($item[0])?>"><?=htmlspecialchars($item[1])?></a>
    <?php endforeach; ?>
    <a href="<?=site_url('about')?>">About</a>
    <?php if ($cu): ?>
      <a href="<?=site_url($is_staff ? 'admin' : 'dashboard')?>"><?=$is_staff ? 'Admin' : 'Dashboard'?></a>
      <form method="post" action="<?=site_url('logout')?>" class="m-0">
        <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
        <button type="submit" class="btn btn-ghost">Log out</button>
      </form>
    <?php else: ?>
      <a href="<?=site_url('login')?>">Log in</a>
      <a href="<?=site_url('register')?>">Create account</a>
    <?php endif; ?>
  </div>
</nav>
