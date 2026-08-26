<?php
defined('BASEPATH') OR exit('No direct script access allowed');
if (!class_exists('SiteOperatorKnowledge', false)) {
    require_once APPPATH.'libraries/SiteOperatorKnowledge.php';
}
?>
<section class="ws-page-hero">
  <div class="container" style="max-width:760px">
    <p class="ws-kicker">Legal</p>
    <h1>Acceptable Use Policy</h1>
    <p class="ws-lede">Rules for using this MarvySocials instance. Breaking them can mean a refused order, a locked account, or a report to a provider or authority.</p>
    <p class="hint">Effective <?=htmlspecialchars(SiteOperatorKnowledge::EFFECTIVE_DATE)?> · Last updated <?=htmlspecialchars(SiteOperatorKnowledge::UPDATED_DATE)?></p>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container ws-prose">
    <h2>You may</h2>
    <ul>
      <li>Create one account for yourself or your organisation and keep its credentials private</li>
      <li>Order catalogue services for properties you own or are authorised to promote</li>
      <li>Use VTU and bill payment for numbers and meters you are allowed to fund</li>
      <li>Rent virtual numbers for your own account enrolments</li>
      <li>Run identity lookups only with a lawful basis and only for the identifier the product asks for</li>
      <li>Buy gift cards and marketplace listings for legitimate use</li>
      <li>Call the reseller API with a key you created, within its rate limit and IP allowlist</li>
    </ul>

    <h2>You may not</h2>
    <ul>
      <li>Attack, scan or overload the panel, its staff, or its providers</li>
      <li>Bypass authentication, CSRF, RBAC, rate limits or the ledger</li>
      <li>Use stolen payment instruments or attempt to launder funds through the wallet</li>
      <li>Order SMM activity that is illegal, that impersonates someone, or that you have no right to place</li>
      <li>Use virtual numbers to commit fraud or to evade a ban you are not allowed to evade</li>
      <li>Resell raw identity-lookup payloads in breach of the identity vendor’s terms or applicable KYC law</li>
      <li>Share revealed gift-card codes or marketplace secrets you do not own</li>
      <li>Open multiple accounts to abuse inventory, referrals or rate limits</li>
      <li>Upload or link malware, or use tickets to harass staff</li>
    </ul>

    <h2>Enforcement</h2>
    <p>The operator may refuse an order, freeze a wallet, revoke API keys, suspend or ban an account, and keep ledger evidence. Where required by law the operator may notify a provider or authority. Appeals go through the <a href="<?=site_url('contact')?>">contact form</a>.</p>
  </div>
</section>
