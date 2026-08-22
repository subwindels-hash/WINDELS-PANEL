<?php
defined('BASEPATH') OR exit('No direct script access allowed');
if (!class_exists('SiteOperatorKnowledge', false)) {
    require_once APPPATH.'libraries/SiteOperatorKnowledge.php';
}
?>
<section class="ws-page-hero">
  <div class="container" style="max-width:760px">
    <p class="ws-kicker">Legal</p>
    <h1>Refund Policy</h1>
    <p class="ws-lede">Refunds on WINDELS PANEL return value to the prepaid wallet. They are not bank payouts. This page follows what the order engines actually do.</p>
    <p class="hint">Effective <?=htmlspecialchars(SiteOperatorKnowledge::EFFECTIVE_DATE)?> · Last updated <?=htmlspecialchars(SiteOperatorKnowledge::UPDATED_DATE)?></p>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container ws-prose">
    <h2>Wallet, not cash-out</h2>
    <p>When a refund is due, the amount is credited back to your panel wallet — your spending balance — through the ledger. The software does not offer customer withdrawals of leftover deposits. Affiliate commissions are a separate programme and are not a refund of deposits.</p>

    <h2>Social-media orders</h2>
    <ul>
      <li><strong>Partial delivery.</strong> If a provider completes only part of the quantity and partial refunds are enabled, the undelivered quantity is credited to the wallet and the order is marked PARTIAL.</li>
      <li><strong>Cancellation.</strong> You may request a cancel only when the service supports it and the order is still in a cancellable state. Completed work is not cancelled.</li>
      <li><strong>Refill.</strong> A refill is additional fulfilment, not a cash refund. Use it when the service is marked Refill and you are still inside the refill window.</li>
    </ul>

    <h2>VTU, numbers, identity and gift cards</h2>
    <p>If the relevant engine marks a purchase failed, abandoned, or never delivered (for example a gift-card vendor that never issues a code within the give-up window), the charged amount is returned to the wallet. A revealed gift-card code, a delivered SMS, a successful identity lookup or consumed electricity units are not automatically reversed — those are completed products.</p>

    <h2>Marketplace</h2>
    <p>Marketplace purchases sit in escrow until you accept delivery, a dispute is resolved, or the auto-release window passes. Open a dispute from the order if fulfilment is wrong. Staff resolve disputes; the outcome may be a wallet credit, a redelivery, or no change.</p>

    <h2>Deposits</h2>
    <p>A verified deposit is wallet balance. We do not reverse a completed deposit back to the original payment method except where a payment gateway or law requires it (for example a chargeback). Chargebacks may lead to account suspension.</p>

    <h2>How to ask</h2>
    <p>Use the order or receipt page first. If no automated action applies, <a href="<?=site_url('contact')?>">contact support</a> with the public ID. Discretionary credits are decided by staff and are not a promised right.</p>
  </div>
</section>
