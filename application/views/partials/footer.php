<?php defined('BASEPATH') OR exit('No direct script access allowed');
$site_name = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
$year = date('Y');
// Who is legally behind the brand. Empty until the operator publishes it, and
// an empty line is simply not rendered — a footer must never show a stray
// comma or the word "null" to a customer deciding whether to deposit.
if (!class_exists('LegalIdentity', false)) {
    require_once APPPATH.'libraries/LegalIdentity.php';
}
$legal_line = LegalIdentity::line();
?>
<footer class="ws-footer">
  <div class="container">
    <div class="ws-footer-grid">
      <div class="ws-footer-brand">
        <a class="ws-brand" href="<?=site_url()?>" aria-label="<?=htmlspecialchars($site_name)?> home">
          <?php // The footer is navy: the light wordmark, not the dark one with a CSS filter over it.
                // 44px is the site's largest placement — the footer mark is the sign-off, so it
                // reads at a normal size instead of the 36px the old max-height cap forced.
                $this->load->view('partials/brand_logo', array('variant'=>'dark','height'=>44,'class'=>'ws-logo ws-footer-logo')); ?>
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
          <?php if (marvy_feature_enabled('blog', true)): ?>
          <li><a href="<?=site_url('blog')?>">Blog</a></li>
          <?php endif; ?>
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
      <div>
        © <?=$year?> <?=htmlspecialchars($site_name)?>. Wallet balances are for spending on this platform only and cannot be withdrawn.
        <?php if ($legal_line !== ''): ?>
          <div class="muted text-xs mt-1">Operated by <?=htmlspecialchars($legal_line)?>.</div>
        <?php endif; ?>
      </div>
      <div class="ws-footer-links">
        <a href="<?=site_url('login')?>">Customer login</a>
        <?php
        // The staff door is not advertised to customers. It is only linked for
        // someone already signed in with a back-office role; everyone else
        // reaches /admin/login by typing it, which is the point of a separate
        // door. Removing the link changes no route and no permission check.
        $footer_role = isset($current_user->role) ? (string)$current_user->role : '';
        if (in_array($footer_role, array('SUPER_ADMIN','ADMIN','STAFF'), true)): ?>
          <a href="<?=site_url('admin/login')?>">Staff login</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</footer>
