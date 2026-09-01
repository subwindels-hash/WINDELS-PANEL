<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php if (!empty($active_deposit)):
  $d = $active_deposit;
  $method = $this->db->where('id',$d->payment_method_id)->get('payment_methods')->row();
?>
<div class="card max-w-2xl mb-6">
  <div class="row justify-between">
    <h2 class="card-title mb-0">Deposit <?=htmlspecialchars(substr($d->public_id,0,12))?>…</h2>
    <span class="badge <?=$d->status==='SUCCESS'?'badge-success':($d->status==='FAILED'?'badge-danger':'badge-warning')?>"><?=htmlspecialchars($d->status)?></span>
  </div>
  <dl class="grid grid-4 mt-4" style="gap:1rem">
    <div><dt class="muted text-xs">Amount</dt><dd class="font-semibold"><?=marvy_money($d->amount, $d->currency)?></dd></div>
    <div><dt class="muted text-xs">Credited</dt><dd><?=$d->credited_amount!==null?marvy_money($d->credited_amount,$d->currency):'—'?></dd></div>
    <div><dt class="muted text-xs">Method</dt><dd><?=htmlspecialchars($method->name ?? '—')?></dd></div>
    <div><dt class="muted text-xs">Date</dt><dd class="text-sm"><?=date('M j, Y H:i', strtotime($d->created_at))?> UTC</dd></div>
  </dl>
  <?php if ($d->status === 'PENDING' && $method && strtolower((string)$method->code) === 'fundsvera'):
    // A Fundsvera checkout is one link with two payment routes: the hosted
    // secure-checkout page takes card payment, the account details are the
    // bank-transfer route. The details live on the checkout row AND in the
    // transaction metadata (written at initiation) — merge both so a partial
    // provider response still leaves the customer a way to pay.
    $gc = is_array($gateway_checkout ?? null) ? $gateway_checkout : array();
    $fv = array(
        'account_number' => trim((string)($checkout->account_number ?? '')) !== '' ? (string)$checkout->account_number : (string)($gc['account_number'] ?? ''),
        'account_name'   => trim((string)($checkout->account_name ?? ''))   !== '' ? (string)$checkout->account_name   : (string)($gc['account_name'] ?? ''),
        'bank_name'      => trim((string)($checkout->bank_name ?? ''))      !== '' ? (string)$checkout->bank_name      : (string)($gc['bank_name'] ?? ''),
        'checkout_url'   => trim((string)($checkout->checkout_url ?? ''))   !== '' ? (string)$checkout->checkout_url   : (string)($gc['checkout_url'] ?? ''),
        'expires_at'     => trim((string)($checkout->expires_at ?? '')),
    );
    $fv['has_details'] = $fv['account_number'] !== '';
    // The window is short, so the expiry is shown rather than left for the
    // customer to discover.
    $expired = $fv['expires_at'] !== '' && strtotime($fv['expires_at'].' UTC') < time();
  ?>
    <div class="alert <?=$expired ? 'alert-warning' : 'alert-info'?> mt-4 mb-0">
      <?php if ($expired): ?>
        <strong>This payment window has expired.</strong>
        <p class="mt-1 mb-0">
          Start a new deposit to get fresh details. If you already paid it will
          still be credited once the payment is confirmed.
        </p>
      <?php else: ?>
        <strong>Pay <?=marvy_money($d->amount, $d->currency)?> by card or bank transfer</strong>
        <?php if ($fv['checkout_url'] !== ''): ?>
          <p class="mt-2 mb-0 text-sm">
            The secure checkout page accepts <strong>credit and debit cards</strong> as well as
            bank transfer. Your wallet is credited automatically once the payment is confirmed —
            no receipt needed.
          </p>
          <a class="btn btn-primary btn-sm mt-3" href="<?=htmlspecialchars($fv['checkout_url'])?>"
             target="_blank" rel="noopener">Pay now — open secure checkout</a>
        <?php endif; ?>
        <?php if ($fv['has_details']): ?>
          <dl class="grid grid-3 mt-3" style="gap:1rem">
            <div>
              <dt class="muted text-xs">Bank</dt>
              <dd class="font-semibold"><?=htmlspecialchars($fv['bank_name'] !== '' ? $fv['bank_name'] : '—')?></dd>
            </div>
            <div>
              <dt class="muted text-xs">Account number</dt>
              <dd class="mono font-semibold" style="font-size:1.1rem"><?=htmlspecialchars($fv['account_number'])?></dd>
            </div>
            <div>
              <dt class="muted text-xs">Account name</dt>
              <dd class="font-semibold"><?=htmlspecialchars($fv['account_name'] !== '' ? $fv['account_name'] : '—')?></dd>
            </div>
          </dl>
          <p class="mt-3 mb-0 text-sm">
            For a bank transfer, send the exact amount to the account above.
            <?php if ($fv['expires_at'] !== ''): ?>
              <br>These details expire at
              <strong><?=htmlspecialchars(date('H:i', strtotime($fv['expires_at'].' UTC')))?> UTC</strong>.
            <?php endif; ?>
          </p>
        <?php elseif ($fv['checkout_url'] === ''): ?>
          <p class="mt-2 mb-0 text-sm">
            We could not fetch the payment details from the provider just now.
            Please start the deposit again, or contact support with reference
            <code class="mono"><?=htmlspecialchars(substr($d->public_id,0,12))?>…</code>.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php elseif ($d->status === 'PENDING' && !empty($gateway_checkout)): ?>
    <?php $gc = $gateway_checkout; ?>
    <div class="alert alert-info mt-4 mb-0">
      <strong>This payment is waiting to be completed</strong>
      <p class="mt-1 mb-0 text-sm">
        <?=htmlspecialchars((string)($gc['instructions'] ?? 'Finish the payment with the provider; your wallet is credited automatically once it is confirmed.'))?>
      </p>

      <?php if (!empty($gc['address'])): ?>
        <dl class="grid grid-3 mt-3" style="gap:1rem">
          <div>
            <dt class="muted text-xs">Send</dt>
            <dd class="mono font-semibold"><?=htmlspecialchars((string)($gc['coin_amount'] ?? ''))?> <?=htmlspecialchars((string)($gc['coin'] ?? ''))?></dd>
          </div>
          <div style="grid-column:span 2">
            <dt class="muted text-xs">To this address</dt>
            <dd class="mono font-semibold" style="word-break:break-all"><?=htmlspecialchars((string)$gc['address'])?></dd>
          </div>
          <?php if (!empty($gc['confirms_needed'])): ?>
          <div>
            <dt class="muted text-xs">Confirmations needed</dt>
            <dd class="font-semibold"><?=htmlspecialchars((string)$gc['confirms_needed'])?></dd>
          </div>
          <?php endif; ?>
        </dl>
      <?php endif; ?>

      <p class="mt-3 mb-0 text-sm">
        Reference <code class="mono"><?=htmlspecialchars((string)($gc['reference'] ?? $d->internal_reference ?: $d->public_id))?></code>
        <?php if (!empty($gc['expires_in'])): ?> · valid for <?=htmlspecialchars((string)$gc['expires_in'])?><?php endif; ?>
      </p>

      <?php if (!empty($gc['redirect_url'])): ?>
        <a class="btn btn-primary btn-sm mt-3" href="<?=htmlspecialchars((string)$gc['redirect_url'])?>"
           target="_blank" rel="noopener">Resume payment →</a>
      <?php endif; ?>
    </div>
  <?php elseif ($d->status === 'PENDING' && $method && $method->code === 'manual' && $method->instructions): ?>
    <div class="alert alert-info mt-4 mb-0">
      <strong>Bank transfer instructions:</strong><br><?=nl2br(htmlspecialchars($method->instructions))?>
      <p class="mt-2 mb-0">Include reference <code class="mono"><?=htmlspecialchars($d->internal_reference ?: $d->public_id)?></code> so the transfer can be matched.</p>
    </div>
  <?php endif; ?>
  <a class="btn btn-ghost btn-sm mt-4" href="<?=site_url('dashboard/wallet/deposits')?>">← All deposits</a>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="card-title">Deposits</h2>
  <?php if (empty($deposits)): ?>
    <p class="muted mt-2">No deposits yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto mt-3">
    <table class="table">
      <thead><tr><th>Reference</th><th>Amount</th><th>Fee</th><th>Bonus</th><th>Credited</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($deposits as $d): ?>
        <tr>
          <td class="mono text-xs"><?=htmlspecialchars(substr($d->public_id,0,12))?>…</td>
          <td class="mono"><?=marvy_money($d->amount, $d->currency)?></td>
          <td class="mono muted"><?=marvy_money($d->fee, $d->currency)?></td>
          <td class="mono" style="color:var(--success-700)">+<?=marvy_money($d->bonus, $d->currency)?></td>
          <td class="mono"><?=$d->credited_amount!==null?marvy_money($d->credited_amount,$d->currency):'—'?></td>
          <td><span class="badge <?=$d->status==='SUCCESS'?'badge-success':($d->status==='FAILED'?'badge-danger':'badge-warning')?>"><?=htmlspecialchars($d->status)?></span></td>
          <td class="text-xs muted"><?=date('M j, H:i', strtotime($d->created_at))?></td>
          <td><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/wallet/deposits/'.$d->public_id)?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($active_deposit) && $active_deposit->status === 'PENDING'): ?>
<script <?=csp_nonce_attr()?>>
// A pending deposit used to sit on screen until a manual refresh even after
// the webhook had credited it. Poll the status endpoint and reload the page
// the moment it stops being PENDING — bounded so a forgotten tab cannot poll
// for ever.
(function () {
  var ref = <?=json_encode(($active_deposit->internal_reference ?: $active_deposit->public_id))?>;
  var url = <?=json_encode(site_url('api/payments/'.($active_deposit->internal_reference ?: $active_deposit->public_id)))?>;
  var started = Date.now(), ticks = 0;
  function stop() { clearInterval(timer); }
  function check() {
    ticks++;
    if (Date.now() - started > 30 * 60 * 1000) { stop(); return; }
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (body) {
        var status = body && body.success && body.data ? String(body.data.status) : '';
        if (status && status !== 'PENDING') { stop(); location.reload(); }
      })
      .catch(function () { /* transient — try again next tick */ });
  }
  var timer = setInterval(check, 10000);
})();
</script>
<?php endif; ?>
