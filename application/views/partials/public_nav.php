<?php defined('BASEPATH') OR exit('No direct script access allowed');
$cu = $current_user ?? null;
$is_staff = $cu && in_array($cu->role, array('SUPER_ADMIN','ADMIN','STAFF'), true);
?>
<nav class="border-b bg-white/80 backdrop-blur sticky top-0 z-50">
<div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
<a href="<?=site_url()?>" class="font-bold text-lg">WINDELS PANEL</a>
<div class="hidden md:flex gap-6 text-sm">
<a href="<?=site_url('services')?>">Services</a><a href="<?=site_url('pricing')?>">Pricing</a><a href="<?=site_url('faq')?>">FAQ</a><a href="<?=site_url('blog')?>">Blog</a>
</div>
<div class="flex items-center gap-2">
  <?php if ($cu): ?>
    <?php if ($is_staff): ?>
      <a href="<?=site_url('admin')?>" class="px-4 py-2 text-sm">Admin</a>
    <?php else: ?>
      <a href="<?=site_url('dashboard')?>" class="px-4 py-2 text-sm">Dashboard</a>
    <?php endif; ?>
    <a href="<?=site_url('dashboard/favorites')?>" class="px-3 py-2 text-sm" title="Favorites" aria-label="Favorites">★</a>
    <form method="post" action="<?=site_url('logout')?>" class="m-0 inline-block">
      <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
      <button type="submit" class="px-4 py-2 bg-black text-white rounded-lg text-sm border-0 cursor-pointer">Log out</button>
    </form>
  <?php else: ?>
    <a href="<?=site_url('login')?>" class="px-4 py-2 text-sm">Login</a>
    <a href="<?=site_url('register')?>" class="px-4 py-2 bg-black text-white rounded-lg text-sm">Start Ordering</a>
  <?php endif; ?>
</div>
</div>
</nav>
