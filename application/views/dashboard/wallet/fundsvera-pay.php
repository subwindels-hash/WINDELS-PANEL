<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
// Fundsvera checkout — the dedicated "pay for this deposit" page.
//
// The wallet screen keeps a compact summary; this page is where the customer
// actually completes a Fundsvera payment: the hosted checkout link (card
// route) beside the bank-transfer details (transfer route). It receives the
// same variables the deposit screen builds them from, and degrades to a
// plain "check your deposit" card when the checkout details are incomplete —
// a half-answered provider response must never leave the customer with a
// blank screen and no way to pay.
$d      = isset($active_deposit) ? $active_deposit : null;
$gc     = is_array($gateway_checkout ?? null) ? $gateway_checkout : array();
$chk    = isset($checkout) ? $checkout : null;
$fv = array(
    'account_number' => trim((string)($chk->account_number ?? '')) !== '' ? (string)$chk->account_number : (string)($gc['account_number'] ?? ''),
    'account_name'   => trim((string)($chk->account_name ?? ''))   !== '' ? (string)$chk->account_name   : (string)($gc['account_name'] ?? ''),
    'bank_name'      => trim((string)($chk->bank_name ?? ''))      !== '' ? (string)$chk->bank_name      : (string)($gc['bank_name'] ?? ''),
    'checkout_url'   => trim((string)($chk->checkout_url ?? ''))   !== '' ? (string)$chk->checkout_url   : (string)($gc['checkout_url'] ?? ''),
    'expires_at'     => trim((string)($chk->expires_at ?? '')) !== '' ? (string)$chk->expires_at : (string)($gc['expires_at'] ?? ''),
);
$expired = $fv['expires_at'] !== '' && strtotime($fv['expires_at'].' UTC') < time();
$status  = $d ? (string)$d->status : 'PENDING';
?>
<div class="card max-w-2xl">
  <div class="row justify-between">
    <h2 class="card-title mb-0">Complete your payment</h2>
    <span class="badge <?=$status==='SUCCESS'?'badge-success':($status==='FAILED'?'badge-danger':'badge-warning')?>"><?=htmlspecialchars($status)?></span>
  </div>

<?php if ($status === 'SUCCESS'): ?>
  <div class="alert alert-success mt-4 mb-0">
    <strong>Payment received — your wallet has been credited.</strong>
    <p class="mt-1 mb-0">Nothing else to do. Payments occasionally take a moment to settle; the
      balance on your dashboard is the source of truth.</p>
  </div>
<?php elseif ($status === 'FAILED'): ?>
  <div class="alert alert-danger mt-4 mb-0">
    <strong>This payment did not go through.</strong>
    <p class="mt-1 mb-0">No money left your account for it. Start a new deposit whenever you are ready.</p>
  </div>
<?php else: ?>
  <?php if ($expired): ?>
  <div class="alert alert-warning mt-4">
    <strong>This payment window has expired.</strong>
    <p class="mt-1 mb-0">Start a new deposit to get fresh details. If you already paid, it will
      still be credited once the payment is confirmed.</p>
  </div>
  <?php else: ?>
  <div class="alert alert-info mt-4">
    <strong>Pay <?=$d ? marvy_money($d->amount, $d->currency) : 'the deposit amount'?></strong>
    <p class="mt-1 mb-0">Use either route below. The deposit is credited automatically the moment
      the payment is confirmed — no message or screenshot needed.</p>
  </div>
  <?php endif; ?>

  <?php if ($fv['checkout_url'] !== ''): ?>
  <div class="mt-4">
    <h3 class="text-sm font-semibold">Pay by card</h3>
    <p class="hint mt-1">Fundsvera's secure checkout opens in a new tab.</p>
    <a class="btn btn-primary" href="<?=htmlspecialchars($fv['checkout_url'])?>" target="_blank" rel="noopener noreferrer">
      Open secure checkout
    </a>
  </div>
  <?php endif; ?>

  <?php if ($fv['account_number'] !== ''): ?>
  <div class="mt-4">
    <h3 class="text-sm font-semibold">Pay by bank transfer</h3>
    <dl class="grid grid-2 mt-2" style="gap:1rem">
      <div><dt class="muted text-xs">Bank</dt><dd class="font-semibold"><?=htmlspecialchars($fv['bank_name'])?></dd></div>
      <div><dt class="muted text-xs">Account number</dt><dd class="font-semibold"><?=htmlspecialchars($fv['account_number'])?></dd></div>
      <div class="grid-col-2"><dt class="muted text-xs">Account name</dt><dd class="font-semibold"><?=htmlspecialchars($fv['account_name'])?></dd></div>
    </dl>
    <p class="hint mt-2 mb-0">Transfer the exact amount. Under- and over-payments cannot be matched
      automatically and delay the credit.</p>
  </div>
  <?php elseif ($fv['checkout_url'] === ''): ?>
  <div class="alert alert-warning mt-4 mb-0">
    <strong>Payment details are not available for this deposit yet.</strong>
    <p class="mt-1 mb-0">Check your deposit history in a moment, or start a new deposit.</p>
  </div>
  <?php endif; ?>
<?php endif; ?>

  <div class="row mt-6" style="gap:.5rem">
    <a class="btn btn-ghost" href="<?=site_url('dashboard/transactions')?>">View transactions</a>
    <a class="btn btn-ghost" href="<?=site_url('dashboard/add-funds')?>">New deposit</a>
  </div>
</div>
