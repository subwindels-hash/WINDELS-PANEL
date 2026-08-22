<?php
defined('BASEPATH') OR exit('No direct script access allowed');
if (!class_exists('SiteOperatorKnowledge', false)) {
    require_once APPPATH.'libraries/SiteOperatorKnowledge.php';
}
$effective = SiteOperatorKnowledge::EFFECTIVE_DATE;
$updated = SiteOperatorKnowledge::UPDATED_DATE;
$inventory = SiteOperatorKnowledge::data_inventory();
$processors = SiteOperatorKnowledge::processors();
$toc = array(
    'collect' => 'Information collected',
    'use' => 'How information is used',
    'bases' => 'Legal bases',
    'sharing' => 'Sharing and processors',
    'cookies' => 'Cookies',
    'security' => 'Security measures',
    'retention' => 'Retention',
    'rights' => 'Your rights',
    'deletion' => 'Account deletion',
    'transfers' => 'International transfers',
    'children' => 'Children',
    'changes' => 'Policy changes',
    'contact' => 'Contact',
);
?>
<section class="ws-page-hero">
  <div class="container">
    <p class="ws-kicker">Legal</p>
    <h1>Privacy Policy</h1>
    <p class="ws-lede">This policy describes the data this WINDELS PANEL instance actually handles — accounts, sessions, orders, optional payment and fulfilment providers, and the on-site assistant. It is written from the codebase, not copied from a generic template.</p>
    <p class="hint">Effective <?=htmlspecialchars($effective)?> · Last updated <?=htmlspecialchars($updated)?></p>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container ws-legal-grid">
    <nav class="ws-toc card" aria-label="Table of contents">
      <h2 class="card-title">Contents</h2>
      <ol>
        <?php $i=1; foreach ($toc as $id => $label): ?>
          <li><a href="#<?=$id?>"><?=$i++?>. <?=htmlspecialchars($label)?></a></li>
        <?php endforeach; ?>
      </ol>
    </nav>
    <article class="ws-prose">
      <div class="ws-callout">
        The operator of this deployment is the controller of personal data. Identity, address and a Data Protection Officer (if one is appointed) must be published by that operator. Until then, use the support email in site settings.
      </div>

      <h2 id="collect">1. Information collected</h2>
      <?php foreach ($inventory as $label => $text): ?>
        <h3><?=htmlspecialchars($label)?></h3>
        <p><?=htmlspecialchars($text)?></p>
      <?php endforeach; ?>
      <p>We do not run a third-party product-analytics script in this codebase. If the operator later adds one, this policy must be updated before it is enabled.</p>

      <h2 id="use">2. How information is used</h2>
      <ul>
        <li>To create and authenticate accounts, including MFA and password reset</li>
        <li>To take payment, credit wallets, place orders and record a ledger</li>
        <li>To fulfil orders through the providers the operator has connected</li>
        <li>To answer support tickets and contact-form messages</li>
        <li>To enforce rate limits, blacklists and Acceptable Use rules</li>
        <li>To answer assistant questions from local knowledge (not a cloud AI API)</li>
        <li>To meet accounting, tax and lawful-request obligations</li>
      </ul>

      <h2 id="bases">3. Legal bases</h2>
      <p><strong>Requires counsel review for a specific jurisdiction.</strong> Where GDPR or a similar regime applies, typical bases are: contract (running the account and fulfilling orders), legitimate interests (security, fraud prevention, service improvement that does not override your rights), legal obligation (tax and accounting records), and consent where we ask for it (for example optional marketing email, which this codebase does not send by default).</p>

      <h2 id="sharing">4. Sharing and processors</h2>
      <p>We do not sell personal data. We share it with processors only as needed to run the service:</p>
      <ul>
        <?php foreach ($processors as $row): ?>
          <li><strong><?=htmlspecialchars($row[0])?>.</strong> <?=htmlspecialchars($row[1])?></li>
        <?php endforeach; ?>
      </ul>
      <p>Staff with the right RBAC permission can see operational records. Impersonation, if granted, is read-only, time-boxed and audited. Lawful requests from authorities may compel disclosure.</p>

      <h2 id="cookies">5. Cookies</h2>
      <p>A first-party session cookie keeps you signed in and binds the CSRF token. That cookie is required for the logged-in product to work. Optional “remember me” extends the session cookie lifetime on the device you used to sign in. This repository does not ship advertising or cross-site tracking cookies.</p>

      <h2 id="security">6. Security measures</h2>
      <ul>
        <li>Hashed passwords; dummy verify on unknown identifiers</li>
        <li>CSRF protection on state-changing requests; logout is POST-only</li>
        <li>Encrypted MFA secrets, provider credentials and gift-card codes at rest</li>
        <li>TLS verification on outbound provider calls; private-IP egress blocked by default</li>
        <li>Rate limiting on login, registration, password reset, contact and the assistant</li>
        <li>Role checks on every admin controller</li>
      </ul>
      <p>No security measure is a guarantee against every attack. Report a vulnerability to the support address rather than exploiting it.</p>

      <h2 id="retention">7. Retention</h2>
      <p>Account and ledger records are kept for as long as the account exists and for a further period needed for accounting and dispute handling. Identity-lookup payloads are retained for the operator’s <code>identity_retention_days</code> setting (default 30 days) and can be purged. Login-attempt rows are used for throttling and abuse review. Application logs keep request IDs; they are not a second copy of gift-card codes or MFA secrets.</p>

      <h2 id="rights">8. Your rights</h2>
      <p>Depending on where you live you may have rights to access, correct, delete, restrict or port personal data, and to object to certain processing. Use the contact form or a signed-in ticket. We may need to verify the request. Some records (ledger rows, completed tax-relevant payments) cannot be erased without breaking the integrity of the books; we will explain if that applies.</p>

      <h2 id="deletion">9. Account deletion</h2>
      <p>Ask support to close the account. Closure prevents further sign-in and further charges. Wallet leftovers are not paid out as cash. Historical ledger and order rows remain as required to keep the books consistent and to handle chargebacks.</p>

      <h2 id="transfers">10. International transfers</h2>
      <p>If the operator, a payment gateway or a fulfilment vendor stores data in another country, that is an international transfer. <strong>The operator must document the transfer tool (for example standard contractual clauses) once the hosting and vendor locations are known.</strong> This policy does not invent those locations.</p>

      <h2 id="children">11. Children</h2>
      <p>The service is not directed at children under 16, or the higher digital-consent age in your country. If you believe we hold data about a child, contact us and we will delete the account.</p>

      <h2 id="changes">12. Policy changes</h2>
      <p>We will update the date at the top of this page when the policy changes. Material changes that affect how we use personal data will also be announced in the site banner where practical.</p>

      <h2 id="contact">13. Contact</h2>
      <p>Privacy questions: <a href="<?=site_url('contact')?>">contact form</a>. Identity of the controller and any supervisory-authority complaint rights must be completed by the operator for the country in which they are established.</p>
    </article>
  </div>
</section>
