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
    <div><dt class="muted text-xs">Amount</dt><dd class="font-semibold"><?=windels_money($d->amount, $d->currency)?></dd></div>
    <div><dt class="muted text-xs">Credited</dt><dd><?=$d->credited_amount!==null?windels_money($d->credited_amount,$d->currency):'—'?></dd></div>
    <div><dt class="muted text-xs">Method</dt><dd><?=htmlspecialchars($method->name ?? '—')?></dd></div>
    <div><dt class="muted text-xs">Date</dt><dd class="text-sm"><?=date('M j, Y H:i', strtotime($d->created_at))?> UTC</dd></div>
  </dl>
  <?php if ($d->status === 'PENDING' && $method && $method->code === 'manual' && $method->instructions): ?>
    <div class="alert alert-info mt-4 mb-0">
      <strong>Bank transfer instructions:</strong><br><?=nl2br(htmlspecialchars($method->instructions))?>
      <p class="mt-2 mb-0">Include reference <code class="mono"><?=htmlspecialchars($d->public_id)?></code> so the transfer can be matched.</p>
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
          <td class="mono"><?=windels_money($d->amount, $d->currency)?></td>
          <td class="mono muted"><?=windels_money($d->fee, $d->currency)?></td>
          <td class="mono" style="color:var(--success-700)">+<?=windels_money($d->bonus, $d->currency)?></td>
          <td class="mono"><?=$d->credited_amount!==null?windels_money($d->credited_amount,$d->currency):'—'?></td>
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
