<?php
defined('BASEPATH') OR exit('No direct script access allowed');
if (!class_exists('SiteOperatorKnowledge', false)) {
    require_once APPPATH.'libraries/SiteOperatorKnowledge.php';
}
$effective = SiteOperatorKnowledge::EFFECTIVE_DATE;
$updated = SiteOperatorKnowledge::UPDATED_DATE;
$toc = array(
    'acceptance' => 'Acceptance of terms',
    'eligibility' => 'Eligibility',
    'accounts' => 'Account registration',
    'security' => 'Account security',
    'responsibilities' => 'User responsibilities',
    'acceptable' => 'Acceptable use',
    'prohibited' => 'Prohibited activities',
    'availability' => 'Service availability',
    'third-party' => 'Third-party services',
    'payments' => 'Payments and billing',
    'refunds' => 'Refunds and cancellations',
    'ip' => 'Intellectual property',
    'user-content' => 'User content',
    'ai' => 'On-site assistant',
    'disclaimers' => 'Disclaimers',
    'liability' => 'Limitation of liability',
    'suspension' => 'Suspension and termination',
    'changes-service' => 'Changes to the service',
    'changes-terms' => 'Changes to the terms',
    'law' => 'Governing law',
    'contact' => 'Contact',
);
?>
<section class="ws-page-hero">
  <div class="container">
    <p class="ws-kicker">Legal</p>
    <h1>Terms of Service</h1>
    <p class="ws-lede">These terms govern use of this Averion Commerce instance. They describe the software as it actually behaves. They are not a substitute for advice from the operator’s counsel.</p>
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
        <strong>Operator placeholder.</strong> The legal entity, registered address and governing jurisdiction are those of the party that deployed this instance. Until that party publishes them, notices go to the support email configured in settings.
      </div>

      <h2 id="acceptance">1. Acceptance of terms</h2>
      <p>By creating an account, signing in, calling the API or otherwise using the service, you agree to these terms, the Privacy Policy, the Refund Policy and the Acceptable Use Policy. If you do not agree, do not use the service.</p>

      <h2 id="eligibility">2. Eligibility</h2>
      <p>You must be able to form a binding contract. The service is not directed at children under 16 (or the higher age required in your country). You may not use the service if you are barred under applicable law or these terms.</p>

      <h2 id="accounts">3. Account registration</h2>
      <p>You must provide a unique username, a working email address and a password of at least eight characters. A wallet in the panel base currency is created with the account. The operator may require email verification before some actions. Registration may be disabled by the operator at any time.</p>
      <p>One person or organisation should keep one account unless the operator agrees otherwise. You are responsible for activity that occurs under your credentials, including API keys you create.</p>

      <h2 id="security">4. Account security</h2>
      <p>Keep your password and API keys confidential. Enable TOTP two-factor authentication if you handle significant balance or API traffic. Notify support promptly if you believe an account or key is compromised. We may invalidate sessions, revoke keys or lock an account after suspected abuse.</p>

      <h2 id="responsibilities">5. User responsibilities</h2>
      <ul>
        <li>Provide accurate order details (public links, phone numbers, meter numbers, identity identifiers) and only for resources you are allowed to act on.</li>
        <li>Keep enough wallet balance for the orders you place, including scheduled drip-feed and subscription runs.</li>
        <li>Use the dashboard or API as documented. Do not attempt to bypass rate limits, CSRF, RBAC or ledger controls.</li>
        <li>Pay applicable taxes on your purchases where the law requires you to do so.</li>
      </ul>

      <h2 id="acceptable">6. Acceptable use</h2>
      <p>Use of the panel must also comply with the <a href="<?=site_url('acceptable-use')?>">Acceptable Use Policy</a>. In short: no fraud, no attacks on the platform or its providers, no unlawful targeting of third parties, and no resale of identity-lookup results in a way that violates the identity vendor’s rules.</p>

      <h2 id="prohibited">7. Prohibited activities</h2>
      <ul>
        <li>Creating accounts by automated means to abuse promotions or inventory</li>
        <li>Attempting to enumerate whether an email exists via password reset or similar flows</li>
        <li>Submitting payment instruments you are not authorised to use</li>
        <li>Uploading malware, scraping the site in a way that degrades it, or probing for vulnerabilities outside a coordinated disclosure</li>
        <li>Using SMM services to impersonate, defraud, or attack a person or platform in violation of that platform’s rules or applicable law</li>
      </ul>

      <h2 id="availability">8. Service availability</h2>
      <p>The panel depends on the operator’s hosting, on MySQL, optionally Redis, and on upstream providers. Maintenance, provider outages and network failures happen. We do not warrant uninterrupted or error-free operation and we do not publish a contractual uptime commitment on this site.</p>

      <h2 id="third-party">9. Third-party services</h2>
      <p>Orders may be fulfilled by SMM panels, VTpass or another VTU adapter, 5sim or another number adapter, Dojah or another identity adapter, Reloadly or another gift-card adapter, and the payment gateways the operator has enabled. Those vendors have their own terms. The panel is not those vendors, and a failure at a vendor is not automatically a breach by the operator.</p>

      <h2 id="payments">10. Payments and billing</h2>
      <p>The service is prepaid. You add funds through an enabled payment method; the wallet is credited after the payment is verified. Charges for orders and lookups are taken from that wallet through the ledger at the price frozen at checkout. There is no public recurring subscription to cancel. The wallet is a spending balance: the software does not offer customer withdrawals of leftover deposits.</p>
      <p>Manual bank transfer ships enabled. Stripe, PayPal, Paystack, Flutterwave, Razorpay and CoinPayments exist in the software and process data only if the operator turns them on.</p>

      <h2 id="refunds">11. Refunds and cancellations</h2>
      <p>Refunds follow the <a href="<?=site_url('refund-policy')?>">Refund Policy</a>. Partial SMM deliveries may return the undelivered quantity to the wallet. Completed, consumed or revealed products are not automatically reversed. Order cancellation is available only when the specific service and order state allow it.</p>

      <h2 id="ip">12. Intellectual property</h2>
      <p>The panel software, design system, documentation and operator branding remain the property of their respective owners. You receive a limited, revocable right to use the hosted service. You do not receive ownership of provider inventory, gift-card brands or third-party trademarks that appear in the catalogue.</p>

      <h2 id="user-content">13. User content</h2>
      <p>You retain rights in content you submit (ticket messages, profile names, order links, marketplace dispute text). You grant the operator a licence to host, process and display that content as needed to run the service and to investigate abuse. Do not submit content you do not have the right to use.</p>

      <h2 id="ai">14. On-site assistant</h2>
      <p>The embedded assistant is a local operational engine. It matches questions to site knowledge. It is not a generative cloud model and it does not call a third-party AI API. It cannot place orders, move funds or change settings. If it is wrong, the legal pages, dashboard and a human ticket take precedence.</p>

      <h2 id="disclaimers">15. Disclaimers</h2>
      <p>THE SERVICE IS PROVIDED “AS IS” AND “AS AVAILABLE”. TO THE MAXIMUM EXTENT PERMITTED BY LAW, THE OPERATOR DISCLAIMS IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT. SOCIAL-PLATFORM OUTCOMES, SMS DELIVERY, IDENTITY-VENDOR MATCHES AND GIFT-CARD REDEMPTION ARE OUTSIDE THE OPERATOR’S SOLE CONTROL.</p>

      <h2 id="liability">16. Limitation of liability</h2>
      <p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, THE OPERATOR IS NOT LIABLE FOR INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL OR PUNITIVE DAMAGES, OR FOR LOST PROFITS, LOST DATA OR BUSINESS INTERRUPTION. THE OPERATOR’S TOTAL LIABILITY FOR A CLAIM RELATING TO THE SERVICE IS LIMITED TO THE AMOUNT YOU PAID FOR THE SPECIFIC ORDER GIVING RISE TO THE CLAIM IN THE THREE MONTHS BEFORE THE CLAIM, OR THE AMOUNT STILL IN YOUR WALLET FOR THAT MATTER, WHICHEVER IS SMALLER.</p>
      <p>Nothing in these terms excludes liability that cannot be excluded under applicable law, including liability for fraud or for death or personal injury caused by negligence where that exclusion is forbidden.</p>

      <h2 id="suspension">17. Suspension and termination</h2>
      <p>The operator may suspend or close an account for unpaid chargebacks, Acceptable Use violations, legal risk, or to protect the ledger. You may stop using the service at any time and may ask support to close the account. Closure does not erase ledger rows needed to keep financial records consistent.</p>

      <h2 id="changes-service">18. Changes to the service</h2>
      <p>Features can be enabled or disabled by configuration (for example mass orders, the marketplace, or the API). Catalogue items appear only when priced and active. The operator may change providers, rates and limits.</p>

      <h2 id="changes-terms">19. Changes to the terms</h2>
      <p>Material changes will be indicated by updating the “Last updated” date on this page and, where practical, by an announcement. Continued use after the update is acceptance of the revised terms. If you do not agree, stop using the service and ask for account closure.</p>

      <h2 id="law">20. Governing law</h2>
      <p><strong>Requires review by the operator’s legal counsel.</strong> Until a jurisdiction is designated in writing by the operator, these terms are governed by the laws of the operator’s principal place of business, excluding conflict-of-law rules. Courts of that place have non-exclusive jurisdiction, except where consumer-protection law gives you a non-waivable forum.</p>

      <h2 id="contact">21. Contact</h2>
      <p>Questions about these terms: use the <a href="<?=site_url('contact')?>">contact form</a> or the support email published in site settings.</p>
    </article>
  </div>
</section>
