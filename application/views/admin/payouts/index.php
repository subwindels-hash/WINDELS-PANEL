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
$f = $filters;
$qs = function (array $over = array()) use ($f, $page) {
    $base = array(
        'status'     => $f['status'] ?? null,
        'q'          => $f['search'] ?? null,
        'from'       => $f['date_from'] ?? null,
        'to'         => $f['date_to'] ?? null,
        'amount_min' => $f['amount_min'] ?? null,
        'amount_max' => $f['amount_max'] ?? null,
        'page'       => $page,
    );
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== ''; });
    return $merged ? '?'.http_build_query($merged) : '';
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
  <a class="card" style="text-decoration:none;color:inherit" href="<?=site_url('admin/payouts'.$qs(array('status'=>$s,'page'=>null)))?>">
    <div class="muted text-xs"><?=htmlspecialchars(ucfirst(strtolower($s)))?></div>
    <div class="mono" style="font-size:1.25rem;font-weight:600">
      <?=marvy_money($totals[$s]['total'] ?? '0')?>
    </div>
    <div class="muted text-xs"><?=(int)($totals[$s]['count'] ?? 0)?> request(s)</div>
  </a>
  <?php endforeach; ?>
</div>

<form method="get" action="<?=site_url('admin/payouts')?>" class="card mb-4">
  <div class="row" style="gap:.5rem;flex-wrap:wrap;align-items:flex-end">
    <label class="field mb-0" style="flex:1;min-width:14rem"><span class="label">Search users</span>
      <input class="input" name="q" value="<?=htmlspecialchars((string)($f['search'] ?? ''))?>"
             placeholder="Username, email, reference or destination">
    </label>
    <label class="field mb-0"><span class="label">Status</span>
      <select class="select" name="status">
        <option value="">All statuses</option>
        <?php foreach (array('REQUESTED','APPROVED','PAID','REJECTED','CANCELLED') as $s): ?>
          <option value="<?=$s?>" <?=($f['status'] ?? '') === $s ? 'selected' : ''?>><?=ucfirst(strtolower($s))?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field mb-0"><span class="label">From</span>
      <input class="input" type="date" name="from" value="<?=htmlspecialchars((string)($f['date_from'] ?? ''))?>">
    </label>
    <label class="field mb-0"><span class="label">To</span>
      <input class="input" type="date" name="to" value="<?=htmlspecialchars((string)($f['date_to'] ?? ''))?>">
    </label>
    <label class="field mb-0"><span class="label">Min amount</span>
      <input class="input mono" type="number" step="0.01" min="0" name="amount_min" style="max-width:8rem"
             value="<?=htmlspecialchars((string)($f['amount_min'] ?? ''))?>">
    </label>
    <label class="field mb-0"><span class="label">Max amount</span>
      <input class="input mono" type="number" step="0.01" min="0" name="amount_max" style="max-width:8rem"
             value="<?=htmlspecialchars((string)($f['amount_max'] ?? ''))?>">
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Filter</button>
    <?php if (array_filter($f)): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/payouts')?>">Clear</a>
    <?php endif; ?>
  </div>
</form>

<div class="card">
  <?php if (!$payouts): ?>
    <p class="muted text-sm">No payout requests match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table class="table">
    <thead>
      <tr><th>Reference</th><th>User</th><th>Amount</th><th>Method</th><th>Destination</th>
          <th>Status</th><th>Requested</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($payouts as $p): ?>
      <tr>
        <td><a class="mono text-xs" href="<?=site_url('admin/payouts/'.$p->public_id)?>"><?=htmlspecialchars($p->public_id)?></a></td>
        <td class="text-xs">
          <?php if (!empty($p->user_username)): ?>
            <div class="font-medium text-slate-900"><?=htmlspecialchars($p->user_username)?></div>
            <div class="muted"><?=htmlspecialchars((string)($p->user_email ?? ''))?></div>
          <?php else: ?>
            #<?=(int)$p->user_id?>
          <?php endif; ?>
        </td>
        <td class="mono"><?=marvy_money($p->amount, $p->currency ?? null)?></td>
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
          <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/payouts/'.$p->public_id)?>">View →</a>
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
            <form method="post" class="row" style="gap:.25rem;justify-content:flex-end;display:inline-flex"
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
  </div>

  <?php if (($total_pages ?? 1) > 1): ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/payouts'.$qs(array('page'=>max(1,$page-1))))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?> · <?=number_format($total)?> request(s)</span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/payouts'.$qs(array('page'=>min($total_pages,$page+1))))?>">Next →</a>
  </nav>
  <?php else: ?>
  <p class="muted text-xs mt-2"><?=number_format($total)?> request(s).</p>
  <?php endif; ?>
  <?php endif; ?>
</div>
