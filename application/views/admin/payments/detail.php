<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$can_manage = in_array('*', $perms, true) || in_array('payments.manage', $perms, true);
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$badge = function ($s) {
    $map = array('SUCCESS'=>'badge-success','PENDING'=>'badge-warning','CREATED'=>'badge-default','FAILED'=>'badge-danger');
    return 'badge '.($map[$s] ?? 'badge-default');
};
$actionable = in_array($tx->status, array('CREATED','PENDING'), true);
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/payments')?>">← All payments</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      Deposit <span class="mono"><?=htmlspecialchars($tx->public_id)?></span>
    </h2>
    <p class="muted text-sm">
      <span class="<?=$badge($tx->status)?>"><?=htmlspecialchars($tx->status)?></span>
      created <?=htmlspecialchars((string)$tx->created_at)?>
    </p>
  </div>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Deposit</h3>
    <table class="table">
      <tbody>
        <tr><th>Customer</th><td><?=htmlspecialchars((string)$tx->username)?>
            <span class="muted text-xs"><?=htmlspecialchars((string)$tx->email)?></span></td></tr>
        <tr><th>Method</th><td><?=htmlspecialchars((string)$tx->method_name)?>
            <span class="badge badge-default"><?=htmlspecialchars((string)$tx->method_type)?></span></td></tr>
        <tr><th>Amount paid</th><td class="mono"><?=marvy_money($tx->amount)?> <?=htmlspecialchars($tx->currency)?></td></tr>
        <tr><th>Fee</th><td class="mono"><?=marvy_money($tx->fee)?></td></tr>
        <?php if (bccomp((string)$tx->bonus, '0', 8) > 0): ?>
        <tr><th>Bonus</th><td class="mono"><?=marvy_money($tx->bonus)?></td></tr>
        <?php endif; ?>
        <tr><th>Credits to wallet</th><td class="mono font-bold"><?=marvy_money($tx->credited_amount ?? $tx->amount)?></td></tr>
        <?php if ($tx->provider_tx_id): ?>
        <tr><th>Reference</th><td class="mono text-xs"><?=htmlspecialchars($tx->provider_tx_id)?></td></tr>
        <?php endif; ?>
        <?php if ($tx->verified_at): ?>
        <tr><th>Verified</th><td class="text-xs"><?=htmlspecialchars((string)$tx->verified_at)?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Review</h3>
    <?php if (!$can_manage): ?>
      <p class="muted text-sm">You have read-only access to payments.</p>
    <?php elseif ($tx->status === 'SUCCESS'): ?>
      <div class="alert alert-success">
        Credited <?=marvy_money($tx->credited_amount ?? $tx->amount)?> to this customer's wallet.
        Reverse it with a wallet adjustment rather than re-approving.
      </div>
    <?php elseif (!$actionable): ?>
      <div class="alert alert-warning">This deposit is <?=htmlspecialchars($tx->status)?> and cannot be actioned.</div>
    <?php else: ?>
      <p class="text-sm muted mb-2">
        Approving credits <strong><?=marvy_money($tx->credited_amount ?? $tx->amount)?></strong>
        to <?=htmlspecialchars((string)$tx->username)?>'s wallet through the ledger. Confirm the funds
        have actually arrived first — this cannot be undone from here.
      </p>
      <form method="post" action="<?=site_url('admin/payments/'.$tx->public_id.'/approve')?>" class="mb-4"
            onsubmit="return confirm('Credit <?=htmlspecialchars(marvy_money($tx->credited_amount ?? $tx->amount))?> to this wallet?')">
        <?=$csrf()?>
        <input class="input mb-2" name="provider_tx_id" placeholder="Bank reference (optional)"
               value="<?=htmlspecialchars((string)$tx->provider_tx_id)?>">
        <button class="btn btn-primary btn-sm" type="submit">Approve &amp; credit wallet</button>
      </form>

      <form method="post" action="<?=site_url('admin/payments/'.$tx->public_id.'/reject')?>"
            onsubmit="return confirm('Reject this deposit? Nothing will be credited.')">
        <?=$csrf()?>
        <input class="input mb-2" name="reason" placeholder="Rejection reason">
        <button class="btn btn-secondary btn-sm" type="submit">Reject</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-4">
  <h3 class="text-sm font-semibold mb-2">Event log</h3>
  <?php if (empty($events)): ?>
    <p class="muted text-sm">No events recorded yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>When</th><th>From</th><th>To</th><th>Source</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($events as $e): ?>
        <tr>
          <td class="text-xs muted"><?=htmlspecialchars((string)$e->created_at)?></td>
          <td class="text-xs"><?=htmlspecialchars((string)($e->from_status ?? '—'))?></td>
          <td><span class="<?=$badge($e->to_status)?>"><?=htmlspecialchars($e->to_status)?></span></td>
          <td class="text-xs"><?=htmlspecialchars((string)$e->source)?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($e->reason ?? ''))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
