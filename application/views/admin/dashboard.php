<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$healthy = (int)($health['HEALTHY'] ?? 0);
?>
<div class="mb-4">
  <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Overview</h2>
  <p class="muted text-sm">Signed in as <?=htmlspecialchars($current_user->username)?> (<?=htmlspecialchars($current_user->role)?>)</p>
</div>

<div class="grid grid-4 mb-4" style="gap:1rem">
  <div class="card">
    <div class="muted text-sm">Net revenue today</div>
    <div class="text-2xl font-bold"><?=marvy_money($today['net'])?></div>
    <div class="hint"><?=number_format((int)$today['orders'])?> order<?=$today['orders'] == 1 ? '' : 's'?>
      <?php if (bccomp((string)$today['refunded'], '0', 8) > 0): ?>
        · <?=marvy_money($today['refunded'])?> refunded
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="muted text-sm">Net revenue · 30 days</div>
    <div class="text-2xl font-bold"><?=marvy_money($month['net'])?></div>
    <div class="hint"><?=number_format((int)$month['orders'])?> orders</div>
  </div>
  <div class="card">
    <div class="muted text-sm">Customers</div>
    <div class="text-2xl font-bold"><?=number_format((int)$customers['active'])?></div>
    <div class="hint"><?=number_format((int)$customers['new_30d'])?> new in 30 days</div>
  </div>
  <div class="card">
    <div class="muted text-sm">Provider health</div>
    <div class="text-2xl font-bold"><?=$healthy?> / <?=(int)($health['total'] ?? 0)?></div>
    <div class="hint">healthy and active</div>
  </div>
</div>

<div class="card mb-4">
  <h3 class="text-sm font-semibold mb-2">Needs attention</h3>
  <div class="grid grid-4" style="gap:1rem">
    <?php
    $items = array(
      array('deposits',           'Deposits awaiting review', 'admin/payments?status=PENDING', 'payments.view'),
      array('tickets',            'Open tickets',             'admin/tickets?status=OPEN',     'tickets.view'),
      array('unassigned_tickets', 'Unassigned tickets',       'admin/tickets?unassigned=1',    'tickets.view'),
      array('stuck_orders',       'Orders pending >24h',      'admin/orders?status=PENDING',   'orders.view'),
      // A far tighter window than the SMM one, because these domains settle in
      // seconds: a gift card or airtime purchase still processing after half an
      // hour is a customer who paid and got nothing.
      array('stuck_services',     'Services stuck >30m',      'admin/analytics',               'reports.view'),
    );
    foreach ($items as $item): list($key, $label, $href, $perm) = $item;
      $count = (int)($queue[$key] ?? 0);
      if (!$has($perm)) continue; ?>
      <a class="card" href="<?=site_url($href)?>" style="display:block;text-decoration:none">
        <div class="muted text-sm"><?=htmlspecialchars($label)?></div>
        <div class="text-2xl font-bold <?=$count > 0 ? '' : 'muted'?>"><?=number_format($count)?></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Orders by status</h3>
    <?php if (empty($status_counts)): ?>
      <p class="muted text-sm">No orders yet.</p>
    <?php else: ?>
      <table class="table">
        <tbody>
        <?php foreach ($status_counts as $status => $count): ?>
          <tr>
            <td><span class="<?=DashboardStats::status_badge($status)?>"><?=htmlspecialchars($status)?></span></td>
            <td class="text-right mono"><?=number_format((int)$count)?></td>
            <td class="text-right">
              <?php if ($has('orders.view')): ?>
                <a class="text-xs" href="<?=site_url('admin/orders?status='.urlencode($status))?>">view</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="row justify-between mb-2">
      <h3 class="text-sm font-semibold mb-0">Recent sales</h3>
      <?php if ($has('reports.view')): ?>
        <a class="text-xs" href="<?=site_url('admin/analytics')?>">Analytics →</a>
      <?php endif; ?>
    </div>
    <?php if (empty($recent)): ?>
      <p class="muted text-sm">No sales yet.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr><th>Sale</th><th>Customer</th><th class="text-right">Charge</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td>
              <?php if ($r['url']): ?>
                <a class="mono text-xs" href="<?=site_url($r['url'])?>"><?=htmlspecialchars($r['public_id'])?></a>
              <?php else: ?>
                <span class="mono text-xs"><?=htmlspecialchars($r['public_id'])?></span>
              <?php endif; ?>
              <div class="text-xs muted">
                <span class="badge badge-default"><?=htmlspecialchars($r['domain'])?></span>
                <?=htmlspecialchars($r['label'])?>
              </div>
            </td>
            <td class="text-sm"><?=htmlspecialchars((string)$r['username'])?></td>
            <td class="text-right mono"><?=marvy_money($r['amount'], $r['currency'])?></td>
            <td><span class="<?=DashboardStats::status_badge($r['status'])?>"><?=htmlspecialchars($r['status'])?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-4">
  <div class="row justify-between mb-2">
    <h3 class="text-sm font-semibold mb-0">Revenue by domain · 30 days</h3>
    <a class="text-xs" href="<?=site_url('admin/analytics')?>">Full analytics →</a>
  </div>
  <?php if (empty($domains)): ?>
    <p class="muted text-sm">Nothing sold in the last 30 days.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>Domain</th><th class="text-right">Sales</th>
                 <th class="text-right">Net</th><th class="text-right">Margin</th></tr></thead>
      <tbody>
      <?php foreach ($domains as $domain => $d): ?>
        <tr>
          <td><?=htmlspecialchars($domain)?></td>
          <td class="text-right mono"><?=number_format((int)$d['sales'])?></td>
          <td class="text-right mono"><?=marvy_money($d['net'])?></td>
          <td class="text-right mono">
            <?=$d['margin'] === null ? '<span class="muted">—</span>' : marvy_money($d['margin'])?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
