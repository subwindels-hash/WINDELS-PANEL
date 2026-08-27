<?php defined('BASEPATH') OR exit('No direct script access allowed');
$p = $payout;
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
    <a class="text-xs muted" href="<?=site_url('admin/payouts')?>">← Payouts</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      Withdrawal <span class="mono text-sm muted"><?=htmlspecialchars($p->public_id)?></span>
      <span class="badge <?=$badge($p->status)?>"><?=htmlspecialchars($p->status)?></span>
    </h2>
    <p class="muted text-sm">
      Requested by
      <a href="<?=site_url('admin/customers/'.htmlspecialchars($p->user_public_id ?? ''))?>">
        <?=htmlspecialchars((string)($p->user_username ?? ('user #'.$p->user_id)))?>
      </a>
      · <?=htmlspecialchars((string)($p->user_email ?? ''))?>
      · <?=htmlspecialchars(date('M j, Y H:i', strtotime($p->requested_at)))?> UTC
    </p>
  </div>
  <div class="text-right">
    <div class="muted text-xs">Amount requested</div>
    <div style="font-size:1.5rem;font-weight:600" class="mono"><?=marvy_money($p->amount, $p->currency)?></div>
  </div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.75rem" class="mb-4">
  <div class="card">
    <div class="muted text-xs">Method</div>
    <div class="font-medium"><?=htmlspecialchars(str_replace('_',' ', $p->method))?></div>
  </div>
  <div class="card">
    <div class="muted text-xs">Destination</div>
    <div class="font-medium mono text-sm"><?=htmlspecialchars((string)($p->destination ?: '—'))?></div>
    <?php if (!empty($p->destination_name)): ?>
      <div class="text-xs muted"><?=htmlspecialchars($p->destination_name)?></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="muted text-xs">Payment reference</div>
    <div class="font-medium mono text-sm"><?=htmlspecialchars((string)($p->payout_reference ?: '—'))?></div>
  </div>
  <div class="card">
    <div class="muted text-xs">Reviewed by</div>
    <div class="font-medium text-sm"><?=htmlspecialchars((string)($p->reviewer_username ?: '—'))?></div>
    <?php if (!empty($p->reviewed_at)): ?>
      <div class="text-xs muted"><?=htmlspecialchars(date('M j, Y H:i', strtotime($p->reviewed_at)))?> UTC</div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($p->review_note)): ?>
<div class="alert alert-info mb-4">
  <strong>Internal note:</strong> <?=nl2br(htmlspecialchars($p->review_note))?>
</div>
<?php endif; ?>

<div class="row" style="gap:.5rem;flex-wrap:wrap" class="mb-4">
  <?php if ($p->status === 'REQUESTED'): ?>
    <form method="post" action="<?=site_url('admin/payouts/'.$p->public_id.'/approve')?>" class="row" style="gap:.35rem">
      <?=$csrf()?>
      <input class="input" type="text" name="note" placeholder="Internal note (optional)" style="min-width:16rem">
      <button class="btn btn-primary btn-sm" type="submit">Approve</button>
    </form>
    <form method="post" action="<?=site_url('admin/payouts/'.$p->public_id.'/reject')?>" class="row" style="gap:.35rem">
      <?=$csrf()?>
      <input class="input" type="text" name="reason" placeholder="Reason for rejection" style="min-width:16rem">
      <button class="btn btn-secondary btn-sm" type="submit">Reject</button>
    </form>
  <?php elseif ($p->status === 'APPROVED'): ?>
    <form method="post" action="<?=site_url('admin/payouts/'.$p->public_id.'/paid')?>" class="row" style="gap:.35rem">
      <?=$csrf()?>
      <input class="input mono" type="text" name="reference" required placeholder="Bank/provider reference" style="min-width:16rem">
      <button class="btn btn-primary btn-sm" type="submit">Mark paid</button>
    </form>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-3">Earnings locked against this request</h3>
  <?php if (empty($earnings)): ?>
    <p class="muted text-sm">No earning rows are currently tied to this request.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Source</th><th>Description</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($earnings as $e): ?>
        <tr>
          <td class="text-xs"><?=htmlspecialchars(ucfirst(strtolower($e->source)))?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)$e->description)?></td>
          <td class="text-right mono"><?=marvy_money($e->amount, $e->currency)?></td>
          <td><span class="badge badge-default"><?=htmlspecialchars($e->status)?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="row" style="gap:.75rem;flex-wrap:wrap;align-items:flex-start">
  <div class="card" style="flex:1;min-width:20rem">
    <h3 style="font-size:1rem;font-weight:600" class="mb-3">This customer's affiliate balance</h3>
    <?php $b = $user_earnings_balance; ?>
    <dl class="stack" style="gap:.5rem">
      <div class="row justify-between"><span class="muted">Available</span><strong class="mono"><?=marvy_money($b['available'])?></strong></div>
      <div class="row justify-between"><span class="muted">Pending</span><strong class="mono"><?=marvy_money($b['pending'])?></strong></div>
      <div class="row justify-between"><span class="muted">Locked</span><strong class="mono"><?=marvy_money($b['locked'])?></strong></div>
      <div class="row justify-between"><span class="muted">Total earned</span><strong class="mono"><?=marvy_money($b['total_earned'])?></strong></div>
      <div class="row justify-between"><span class="muted">Paid out (lifetime)</span><strong class="mono"><?=marvy_money($b['paid'])?></strong></div>
    </dl>
  </div>
  <div class="card" style="flex:1;min-width:20rem">
    <h3 style="font-size:1rem;font-weight:600" class="mb-3">This customer's withdrawal history</h3>
    <?php if (empty($user_payout_history)): ?>
      <p class="muted text-sm">No other withdrawal requests.</p>
    <?php else: ?>
      <table class="table">
        <tbody>
        <?php foreach ($user_payout_history as $h): ?>
          <tr>
            <td class="text-xs muted whitespace-nowrap"><?=htmlspecialchars(date('M j, H:i', strtotime($h->requested_at)))?></td>
            <td class="text-right mono text-xs"><?=marvy_money($h->amount, $h->currency)?></td>
            <td><span class="badge <?=$badge($h->status)?>"><?=htmlspecialchars($h->status)?></span></td>
            <td><a class="text-xs" href="<?=site_url('admin/payouts/'.$h->public_id)?>">View →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
