<?php defined('BASEPATH') OR exit('No direct script access allowed');
$badge = array(
  'SUCCESSFUL' => 'badge-success', 'PROCESSING' => 'badge-warning',
  'PENDING' => 'badge-warning', 'FAILED' => 'badge-error',
  'REFUNDED' => 'badge-muted', 'CANCELLED' => 'badge-muted',
);
$class = $badge[$tx->status] ?? 'badge-muted';
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <h2 class="card-title mb-0">Receipt</h2>
    <span class="badge <?=$class?>"><?=htmlspecialchars($tx->status)?></span>
  </div>

  <div class="overflow-x-auto mt-4">
    <table class="table">
      <tbody>
        <tr><th>Reference</th><td class="mono text-xs"><?=htmlspecialchars($tx->public_id)?></td></tr>
        <tr><th>Service</th><td><?=htmlspecialchars($tx->service_type)?></td></tr>
        <tr><th>Amount</th><td><strong><?=marvy_money($tx->amount)?></strong></td></tr>
        <?php if ($detail): ?>
          <tr><th>Recipient</th><td><?=htmlspecialchars($detail->recipient)?></td></tr>
          <?php if (!empty($detail->recipient_name)): ?>
            <tr><th>Name</th><td><?=htmlspecialchars($detail->recipient_name)?></td></tr>
          <?php endif; ?>
          <?php if (!empty($detail->token)): ?>
            <tr><th>Token</th><td class="mono"><strong><?=htmlspecialchars($detail->token)?></strong></td></tr>
          <?php endif; ?>
          <?php if (!empty($detail->units)): ?>
            <tr><th>Units</th><td><?=htmlspecialchars($detail->units)?></td></tr>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (bccomp((string)$tx->refunded_amount, '0', 8) > 0): ?>
          <tr><th>Refunded</th><td><?=marvy_money($tx->refunded_amount)?></td></tr>
        <?php endif; ?>
        <?php if (!empty($tx->failure_reason)): ?>
          <tr><th>Reason</th><td><?=htmlspecialchars($tx->failure_reason)?></td></tr>
        <?php endif; ?>
        <tr><th>Date</th><td><?=htmlspecialchars($tx->created_at)?> UTC</td></tr>
      </tbody>
    </table>
  </div>

  <div class="row mt-4" style="gap:.5rem">
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/vtu/history')?>">History</a>
    <a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/vtu')?>">Buy again</a>
  </div>
</div>
