<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?=htmlspecialchars($title ?? 'WINDELS PANEL')?> · WINDELS PANEL</title>
<?php $this->load->view('partials/icons_meta'); ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap">
<?php // CSRF token for JavaScript: assets/js/app.js reads these and attaches the
      // token to every same-origin fetch/XHR, so a page that posts more than
      // once (reply box, chat widget, retry) never sends a retired token. ?>
<meta name="csrf-name" content="<?=htmlspecialchars($this->security->get_csrf_token_name())?>">
<meta name="csrf-token" content="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
<meta name="csrf-endpoint" content="<?=htmlspecialchars(site_url('csrf'))?>">
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
<link rel="stylesheet" href="<?=base_url('assets/css/design-system.css')?>">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<?php $this->load->view('partials/announcement_bar'); ?>
<div class="min-h-screen flex flex-col">
  <header class="border-b bg-white">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
      <a href="<?=site_url()?>" class="ws-brand">
        <?php $this->load->view('partials/brand_logo', array('variant'=>'horizontal','height'=>30)); ?>
        <span class="sr-only">WINDELS PANEL</span>
      </a>
      <?php if (!empty($current_user)): ?>
        <form method="post" action="<?=site_url('logout')?>" class="m-0">
          <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
          <button type="submit" class="text-sm text-slate-600 hover:text-slate-900 bg-transparent border-0 p-0 cursor-pointer">Log out</button>
        </form>
      <?php else: ?>
        <a href="<?=site_url()?>" class="text-sm text-slate-600 hover:text-slate-900">← Back to site</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="flex-1">
    <div class="ws-auth-shell">
      <aside class="ws-auth-visual" aria-hidden="true">
        <img src="<?=base_url('assets/images/home/hero.jpg')?>" alt="" width="960" height="1200">
        <div class="ws-auth-visual-inner">
          <img src="<?=base_url('assets/brand/logo-dark.svg')?>" alt="" height="40" width="176" class="ws-logo">
          <h2 class="mt-6">A wallet you can audit. Orders you can follow.</h2>
          <p class="muted" style="color:#cbd5e1">Prepaid SMM, VTU and digital goods — same ledger, same staff tools.</p>
        </div>
      </aside>
      <div class="flex items-start justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <?php $flash_success = $this->session->flashdata('success'); ?>
      <?php $flash_error   = $this->session->flashdata('error'); ?>
      <?php $dev_link      = $this->session->flashdata('dev_link'); ?>
      <?php if ($flash_success): ?>
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?=htmlspecialchars($flash_success)?></div>
      <?php endif; ?>
      <?php if ($flash_error): ?>
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?=htmlspecialchars($flash_error)?></div>
      <?php endif; ?>
      <?php if ($dev_link): ?>
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <strong>Development link:</strong>
          <a href="<?=htmlspecialchars($dev_link)?>" class="underline break-all"><?=htmlspecialchars($dev_link)?></a>
        </div>
      <?php endif; ?>

      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
        <?php
        // Expose every view variable (title, referral, token, error, …) to the partial.
        $this->load->view($content_view, array_diff_key(get_defined_vars(), array_flip(array('content_view'))));
        ?>
      </div>
    </div>
  </main>
</div>
<?php $this->load->view('partials/site_operator'); ?>
<script src="<?=base_url('assets/js/app.js')?>"></script>
</body>
</html>
