<?php defined('BASEPATH') OR exit('No direct script access allowed');
$site_name = function_exists('windels_site_name') ? windels_site_name() : 'WINDELS PANEL';
$year = date('Y');
?>
<footer class="ws-footer">
  <div class="container">
    <div class="ws-footer-grid">
      <div class="ws-footer-brand">
        <a class="ws-brand" href="<?=site_url()?>" aria-label="<?=htmlspecialchars($site_name)?> home">
          <?php $this->load->view('partials/brand_logo', array('variant'=>'full','height'=>40)); ?>
          <span class="sr-only"><?=htmlspecialchars($site_name)?></span>
        </a>
        <p class="muted mt-2" style="max-width:22rem">
          A prepaid commerce platform for social-media services, Nigerian VTU,
          virtual numbers, identity checks, gift cards and a platform-owned marketplace.
        </p>
      </div>

      <div>
        <h2>Platform</h2>
        <ul>
          <li><a href="<?=site_url('services')?>">Services</a></li>
          <li><a href="<?=site_url('pricing')?>">Pricing</a></li>
          <li><a href="<?=site_url('faq')?>">FAQ</a></li>
          <li><a href="<?=site_url('api/docs')?>">API documentation</a></li>
        </ul>
      </div>

      <div>
        <h2>Company</h2>
        <ul>
          <li><a href="<?=site_url('about')?>">About</a></li>
          <li><a href="<?=site_url('blog')?>">Blog</a></li>
          <li><a href="<?=site_url('contact')?>">Contact</a></li>
        </ul>
      </div>

      <div>
        <h2>Support</h2>
        <ul>
          <li><a href="<?=site_url('faq')?>">Help centre</a></li>
          <li><a href="<?=site_url('dashboard/tickets')?>">Support tickets</a></li>
          <li><a href="<?=site_url('contact')?>">Contact support</a></li>
        </ul>
      </div>

      <div>
        <h2>Legal</h2>
        <ul>
          <li><a href="<?=site_url('terms')?>">Terms of service</a></li>
          <li><a href="<?=site_url('privacy')?>">Privacy policy</a></li>
          <li><a href="<?=site_url('refund-policy')?>">Refund policy</a></li>
          <li><a href="<?=site_url('acceptable-use')?>">Acceptable use</a></li>
        </ul>
      </div>
    </div>

    <div class="ws-footer-meta">
      <div>© <?=$year?> <?=htmlspecialchars($site_name)?>. Wallet balances are for spending on this platform only and cannot be withdrawn.</div>
      <div class="ws-footer-links">
        <a href="<?=site_url('login')?>">Customer login</a>
        <a href="<?=site_url('admin/login')?>">Staff login</a>
      </div>
    </div>
  </div>
</footer>
