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
  <?php if ($d->status === 'PENDING' && !empty($checkout)):
    // A provider-issued transfer account. The window is short, so the expiry
    // is shown rather than left for the customer to discover.
    $expired = !empty($checkout->expires_at) && strtotime($checkout->expires_at.' UTC') < time();
  ?>
    <div class="alert <?=$expired ? 'alert-warning' : 'alert-info'?> mt-4 mb-0">
      <?php if ($expired): ?>
        <strong>This transfer account has expired.</strong>
        <p class="mt-1 mb-0">
          Start a new deposit to get fresh account details. If you already sent the money it will
          still be credited once the bank confirms it.
        </p>
      <?php else: ?>
        <strong>Transfer <?=marvy_money($d->amount, $d->currency)?> to this account</strong>
        <dl class="grid grid-3 mt-3" style="gap:1rem">
          <div>
            <dt class="muted text-xs">Bank</dt>
            <dd class="font-semibold"><?=htmlspecialchars((string)$checkout->bank_name)?></dd>
          </div>
          <div>
            <dt class="muted text-xs">Account number</dt>
            <dd class="mono font-semibold" style="font-size:1.1rem"><?=htmlspecialchars((string)$checkout->account_number)?></dd>
          </div>
          <div>
            <dt class="muted text-xs">Account name</dt>
            <dd class="font-semibold"><?=htmlspecialchars((string)$checkout->account_name)?></dd>
          </div>
        </dl>
        <p class="mt-3 mb-0 text-sm">
          Send the exact amount. Your wallet is credited automatically once the bank confirms the
          transfer — you do not need to send us a receipt.
          <?php if (!empty($checkout->expires_at)): ?>
            <br>These details expire at
            <strong><?=htmlspecialchars(date('H:i', strtotime($checkout->expires_at.' UTC')))?> UTC</strong>.
          <?php endif; ?>
        </p>
        <?php if (!empty($checkout->checkout_url)): ?>
          <a class="btn btn-primary btn-sm mt-3" href="<?=htmlspecialchars($checkout->checkout_url)?>"
             target="_blank" rel="noopener">Open secure checkout page</a>
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
