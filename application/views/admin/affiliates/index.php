<?php defined('BASEPATH') OR exit('No direct script access allowed');
$can_manage = in_array('*', $permissions ?? array(), true) || in_array('affiliates.manage', $permissions ?? array(), true);
$fmt = function ($v) { return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); };
?>
<div class="row justify-between" style="margin-bottom:1rem;align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Affiliate program</h2>
    <p class="muted text-sm">Default <?=htmlspecialchars($fmt($settings['percent']))?>% ·
      <?=(int)$settings['hold_hours']?>h hold ·
      min payout <?=windels_money($settings['min_payout'])?> ·
      scope <?=htmlspecialchars($settings['scope'])?>
      <?php if (empty($settings['enabled'])): ?><span class="badge badge-warning">disabled</span><?php endif; ?>
    </p>
  </div>
  <?php if ($can_manage): ?>
  <form method="post" action="<?=site_url('admin/affiliates/payout')?>"
        onsubmit="return confirm('Pay all commissions that have cleared the hold window?')">
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
    <button class="btn btn-primary" type="submit">Run payout now</button>
  </form>
  <?php endif; ?>
</div>

<div class="grid grid-3 mb-4" style="gap:1rem">
  <div class="card">
    <div class="muted text-sm">Affiliates / referred users</div>
    <div class="text-2xl font-bold"><?=number_format((int)$totals['accounts'])?> / <?=number_format((int)$totals['referrals'])?></div>
  </div>
  <div class="card">
    <div class="muted text-sm">Pending commissions</div>
    <div class="text-2xl font-bold"><?=windels_money($totals['pending'])?></div>
    <div class="hint"><?=number_format((int)$totals['commissions'])?> rows accrued</div>
  </div>
  <div class="card">
    <div class="muted text-sm">Paid out</div>
    <div class="text-2xl font-bold"><?=windels_money($totals['paid'])?></div>
    <div class="hint">of <?=windels_money($totals['accrued'])?> accrued</div>
  </div>
</div>

<div class="card">
  <?php if (empty($accounts)): ?>
    <p class="muted">No affiliate accounts yet. One is created the first time a customer opens their referrals page or refers someone.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Affiliate</th><th>Code</th><th>Rate</th><th>Referred</th>
            <th class="text-right">Earned</th><th class="text-right">Paid</th><?php if ($can_manage): ?><th></th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($accounts as $a): ?>
        <tr>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars($a->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars($a->email)?></div>
          </td>
          <td class="mono text-xs"><?=htmlspecialchars($a->code)?></td>
          <td class="mono"><?=htmlspecialchars($fmt($a->commission_percent))?>%</td>
          <td><?=number_format((int)$a->total_referred)?></td>
          <td class="text-right mono"><?=windels_money($a->total_earned)?></td>
          <td class="text-right mono muted"><?=windels_money($a->total_paid)?></td>
          <?php if ($can_manage): ?>
          <td>
            <form method="post" action="<?=site_url('admin/affiliates/'.(int)$a->id.'/rate')?>" class="row" style="gap:.35rem">
              <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
              <input class="input" name="commission_percent" style="width:6rem" inputmode="decimal"
                     value="<?=htmlspecialchars($fmt($a->commission_percent))?>" aria-label="Commission percent">
              <button class="btn btn-ghost btn-sm" type="submit">Save</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>" href="<?=site_url('admin/affiliates?page='.max(1, $page-1))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>" href="<?=site_url('admin/affiliates?page='.min($total_pages, $page+1))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
