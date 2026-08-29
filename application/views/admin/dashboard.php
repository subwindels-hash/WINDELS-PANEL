<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$healthy = (int)($health['HEALTHY'] ?? 0);
?>
<?php $ov = $overview ?? array(); ?>
<div class="ws-stat-grid">
  <div class="card"><div class="muted text-sm">Total users</div><div class="text-2xl font-bold"><?=number_format((int)($ov['users_total'] ?? 0))?></div>
    <div class="hint"><?=number_format((int)($ov['users_today'] ?? 0))?> new today · <?=number_format((int)($ov['users_suspended'] ?? 0))?> suspended</div></div>
  <div class="card"><div class="muted text-sm">Active users</div><div class="text-2xl font-bold"><?=number_format((int)($ov['users_active'] ?? 0))?></div></div>
  <div class="card"><div class="muted text-sm">Orders today</div><div class="text-2xl font-bold"><?=number_format((int)($ov['orders_today'] ?? 0))?></div>
    <div class="hint"><?=number_format((int)($ov['orders_pending'] ?? 0))?> pending · <?=number_format((int)($ov['orders_failed'] ?? 0))?> failed</div></div>
  <div class="card"><div class="muted text-sm">Wallet float</div><div class="text-2xl font-bold"><?=marvy_money($ov['wallet_float'] ?? '0')?></div>
    <div class="hint"><?=number_format((int)($ov['payouts_pending'] ?? 0))?> pending payouts</div></div>
</div>
<div class="ws-stat-grid">
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

<?php if (!empty($series)): ?>
<div class="card">
  <h3 class="text-sm font-semibold mb-2">Revenue · 14 days</h3>
  <div class="row" style="align-items:flex-end;gap:4px;height:72px">
    <?php
      $max = '0.00000001';
      foreach ($series as $d) { if (bccomp($d['net'], $max, 8) > 0) $max = $d['net']; }
      foreach ($series as $day => $d):
        $h = max(4, (int)round((float)$d['net'] / (float)$max * 64));
    ?>
      <div title="<?=htmlspecialchars($day.' · '.marvy_money($d['net']))?>" style="flex:1;height:<?=$h?>px;background:var(--brand-500);border-radius:3px 3px 0 0"></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4">
  <h3 class="text-sm font-semibold mb-2">Needs attention</h3>
  <div class="grid grid-4" style="gap:1rem">
    <?php
    $items = array(
      array('deposits',           'Deposits awaiting review', 'admin/payments?status=PENDING', 'payments.view'),
      array('tickets',            'Open tickets',             'admin/tickets?status=OPEN',     'tickets.view'),
      array('unassigned_tickets', 'Unassigned tickets',       'admin/tickets?unassigned=1',    'tickets.view'),
      // The anonymous half of support: contact-form messages nobody has
      // answered yet. Same queue, same screen real estate.
      array('contact_messages',   'Contact messages to answer', 'admin/messages',              'tickets.view'),
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

<?php if ($has('settings.manage') && !empty($inbox_recent)): ?>
<div class="card mt-4">
  <div class="row justify-between mb-2">
    <h3 class="text-sm font-semibold mb-0">Inbox
      <?php if ($inbox_unread > 0): ?><span class="badge badge-info badge-dot"><?=$inbox_unread?> new</span><?php endif; ?>
    </h3>
    <a class="text-xs" href="<?=site_url('admin/inbox')?>">Open inbox →</a>
  </div>
  <ul class="stack" style="gap:.25rem">
    <?php foreach ($inbox_recent as $m): ?>
      <li class="row justify-between" style="gap:.75rem;padding:.5rem 0;border-bottom:1px solid var(--slate-100)">
        <a class="text-sm min-w-0" href="<?=site_url('admin/inbox/'.$m->public_id)?>" style="text-decoration:none;color:inherit">
          <span style="width:8px;height:8px;border-radius:50%;background:<?=$m->is_read?'var(--slate-300)':'var(--brand-500)?>"></span>
          <?=htmlspecialchars((string)($m->from_name ?: ($m->from_email ?: 'Unknown sender')))?>
          <span class="muted">— <?=htmlspecialchars((string)$m->subject)?></span>
        </a>
        <span class="muted text-xs whitespace-nowrap"><?=$m->received_at ? date('M j H:i', strtotime($m->received_at)) : '—'?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

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
