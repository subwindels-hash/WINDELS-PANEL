<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };

// Where each domain's queue lives, so a row in the table is a way in.
$queues = array(
    'SMM'      => array('admin/orders',    'orders.view'),
    'VTU'      => array('admin/vtu',       'vtu.view'),
    'NUMBER'   => array('admin/numbers',   'numbers.view'),
    'IDENTITY' => array('admin/identity',  'identity.view'),
    'GIFTCARD' => array('admin/giftcards', 'giftcards.view'),
);
$labels = array('SMM'=>'SMM orders','VTU'=>'VTU','NUMBER'=>'Virtual numbers',
                'IDENTITY'=>'Identity checks','GIFTCARD'=>'Gift cards');

// The sparkline is scaled to its own maximum; an all-zero fortnight must not
// divide by zero, and must not render as a full-height bar either.
$peak = '0';
foreach ($series as $point) { if (bccomp($point['net'], $peak, 8) > 0) $peak = $point['net']; }
$has_series = bccomp($peak, '0', 8) > 0;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Analytics</h2>
    <p class="muted text-sm">Every domain the panel sells, last <?=(int)$days?> days</p>
  </div>
  <div class="row" style="gap:.35rem">
    <?php foreach ($ranges as $r): ?>
      <a class="btn btn-sm <?=$days === $r ? 'btn-primary' : 'btn-ghost'?>"
         href="<?=site_url('admin/analytics?days='.$r)?>"><?=(int)$r?> days</a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid grid-4 mb-4" style="gap:1rem">
  <div class="card">
    <div class="muted text-sm">Net revenue</div>
    <div class="text-2xl font-bold"><?=marvy_money($summary['net'])?></div>
    <div class="hint"><?=number_format((int)$summary['orders'])?> sale<?=$summary['orders'] == 1 ? '' : 's'?></div>
  </div>
  <div class="card">
    <div class="muted text-sm">Gross</div>
    <div class="text-2xl font-bold"><?=marvy_money($summary['gross'])?></div>
    <div class="hint"><?=marvy_money($summary['refunded'])?> refunded</div>
  </div>
  <div class="card">
    <div class="muted text-sm">Margin</div>
    <div class="text-2xl font-bold">
      <?=$totals['margin'] === null ? '—' : marvy_money($totals['margin'])?>
    </div>
    <div class="hint">
      <?php if ($totals['margin'] === null): ?>
        no vendor costs recorded
      <?php else: ?>
        over <?=number_format((int)$totals['costed'])?> of <?=number_format((int)$totals['sales'])?> sales
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="muted text-sm">Sales mix</div>
    <div class="text-2xl font-bold"><?=number_format((int)$summary['services'])?></div>
    <div class="hint">service sales · <?=number_format((int)$summary['smm'])?> SMM</div>
  </div>
</div>

<div class="card mb-4">
  <h3 class="text-sm font-semibold mb-2">Net revenue · last 14 days</h3>
  <?php if (!$has_series): ?>
    <p class="muted text-sm">No sales in the last 14 days.</p>
  <?php else: ?>
    <div class="row" style="gap:2px;align-items:flex-end;height:90px">
      <?php foreach ($series as $day => $point):
        $pct = (float)bcdiv(bcmul($point['net'], '100', 8), $peak, 4);
        $h   = max(2, (int)round($pct));
      ?>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%"
             title="<?=htmlspecialchars($day)?> — <?=htmlspecialchars(marvy_money($point['net']))?> from <?=(int)$point['sales']?> sale(s)">
          <div style="height:<?=$h?>%;background:var(--brand-500,#6366f1);border-radius:2px 2px 0 0"></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row justify-between mt-2">
      <span class="text-xs muted"><?=htmlspecialchars((string)array_key_first($series))?></span>
      <span class="text-xs muted">peak <?=marvy_money($peak)?></span>
      <span class="text-xs muted"><?=htmlspecialchars((string)array_key_last($series))?></span>
    </div>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <h3 class="text-sm font-semibold mb-2">By domain</h3>
  <?php if (empty($domains)): ?>
    <p class="muted text-sm">Nothing sold in this window.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Domain</th><th class="text-right">Sales</th><th class="text-right">Gross</th>
            <th class="text-right">Refunded</th><th class="text-right">Net</th>
            <th class="text-right">Vendor cost</th><th class="text-right">Margin</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($domains as $domain => $d):
        $queue = $queues[$domain] ?? null; ?>
        <tr>
          <td><strong><?=htmlspecialchars($labels[$domain] ?? $domain)?></strong></td>
          <td class="text-right mono"><?=number_format((int)$d['sales'])?></td>
          <td class="text-right mono"><?=marvy_money($d['gross'])?></td>
          <td class="text-right mono <?=bccomp($d['refunded'], '0', 8) > 0 ? '' : 'muted'?>">
            <?=marvy_money($d['refunded'])?></td>
          <td class="text-right mono font-semibold"><?=marvy_money($d['net'])?></td>
          <td class="text-right mono muted">
            <?=(int)$d['costed'] > 0 ? marvy_money($d['cost']) : '—'?>
          </td>
          <td class="text-right mono">
            <?php if ($d['margin'] === null): ?>
              <span class="muted" title="This vendor does not report a per-sale cost">—</span>
            <?php else: ?>
              <?=marvy_money($d['margin'])?>
              <?php if ((int)$d['costed'] < (int)$d['sales']): ?>
                <div class="text-xs muted"><?=number_format((int)$d['costed'])?> of <?=number_format((int)$d['sales'])?> costed</div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="text-right">
            <?php if ($queue && $has($queue[1])): ?>
              <a class="text-xs" href="<?=site_url($queue[0])?>">queue →</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th>Total</th>
          <th class="text-right mono"><?=number_format((int)$totals['sales'])?></th>
          <th class="text-right mono"><?=marvy_money($totals['gross'])?></th>
          <th class="text-right mono"><?=marvy_money($totals['refunded'])?></th>
          <th class="text-right mono"><?=marvy_money($totals['net'])?></th>
          <th></th>
          <th class="text-right mono">
            <?=$totals['margin'] === null ? '—' : marvy_money($totals['margin'])?>
          </th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  </div>
  <p class="hint mt-2">
    Margin is only counted where a vendor cost was recorded. Some vendors bill a
    prepaid wallet rather than per sale, and a foreign-currency cost that could
    not be converted is stored as unknown rather than guessed — so a margin over
    a small "costed" count is not the whole picture.
  </p>
  <?php endif; ?>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Delivery health</h3>
    <?php if (empty($health)): ?>
      <p class="muted text-sm">No service transactions yet.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr><th>Domain</th><th class="text-right">In flight</th>
                   <th class="text-right">Stuck</th><th class="text-right">Success</th></tr></thead>
        <tbody>
        <?php foreach ($health as $domain => $h): ?>
          <tr>
            <td><?=htmlspecialchars($labels[$domain] ?? $domain)?></td>
            <td class="text-right mono <?=(int)$h['in_flight'] > 0 ? '' : 'muted'?>">
              <?=number_format((int)$h['in_flight'])?></td>
            <td class="text-right mono">
              <?php if ((int)$h['stuck'] > 0): ?>
                <span class="badge badge-warning"><?=number_format((int)$h['stuck'])?></span>
              <?php else: ?><span class="muted">0</span><?php endif; ?>
            </td>
            <td class="text-right mono">
              <?php if ($h['success_rate'] === null): ?>
                <span class="muted">—</span>
              <?php else: ?>
                <span class="badge <?=$h['success_rate'] >= 95 ? 'badge-success' : ($h['success_rate'] >= 80 ? 'badge-warning' : 'badge-danger')?>">
                  <?=htmlspecialchars((string)$h['success_rate'])?>%</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint mt-2">
      "Stuck" means paid and still processing after 30 minutes — a customer
      waiting for something they have already been charged for. Success rate
      counts only purchases that reached an outcome, so a busy minute does not
      read as an outage.
    </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Vendor reliability</h3>
    <?php if (empty($providers)): ?>
      <p class="muted text-sm">No vendor calls recorded in this window.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr><th>Vendor</th><th class="text-right">Calls</th>
                   <th class="text-right">Success</th><th class="text-right">Avg latency</th></tr></thead>
        <tbody>
        <?php foreach ($providers as $p): ?>
          <tr>
            <td>
              <?=htmlspecialchars($p['provider'])?>
              <?php if ($p['api_type'] !== ''): ?>
                <div class="mono text-xs muted"><?=htmlspecialchars($p['api_type'])?></div>
              <?php endif; ?>
            </td>
            <td class="text-right mono"><?=number_format((int)$p['calls'])?></td>
            <td class="text-right">
              <span class="badge <?=$p['success_rate'] >= 95 ? 'badge-success' : ($p['success_rate'] >= 80 ? 'badge-warning' : 'badge-danger')?>">
                <?=htmlspecialchars((string)$p['success_rate'])?>%</span>
              <?php if ((int)$p['failed'] > 0): ?>
                <div class="text-xs muted"><?=number_format((int)$p['failed'])?> failed</div>
              <?php endif; ?>
            </td>
            <td class="text-right mono"><?=number_format((int)$p['avg_latency'])?> ms</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint mt-2">
      Read from the vendor call log, worst first — this is what happened on the
      wire, independent of whether the customer was refunded afterwards.
    </p>
    <?php endif; ?>
  </div>
</div>
