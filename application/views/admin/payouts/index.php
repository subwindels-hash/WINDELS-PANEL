<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
$badge = function ($s) {
    $map = array('REQUESTED'=>'badge-warning','APPROVED'=>'badge-info',
                 'PAID'=>'badge-success','REJECTED'=>'badge-danger','CANCELLED'=>'badge-default');
    return $map[$s] ?? 'badge-default';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Payouts</h2>
    <p class="muted text-sm">
      Withdrawals of customer earnings. Approving does not send money — send the transfer through your
      own bank, then record its reference here.
    </p>
  </div>
  <div class="row" style="gap:.5rem">
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/earnings')?>">Earnings ledger</a>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/referrals')?>">Referrals</a>
  </div>
</div>

<div class="grid grid-4 mb-4">
  <?php foreach (array('REQUESTED','APPROVED','PAID','REJECTED') as $s): ?>
  <div class="card">
    <div class="muted text-xs"><?=htmlspecialchars(ucfirst(strtolower($s)))?></div>
    <div class="mono" style="font-size:1.25rem;font-weight:600">
      <?=marvy_money($totals[$s]['total'] ?? '0')?>
    </div>
    <div class="muted text-xs"><?=(int)($totals[$s]['count'] ?? 0)?> request(s)</div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (!$payouts): ?>
    <p class="muted text-sm">No payout requests.</p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr><th>Reference</th><th>User</th><th>Amount</th><th>Method</th><th>Destination</th>
          <th>Status</th><th>Requested</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($payouts as $p): ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars($p->public_id)?></td>
        <td class="text-xs">#<?=(int)$p->user_id?></td>
        <td class="mono"><?=marvy_money($p->amount)?></td>
        <td class="text-xs"><?=htmlspecialchars(str_replace('_',' ', $p->method))?></td>
        <td class="text-xs muted">
          <?php // Masked: staff need enough to match a bank statement, not the
                // full account number sitting in a shared browser tab. ?>
          <?php $d = (string)$p->destination; ?>
          <?=$d === '' ? '—' : htmlspecialchars(mb_substr($d, 0, 6).'…'.mb_substr($d, -4))?>
        </td>
        <td><span class="badge <?=$badge($p->status)?>"><?=htmlspecialchars($p->status)?></span></td>
        <td class="text-xs"><?=htmlspecialchars(date('j M H:i', strtotime($p->requested_at)))?></td>
        <td class="text-right">
          <?php if ($p->status === 'REQUESTED'): ?>
            <form method="post" style="display:inline"
                  action="<?=site_url('admin/payouts/'.$p->public_id.'/approve')?>">
              <?=$csrf()?><button class="btn btn-primary btn-sm" type="submit">Approve</button>
            </form>
            <form method="post" style="display:inline"
                  action="<?=site_url('admin/payouts/'.$p->public_id.'/reject')?>">
              <?=$csrf()?><button class="btn btn-secondary btn-sm" type="submit">Reject</button>
            </form>
          <?php elseif ($p->status === 'APPROVED'): ?>
            <form method="post" class="row" style="gap:.25rem;justify-content:flex-end"
                  action="<?=site_url('admin/payouts/'.$p->public_id.'/paid')?>">
              <?=$csrf()?>
              <input class="input mono" type="text" name="reference" required
                     placeholder="Bank reference" style="max-width:11rem" maxlength="160">
              <button class="btn btn-primary btn-sm" type="submit">Mark sent</button>
            </form>
          <?php elseif ($p->payout_reference): ?>
            <span class="mono text-xs muted"><?=htmlspecialchars($p->payout_reference)?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted text-xs mt-2"><?=number_format($total)?> request(s).</p>
  <?php endif; ?>
</div>
