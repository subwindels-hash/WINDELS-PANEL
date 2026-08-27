<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Currencies</h2>
    <p class="muted text-sm">
      Control which currencies customers can browse the catalogue in, the default, and exchange rates.
      This does not change what customers pay with — checkout still settles in the accounting currency below.
    </p>
  </div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/settings')?>">← Settings</a>
</div>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Accounting currency — fixed by design</h3>
  <p class="muted text-xs mb-3">
    Every wallet, order, payment, earning and payout is denominated in this currency. Changing it would
    reinterpret every stored amount, so it moves by migration only.
  </p>
  <div class="row" style="gap:1.5rem;flex-wrap:wrap">
    <div><div class="muted text-xs">Base currency</div><div class="mono font-medium"><?=htmlspecialchars($base_currency)?></div></div>
    <div><div class="muted text-xs">Default display currency</div><div class="mono font-medium"><?=htmlspecialchars($display_currency)?></div></div>
    <div><div class="muted text-xs">Display format</div><div class="font-medium"><?=htmlspecialchars(ucfirst($currency_display_format))?>
      <span class="text-xs muted">(<a href="<?=site_url('admin/settings')?>">change in Settings</a>)</span></div></div>
  </div>
</div>

<div class="card">
  <h3 style="font-size:1rem;font-weight:600" class="mb-3">Supported currencies</h3>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr>
        <th>Currency</th><th>Symbol</th><th>Decimals</th><th class="text-right">Rate (per 1 <?=htmlspecialchars($base_currency)?>)</th>
        <th>Source</th><th>Last updated</th><th>Status</th><th>Default</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($currencies as $c): $is_base = (int)$c->is_base === 1; $is_default = strtoupper($c->code) === strtoupper($display_currency); ?>
        <tr>
          <td><strong class="mono"><?=htmlspecialchars($c->code)?></strong> <span class="text-xs muted"><?=htmlspecialchars($c->name)?></span></td>
          <td class="mono"><?=htmlspecialchars($c->symbol)?></td>
          <td class="mono text-xs"><?=(int)$c->decimal_precision?></td>
          <td class="text-right mono">
            <?php if ($is_base): ?>
              1.00000000 <span class="badge badge-brand">base</span>
            <?php else: ?>
              <?=htmlspecialchars(number_format((float)$c->exchange_rate, 8))?>
            <?php endif; ?>
          </td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($c->rate_source ?: '—'))?></td>
          <td class="text-xs muted">
            <?php if (!empty($c->rate_updated_at)): ?>
              <?=htmlspecialchars(date('M j, Y H:i', strtotime($c->rate_updated_at)))?> UTC
              <?php if (!empty($c->rate_updated_by_username)): ?>
                <div>by <?=htmlspecialchars($c->rate_updated_by_username)?></div>
              <?php endif; ?>
              <?php if (!empty($c->rate_effective_at)): ?>
                <div>effective <?=htmlspecialchars(date('M j, Y', strtotime($c->rate_effective_at)))?></div>
              <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php if ($is_base): ?>
              <span class="badge badge-success badge-dot">Active</span>
            <?php elseif ((int)$c->is_active === 1): ?>
              <form method="post" action="<?=site_url('admin/currencies/active')?>" style="display:inline">
                <?=$csrf()?>
                <input type="hidden" name="code" value="<?=htmlspecialchars($c->code)?>">
                <button class="badge badge-success badge-dot" type="submit" style="border:0;cursor:pointer" title="Click to disable">Active</button>
              </form>
            <?php else: ?>
              <form method="post" action="<?=site_url('admin/currencies/active')?>" style="display:inline">
                <?=$csrf()?>
                <input type="hidden" name="code" value="<?=htmlspecialchars($c->code)?>">
                <input type="hidden" name="active" value="1">
                <button class="badge badge-default" type="submit" style="border:0;cursor:pointer" title="Click to enable">Disabled</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($is_default): ?>
              <span class="badge badge-brand">Default</span>
            <?php elseif ((int)$c->is_active === 1): ?>
              <form method="post" action="<?=site_url('admin/currencies/default')?>" style="display:inline">
                <?=$csrf()?>
                <input type="hidden" name="code" value="<?=htmlspecialchars($c->code)?>">
                <button class="btn btn-ghost btn-sm" type="submit">Make default</button>
              </form>
            <?php else: ?>
              <span class="muted text-xs">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$is_base): ?>
            <form method="post" action="<?=site_url('admin/currencies/rate')?>" class="row" style="gap:.25rem;flex-wrap:nowrap">
              <?=$csrf()?>
              <input type="hidden" name="code" value="<?=htmlspecialchars($c->code)?>">
              <input class="input mono" type="number" step="0.00000001" min="0.00000001" name="rate"
                     value="<?=htmlspecialchars((string)$c->exchange_rate)?>" style="width:9rem" required>
              <button class="btn btn-secondary btn-sm" type="submit">Update rate</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted text-xs mt-3">
    Exchange rates are manual today. Each is recorded with who set it, when, and its source, so a rate can
    always be traced back to a decision an operator made — never a silent default.
  </p>
</div>
