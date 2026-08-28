<?php defined('BASEPATH') OR exit('No direct script access allowed');
$auth_site = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
?>
<!doctype html>
<html lang="en">
<head>
<?php $this->load->view('partials/head', array(
    'page_title'      => $title ?? 'Log in',
    'page_desc'       => 'Secure sign in to '.$auth_site.'.',
    'page_robots'     => 'noindex,nofollow',
    'page_canonical'  => '',
)); ?>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased ws-auth-shell-page">
<a class="ws-skip" href="#main">Skip to content</a>
<?php $this->load->view('partials/announcement'); ?>
<div class="min-h-screen flex flex-col">
  <header class="ws-auth-header border-b">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
      <a href="<?=site_url()?>" class="ws-brand">
        <?php $this->load->view('partials/brand_logo', array('variant'=>'dark','height'=>30)); ?>
        <span class="sr-only"><?=htmlspecialchars($auth_site)?></span>
      </a>
      <nav class="ws-auth-nav" aria-label="Primary">
        <a href="<?=site_url('services')?>">Services</a>
        <a href="<?=site_url('shop')?>">Shop</a>
        <a href="<?=site_url('pricing')?>">Pricing</a>
        <a href="<?=site_url('faq')?>">FAQ</a>
        <?php if (!empty($current_user)): ?>
          <form method="post" action="<?=site_url('logout')?>" class="m-0 inline">
            <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
            <button type="submit" class="text-sm text-slate-600 hover:text-slate-900 bg-transparent border-0 p-0 cursor-pointer">Log out</button>
          </form>
        <?php else: ?>
          <a href="<?=site_url('login')?>">Log in</a>
          <a href="<?=site_url('register')?>" class="btn btn-primary btn-sm">Get started</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <main id="main" class="flex-1" tabindex="-1">
    <div class="ws-auth-shell">
      <?php
        // The panel is real content, not decoration: it carries the brand mark
        // and the one-line pitch. It used to be aria-hidden, which meant a
        // screen reader skipped the words and — because the logo's alt text
        // sat inside it — read the brand name straight into the sentence, so
        // the whole block announced as one run-on line. The copy is also
        // overridable, so the staff door does not greet an administrator with
        // a customer sales pitch.
        $visual_title = $auth_visual_title ?? 'A wallet you can audit. Orders you can follow.';
        $visual_text  = $auth_visual_text
            ?? 'Prepaid SMM, VTU and digital goods — same ledger, same staff tools.';
      ?>
      <aside class="ws-auth-visual">
        <img src="<?=base_url('assets/images/home/hero.jpg')?>" alt="" width="960" height="1200" aria-hidden="true">
        <div class="ws-auth-visual-inner">
          <?php $this->load->view('partials/brand_logo', array('variant'=>'dark','height'=>40,'class'=>'ws-logo')); ?>
          <h2><?=htmlspecialchars($visual_title)?></h2>
          <p><?=htmlspecialchars($visual_text)?></p>
        </div>
      </aside>
      <div class="flex items-start justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <?php $this->load->view('partials/flash'); ?>
      <?php $dev_link = $this->session->flashdata('dev_link'); ?>
      <?php if ($dev_link): ?>
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <strong>Development link:</strong>
          <a href="<?=htmlspecialchars($dev_link)?>" class="underline break-all"><?=htmlspecialchars($dev_link)?></a>
        </div>
      <?php endif; ?>

      <div class="bg-surface rounded-2xl shadow-sm border border-slate-200 p-8">
        <?php
        // Expose every view variable (title, referral, token, error, …) to the partial.
        $this->load->view($content_view, array_diff_key(get_defined_vars(), array_flip(array('content_view'))));
        ?>
      </div>
    </div>
    </div>
    </div>
  </main>

  <?php $this->load->view('partials/footer'); ?>
</div>
<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>
