<?php defined('BASEPATH') OR exit('No direct script access allowed');
$t = $totals;
?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <div class="card">
    <div class="card-meta">Wallet balance</div>
    <div class="mt-1 text-3xl font-bold tracking-tight" style="font-family:var(--font-display)">
      <?=windels_money($wallet->balance ?? '0', $wallet->currency ?? 'USD')?>
    </div>
    <div class="row mt-3">
      <a href="<?=site_url('dashboard/add-funds')?>" class="btn btn-primary btn-sm">Add funds</a>
      <a href="<?=site_url('dashboard/transactions')?>" class="btn btn-ghost btn-sm">History</a>
    </div>
  </div>

  <a href="<?=site_url('dashboard/orders')?>" class="card card-hover">
    <div class="card-meta">Total orders</div>
    <div class="mt-1 text-3xl font-bold" style="font-family:var(--font-display)"><?=number_format($t['orders'])?></div>
    <div class="mt-3 text-sm muted"><?=number_format($t['active'])?> active · <?=number_format($t['completed'])?> completed</div>
  </a>

  <div class="card">
    <div class="card-meta">Total spent</div>
    <div class="mt-1 text-3xl font-bold" style="font-family:var(--font-display)"><?=windels_money($t['spent'])?></div>
    <div class="mt-3 text-sm muted">across completed orders</div>
  </div>

  <div class="card">
    <div class="card-meta">Total deposited</div>
    <div class="mt-1 text-3xl font-bold" style="font-family:var(--font-display)"><?=windels_money($t['deposited'])?></div>
    <div class="mt-3"><a href="<?=site_url('dashboard/new-order')?>" class="btn btn-secondary btn-sm">Place an order →</a></div>
  </div>
</div>

<?php if (empty($current_user->email_verified_at)): ?>
  <div class="alert alert-warning mt-6">
    <strong>Please verify your email.</strong> Some features are restricted until you confirm your address.
    <form method="post" action="<?=site_url('verify-email/resend')?>" class="inline">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
      <button type="submit" class="btn btn-sm" style="background:var(--warning-600);color:#fff">Resend verification email</button>
    </form>
  </div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3 mt-6">
  <div class="lg:col-span-2 card">
    <div class="row justify-between">
      <h2 class="card-title mb-0">Recent orders</h2>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/orders')?>">View all →</a>
    </div>
    <?php if (empty($orders)): ?>
      <p class="muted mt-4">No orders yet. <a href="<?=site_url('dashboard/new-order')?>">Place your first order</a>.</p>
    <?php else: ?>
    <div class="overflow-x-auto mt-3">
      <table class="table">
        <thead><tr><th>Order</th><th>Service</th><th>Qty</th><th>Charge</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="mono text-xs"><?=htmlspecialchars(substr($o->public_id,0,10))?>…</td>
            <td class="truncate max-w-[220px]"><?=htmlspecialchars($o->service_name ?? 'Service #'.$o->service_id)?></td>
            <td><?=number_format($o->quantity)?></td>
            <td><?=windels_money($o->charge, $o->currency)?></td>
            <td><span class="<?=DashboardStats::status_badge($o->status)?> badge-dot"><?=htmlspecialchars(str_replace('_',' ',$o->status))?></span></td>
            <td><a class="text-sm" href="<?=site_url('dashboard/orders/'.$o->public_id)?>">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="row justify-between">
      <h2 class="card-title mb-0">Activity</h2>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/notifications')?>"><?=$unread ? $unread.' new' : 'Inbox'?></a>
    </div>
    <ul class="mt-3 stack" style="gap:.5rem">
      <?php if (empty($transactions) && empty($notifications)): ?>
        <li class="muted text-sm">No recent activity.</li>
      <?php endif; ?>
      <?php foreach (array_slice($transactions ?? array(), 0, 4) as $tx): ?>
        <li class="row justify-between text-sm" style="gap:.5rem">
          <span><?=htmlspecialchars(DashboardStats::transaction_label($tx))?></span>
          <span class="mono font-medium" style="color: $tx->direction==='CREDIT' ? 'var(--success-700)' : 'var(--slate-700)'">
            <?=$tx->direction==='CREDIT' ? '+' : '−'?><?=windels_money($tx->amount, $tx->currency)?>
          </span>
        </li>
      <?php endforeach; ?>
      <?php foreach (array_slice($notifications ?? array(), 0, 3) as $n): ?>
        <li class="text-sm"><span class="badge badge-info badge-dot">new</span> <?=htmlspecialchars($n->title)?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
