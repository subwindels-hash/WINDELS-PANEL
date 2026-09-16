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

<div class="alert alert-info">
  <strong>Automatic rate updates are on.</strong>
  The <span class="mono">currency_rates</span> background job refreshes every row every hour
  through cron or the built-in site-traffic auto-run heartbeat — including NGN, which stays pinned
  at <span class="mono">1.00000000</span> but gets fresh source and timestamp metadata. Use
  <em>Update all currencies at once</em> to run that same locked job immediately; the boxes below stay
  available for emergency manual corrections (pause the job if a manual rate must hold).
  The NGN row has its own manual rate box like the others: type the naira's dollar value
  (USD per ₦1, e.g. <span class="mono">0.00075300</span> ≈ ₦1,328/$) and press Update rate. The
  naira's stored base rate itself remains the pinned <span class="mono">1.00000000</span>.
</div>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Accounting currency</h3>
  <p class="muted text-xs mb-3">
    Every wallet, order, payment, earning and payout is denominated in this currency. To redenominate the
    panel and convert stored amounts at the current exchange rate, change it in
    <a href="<?=site_url('admin/settings')?>">Admin → Settings</a>.
  </p>
  <div class="row" style="gap:1.5rem;flex-wrap:wrap">
    <div><div class="muted text-xs">Base currency</div><div class="mono font-medium"><?=htmlspecialchars($base_currency)?></div></div>
    <div><div class="muted text-xs">Default display currency</div><div class="mono font-medium"><?=htmlspecialchars($display_currency)?></div></div>
    <div><div class="muted text-xs">Display format</div><div class="font-medium"><?=htmlspecialchars(ucfirst($currency_display_format))?>
      <span class="text-xs muted">(<a href="<?=site_url('admin/settings')?>">change in Settings</a>)</span></div></div>
  </div>
</div>

<div class="card">
  <div class="row justify-between mb-3" style="align-items:center;gap:.75rem">
    <h3 style="font-size:1rem;font-weight:600" class="mb-0">Supported currencies</h3>
    <form method="post" action="<?=site_url('admin/currencies/update-all')?>" style="display:inline">
      <?=$csrf()?>
      <button class="btn btn-primary btn-sm" type="submit"
              data-confirm="Update exchange rates for every currency now? This refreshes all rows, including NGN.">
        Update all currencies at once
      </button>
    </form>
  </div>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr>
        <th>Currency</th><th>Symbol</th><th>Decimals</th><th class="text-right">Rate (per 1 <?=htmlspecialchars($base_currency)?>)</th>
        <th>Source</th><th>Last updated</th><th>Status</th><th>Default</th><th></th>
      </tr></thead>
      <tbody>
      <?php
      // The base row (NGN) gets a manual rate box like every other currency.
      // Its own stored rate is pinned at 1.0 (it is the base), so the box
      // edits the base currency's market value — units of the quote currency
      // (USD where available) per 1 NGN, e.g. 0.00075300 ≈ ₦1,328/$. Find the
      // row that holds that number once.
      $quote_row = null;
      foreach ($currencies as $c) {
          if (strtoupper($c->code) === 'USD' && (int)$c->is_base !== 1) { $quote_row = $c; break; }
      }
      if ($quote_row === null) {
          foreach ($currencies as $c) {
              if ((int)$c->is_base !== 1) { $quote_row = $c; break; }
          }
      }
      ?>
      <?php foreach ($currencies as $c): $is_base = (int)$c->is_base === 1; $is_default = strtoupper($c->code) === strtoupper($display_currency); ?>
        <tr>
          <td><strong class="mono"><?=htmlspecialchars($c->code)?></strong> <span class="text-xs muted"><?=htmlspecialchars($c->name)?></span></td>
          <td class="mono"><?=htmlspecialchars($c->symbol)?></td>
          <td class="mono text-xs"><?=(int)$c->decimal_precision?></td>
          <td class="text-right mono">
            <?php if ($is_base): ?>
              1.00000000 <span class="badge badge-brand">base</span>
              <?php if ($quote_row !== null): ?>
                <div class="text-xs muted">
                  <?=htmlspecialchars(number_format((float)$quote_row->exchange_rate, 8))?>
                  <?=htmlspecialchars($quote_row->code)?>/<?=htmlspecialchars($c->code)?>
                </div>
              <?php endif; ?>
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
            <form method="post" action="<?=site_url('admin/currencies/rate')?>" class="currency-rate-form">
              <?=$csrf()?>
              <input type="hidden" name="code" value="<?=htmlspecialchars($c->code)?>">
              <label class="sr-only" for="rate-<?=htmlspecialchars($c->code)?>">Rate for <?=htmlspecialchars($c->code)?></label>
              <input id="rate-<?=htmlspecialchars($c->code)?>" class="input mono currency-rate-input" type="number"
                     step="0.00000001" min="0.00000001" inputmode="decimal" name="rate"
                     value="<?=htmlspecialchars((string)$c->exchange_rate)?>" required>
              <button class="btn btn-primary btn-sm currency-rate-button" type="submit">Update rate</button>
            </form>
            <?php elseif ($quote_row !== null): ?>
            <?php /* The naira's own stored rate is pinned at 1.0 (it is the base), but
                     its market value is editable right here, like every other row. The
                     box posts to admin/currencies/base-rate, which records the value as
                     units of the quote currency per ₦1, e.g. 0.00075300 ≈ ₦1,328/$. */ ?>
            <form method="post" action="<?=site_url('admin/currencies/base-rate')?>" class="currency-rate-form">
              <?=$csrf()?>
              <label class="sr-only" for="rate-<?=htmlspecialchars($c->code)?>">Rate for <?=htmlspecialchars($c->code)?></label>
              <input id="rate-<?=htmlspecialchars($c->code)?>" class="input mono currency-rate-input" type="number"
                     step="0.00000001" min="0.00000001" inputmode="decimal" name="rate"
                     value="<?=htmlspecialchars((string)$quote_row->exchange_rate)?>" required
                     title="<?=htmlspecialchars($quote_row->code)?> per 1 <?=htmlspecialchars($c->code)?> — the naira's market value">
              <button class="btn btn-primary btn-sm currency-rate-button" type="submit">Update rate</button>
            </form>
            <div class="text-xs muted mt-1">
              <?=htmlspecialchars($quote_row->code)?> per 1 <?=htmlspecialchars($c->code)?> — the naira's market value
              (also shown on the <?=htmlspecialchars($quote_row->code)?> row).
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted text-xs mt-3">
    Automatic and manual exchange-rate changes are both recorded with who/what set the rate, when it changed,
    and its source. The stored rate is always units of that currency per 1 <?=htmlspecialchars($base_currency)?>;
    <?=htmlspecialchars($base_currency)?> itself is refreshed as an audited 1.00000000 base row.
  </p>
</div>
