<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<section class="ws-page-hero">
  <div class="container" style="max-width:640px;text-align:center">
    <img src="<?=base_url('assets/images/services/numbers.jpg')?>" alt="" width="280" height="210" style="margin:0 auto 1rem;border-radius:1.25rem;max-width:220px" decoding="async">
    <p class="ws-kicker">404</p>
    <h1>That page is not here</h1>
    <p class="ws-lede" style="margin-left:auto;margin-right:auto">The address may be mistyped, or the page was moved. The rest of the site is still available.</p>
    <div class="row" style="justify-content:center;margin-top:1.5rem">
      <a class="btn btn-primary" href="<?=site_url()?>">Go to homepage</a>
      <a class="btn btn-secondary" href="<?=site_url('services')?>">Browse services</a>
      <a class="btn btn-ghost" href="<?=site_url('contact')?>">Contact</a>
    </div>
  </div>
</section>
