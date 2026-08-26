<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
?>
<div class="row justify-between mb-4">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Earnings ledger</h2>
    <p class="muted text-sm">
      Every referral and campaign earning. Reversing writes an offsetting entry and an audit record —
      nothing is deleted.
    </p>
  </div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/payouts')?>">Payouts</a>
</div>

<div class="grid grid-4 mb-4">
  <?php foreach (array('PENDING','AVAILABLE','LOCKED','PAID') as $s): ?>
  <div class="card">
    <div class="muted text-xs"><?=htmlspecialchars(ucfirst(strtolower($s)))?></div>
    <div class="mono" style="font-size:1.25rem;font-weight:600">
      <?=marvy_money($totals[$s]['total'] ?? '0')?>
    </div>
    <div class="muted text-xs"><?=(int)($totals[$s]['count'] ?? 0)?> entries</div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (!$entries): ?>
    <p class="muted text-sm">No earnings recorded yet.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Reference</th><th>User</th><th>Source</th><th>Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($entries as $e): ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars($e->public_id)?></td>
        <td class="text-xs">#<?=(int)$e->user_id?></td>
        <td class="text-xs"><?=htmlspecialchars($e->source)?></td>
        <td class="mono"><?=marvy_money($e->amount)?></td>
        <td><span class="badge badge-default"><?=htmlspecialchars($e->status)?></span></td>
        <td class="text-xs"><?=htmlspecialchars(date('j M H:i', strtotime($e->created_at)))?></td>
        <td class="text-right">
          <?php if (!in_array($e->status, array('PAID','REVERSED'), true)): ?>
          <form method="post" action="<?=site_url('admin/earnings/'.$e->public_id.'/reverse')?>"
                onsubmit="return confirm('Reverse this earning? An offsetting ledger entry is written.')">
            <?=$csrf()?>
            <input type="hidden" name="reason" value="Reversed by staff">
            <button class="btn btn-secondary btn-sm" type="submit">Reverse</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted text-xs mt-2"><?=number_format($total)?> entries.</p>
  <?php endif; ?>
</div>
