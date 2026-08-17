<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?=htmlspecialchars($title ?? 'WINDELS PANEL')?></title>
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<div class="min-h-screen flex flex-col">
  <header class="border-b bg-white">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
      <a href="<?=site_url()?>" class="font-bold text-lg tracking-tight">WINDELS PANEL</a>
      <?php if (!empty($current_user)): ?>
        <a href="<?=site_url('logout')?>" class="text-sm text-slate-600 hover:text-slate-900">Log out</a>
      <?php else: ?>
        <a href="<?=site_url()?>" class="text-sm text-slate-600 hover:text-slate-900">← Back to site</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="flex-1 flex items-start justify-center px-4 py-12">
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
<script src="<?=base_url('assets/js/app.js')?>"></script>
</body>
</html>
