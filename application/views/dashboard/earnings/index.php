<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * The customer earnings wallet.
 *
 * Pending and available are shown as separate figures and never summed into a
 * single "balance": one is money you have, the other is money you will have,
 * and merging them is how a user ends up believing they can withdraw something
 * they cannot.
 */
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
$b = $balance;
$src = $by_source;
$payout_badge = function ($s) {
    $map = array('REQUESTED'=>'badge-warning','APPROVED'=>'badge-info',
                 'PAID'=>'badge-success','REJECTED'=>'badge-danger','CANCELLED'=>'badge-default');
    return $map[$s] ?? 'badge-default';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Earnings</h2>
    <p class="muted text-sm">
      Money you have earned from referrals and promotions. Separate from your wallet balance,
      which is what you top up to spend on services.
    </p>
  </div>
</div>

<div class="grid grid-4 mb-4">
  <div class="card">
    <div class="muted text-xs">Available</div>
    <div class="mono" style="font-size:1.5rem;font-weight:600"><?=marvy_money($b['available'])?></div>
    <div class="muted text-xs">Ready to withdraw or spend</div>
  </div>
  <div class="card">
    <div class="muted text-xs">Pending</div>
    <div class="mono" style="font-size:1.5rem;font-weight:600"><?=marvy_money($b['pending'])?></div>
    <div class="muted text-xs">Inside the holding period</div>
  </div>
  <div class="card">
    <div class="muted text-xs">Locked</div>
    <div class="mono" style="font-size:1.5rem;font-weight:600"><?=marvy_money($b['locked'])?></div>
    <div class="muted text-xs">Held against a payout request</div>
  </div>
  <div class="card">
    <div class="muted text-xs">Total earned</div>
    <div class="mono" style="font-size:1.5rem;font-weight:600"><?=marvy_money($b['total_earned'])?></div>
    <div class="muted text-xs">Withdrawn: <?=marvy_money($b['paid'])?></div>
  </div>
</div>

<div class="grid" style="grid-template-columns:1.4fr 1fr;gap:1rem;align-items:start">
  <div class="card">
    <h3 style="font-size:1rem;font-weight:600" class="mb-2">Your referral link</h3>
    <p class="muted text-xs mb-2">
      Share this link. You earn when someone who signs up through it completes the qualifying activity.
    </p>
    <input class="input mono mb-2" type="text" readonly
           value="<?=htmlspecialchars($referral['link'])?>"
           data-select-on-click aria-label="Your referral link">
    <div class="row" style="gap:1.25rem;flex-wrap:wrap">
      <div><span class="muted text-xs">Code</span><br><strong class="mono"><?=htmlspecialchars($referral['code'])?></strong></div>
      <div><span class="muted text-xs">Clicks</span><br><strong><?=number_format($referral['visits'])?></strong></div>
      <div><span class="muted text-xs">Sign-ups</span><br><strong><?=number_format($referral['signups'])?></strong></div>
      <div><span class="muted text-xs">Qualified</span><br><strong><?=number_format($referral['qualified'])?></strong></div>
    </div>

    <?php if ($src): ?>
    <h3 style="font-size:1rem;font-weight:600" class="mt-4 mb-2">Where it came from</h3>
    <table class="table">
      <tbody>
        <?php foreach ($src as $source => $total): ?>
        <tr>
          <td><?=htmlspecialchars(ucfirst(strtolower(str_replace('_', ' ', $source))))?></td>
          <td class="mono text-right"><?=marvy_money($total)?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="font-size:1rem;font-weight:600" class="mb-2">Withdraw</h3>

    <?php if (bccomp((string)$b['available'], '0', 8) <= 0): ?>
      <p class="muted text-sm">
        You have nothing available yet. Earnings become available after the holding period.
      </p>
    <?php else: ?>
      <form method="post" action="<?=site_url('dashboard/earnings/withdraw')?>" class="stack">
        <?=$csrf()?>
        <label class="field">
          <span class="label">Amount</span>
          <input class="input mono" type="number" name="amount" step="0.01" min="0"
                 max="<?=htmlspecialchars($b['available'])?>" required
                 placeholder="<?=htmlspecialchars($b['available'])?>">
          <span class="hint">Available: <?=marvy_money($b['available'])?></span>
        </label>

        <label class="field">
          <span class="label">Method</span>
          <select class="select" name="method">
            <option value="WALLET_CREDIT">Add to my wallet balance (instant)</option>
            <?php if (!empty($payouts_enabled)): ?>
              <option value="BANK_TRANSFER">Bank transfer (reviewed by staff)</option>
            <?php endif; ?>
          </select>
        </label>

        <?php if (!empty($payouts_enabled)): ?>
        <label class="field">
          <span class="label">Bank account <span class="muted">(bank transfers only)</span></span>
          <input class="input" type="text" name="destination" maxlength="255"
                 placeholder="Bank name and account number">
        </label>
        <label class="field">
          <span class="label">Account name</span>
          <input class="input" type="text" name="destination_name" maxlength="160">
        </label>
        <p class="muted text-xs">Minimum bank payout: <?=marvy_money($min_payout)?>.</p>
        <?php else: ?>
        <p class="muted text-xs">
          Cash payouts are not currently open. You can convert earnings into wallet credit and spend
          them on any service.
        </p>
        <?php endif; ?>

        <div><button class="btn btn-primary" type="submit">Request</button></div>
      </form>
    <?php endif; ?>

    <?php if ($payouts): ?>
    <h4 class="mt-4 mb-2" style="font-size:.9rem;font-weight:600">Recent requests</h4>
    <table class="table">
      <tbody>
        <?php foreach ($payouts as $p): ?>
        <tr>
          <td class="mono text-xs"><?=marvy_money($p->amount)?></td>
          <td><span class="badge <?=$payout_badge($p->status)?>"><?=htmlspecialchars($p->status)?></span>
            <?php if ($p->status === 'REJECTED' && !empty($p->review_note)): ?>
              <div class="text-xs muted"><?=htmlspecialchars($p->review_note)?></div>
            <?php endif; ?>
          </td>
          <td class="text-right">
            <?php if ($p->status === 'REQUESTED'): ?>
            <form method="post" style="margin:0"
                  action="<?=site_url('dashboard/earnings/payouts/'.$p->public_id.'/cancel')?>">
              <?=$csrf()?>
              <button class="btn btn-secondary btn-sm" type="submit">Cancel</button>
            </form>
            <?php elseif ($p->payout_reference): ?>
              <span class="mono text-xs muted"><?=htmlspecialchars($p->payout_reference)?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-4">
  <div class="row justify-between mb-2">
    <h3 style="font-size:1rem;font-weight:600" class="mb-0">Recent earnings</h3>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/earnings/history')?>">Full history →</a>
  </div>
  <?php if (!$recent): ?>
    <p class="muted text-sm">No earnings yet. Share your referral link to get started.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Source</th><th>Description</th><th>Status</th><th class="text-right">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $e): ?>
      <tr>
        <td class="text-xs"><?=htmlspecialchars(date('j M Y', strtotime($e->created_at)))?></td>
        <td><?=htmlspecialchars(ucfirst(strtolower($e->source)))?></td>
        <td class="text-xs muted"><?=htmlspecialchars((string)$e->description)?></td>
        <td><span class="badge badge-default"><?=htmlspecialchars($e->status)?></span></td>
        <td class="mono text-right"><?=marvy_money($e->amount)?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
