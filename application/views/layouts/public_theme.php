<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Controllers pass `content_view` and `data`; alias them to the names this
// layout uses. Without this, `$content` is null, the `!== ''` guard passes,
// and `$this->load->view(null)` blows up with "Unable to load the requested
// file: .php" (HTTP 500). Mirrors layouts/main.php.
$content   = $content_view ?? '';
$page_data = $data ?? array();
?>
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
<body class="min-h-screen bg-gradient-to-b from-[#0a0a0f] via-[#12121a] to-[#0a0a0f] text-slate-900 antialiased ws-public-shell">
<a class="ws-skip" href="#main">Skip to content</a>
<?php $this->load->view('partials/announcement'); ?>
<header class="border-b border-white/10 bg-[gradient_legacy] from-[#0a0a0f] to-[#12121a] shadow-lg">
  <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
    <a href="<?=site_url()?>" class="flex items-center gap-2">
      <img src="<?=base_url('assets/images/logo-white.png')?>" alt="WINDELS" class="w-6 h-6">
      <span class="text-xl font-bold tracking-text-purple-400">WINDELSOCIALS</span>
    </a>
    <div class="hidden sm:flex items-center gap-4">
      <a href="<?=site_url('dashboard')?>" class="text-slate-400 hover:text-purple-400 transition-colors font-medium">Dashboard</a>
      <a href="<?=site_url('services')?>" class="text-slate-400 hover:text-purple-400 transition-colors font-medium">Services</a>
      <a href="<?=site_url('shop')?>" class="text-slate-400 hover:text-purple-400 transition-colors font-medium">Shop</a>
      <a href="<?=site_url('api/docs')?>" class="text-slate-400 hover:text-purple-400 transition-colors font-medium">API</a>
      <a href="<?=site_url('faq')?>" class="text-slate-400 hover:text-purple-400 transition-colors font-medium">FAQ</a>
    </div>
    <div class="flex items-center gap-3">
      <a href="<?=site_url('login')?>" class="btn btn-primary text-sm px-4 py-2 rounded hover:bg-purple-600/80 transition-colors">
        Login
      </a>
      <a href="<?=site_url('register')?>" class="btn btn-ghost text-sm px-4 py-2 rounded border border-white/20 hover:bg-white/10 transition-colors">
        Get started
      </a>
    </div>
  </div>
</header>

<main id="main" class="ws-main px-6 py-8">
  <?php if (!empty($content) && is_file(VIEWPATH.$content.'.php')): ?>
    <?php
      // Pass the whole layout scope (incl. `$data`) so content views that read
      // `$data[...]` work, while still exposing the individual keys. A missing
      // or empty view falls through to the empty-state instead of 500-ing.
      $this->load->view($content, array_diff_key(get_defined_vars(), array_flip(array('content','page_data'))));
    ?>
  <?php else: ?>
    <div class="container ws-section-sm">
      <div class="empty-state card text-center py-12">
        <h2 class="text-2xl font-bold text-purple-400 mb-2">Nothing to show</h2>
        <p class="text-slate-400">This page has no content yet.</p>
      </div>
    </div>
  <?php endif; ?>
</main>

<footer class="border-t border-white/10 bg-[gradient_legacy] from-[#0a0a0f] to-[#12121a] py-6">
  <div class="max-w-7xl mx-auto px-6 text-center text-slate-400 text-sm">
    <p>2026 WINDELSOCIALS. All rights reserved.</p>
    <div class="mt-2">
      <a href="<?=site_url('terms')?>" class="hover:text-purple-400 transition-colors">Terms</a>
      <span class="mx-2">|</span>
      <a href="<?=site_url('privacy')?>" class="hover:text-purple-400 transition-colors">Privacy</a>
    </div>
  </div>
</div>

<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>