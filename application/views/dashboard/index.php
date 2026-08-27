<?php defined('BASEPATH') OR exit('No direct script access allowed');
$t = $totals;
$name = htmlspecialchars($current_user->username ?? 'there');
?>
<?php if (empty($current_user->email_verified_at)): ?>
  <div class="alert alert-warning">
    <strong>Please verify your email.</strong> Some features are restricted until you confirm your address.
    <form method="post" action="<?=site_url('verify-email/resend')?>" class="inline">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
      <button type="submit" class="btn btn-sm btn-secondary">Resend verification email</button>
    </form>
  </div>
<?php endif; ?>

<section class="ws-stat-grid">
  <div class="card ws-stat-card">
    <div class="card-meta">Wallet balance</div>
    <div class="ws-stat-value"><?=marvy_money($wallet->balance ?? '0', $wallet->currency ?? marvy_base_currency())?></div>
    <p class="hint">Available for orders and in-app purchases.</p>
  </div>
  <a href="<?=site_url('dashboard/orders')?>" class="card ws-stat-card card-hover ws-action-card">
    <div class="card-meta">Total orders</div>
    <div class="ws-stat-value"><?=number_format($t['orders'])?></div>
    <p class="hint"><?=number_format($t['active'])?> active · <?=number_format($t['completed'])?> completed</p>
  </a>
  <a href="<?=site_url('dashboard/orders?status=PROCESSING')?>" class="card ws-stat-card card-hover ws-action-card">
    <div class="card-meta">Active orders</div>
    <div class="ws-stat-value"><?=number_format($t['active'])?></div>
    <p class="hint"><?=number_format($t['pending'] ?? 0)?> pending</p>
  </a>
  <a href="<?=site_url('dashboard/orders?status=COMPLETED')?>" class="card ws-stat-card card-hover ws-action-card">
    <div class="card-meta">Completed</div>
    <div class="ws-stat-value"><?=number_format($t['completed'])?></div>
    <p class="hint"><?=marvy_money($t['spent'])?> spent</p>
  </a>
</section>

<section class="ws-action-grid">
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/new-order')?>">
    <h3 class="card-title">New order</h3>
    <p class="muted mb-0">Place SMM, drip-feed, or subscription orders.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/add-funds')?>">
    <h3 class="card-title">Add funds</h3>
    <p class="muted mb-0">Top up your prepaid wallet.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/orders')?>">
    <h3 class="card-title">View orders</h3>
    <p class="muted mb-0">Track status, refills, and history.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/tickets')?>">
    <h3 class="card-title">Contact support</h3>
    <p class="muted mb-0">Open a ticket if something looks wrong.</p>
  </a>
</section>

<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 card">
    <div class="row justify-between">
      <h2 class="card-title mb-0">Recent orders</h2>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/orders')?>">View all</a>
    </div>
    <?php if (empty($orders)): ?>
      <?php $this->load->view('partials/empty_state', array('title'=>'No orders yet','body'=>'Place your first order to see it here.','action_href'=>site_url('dashboard/new-order'),'action_label'=>'New order')); ?>
    <?php else: ?>
    <div class="table-wrap mt-3">
      <table class="table">
        <thead><tr><th>Order</th><th>Service</th><th>Qty</th><th>Charge</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="mono text-xs"><?=htmlspecialchars(substr($o->public_id,0,10))?>…</td>
            <td class="truncate max-w-[220px]"><?=htmlspecialchars($o->service_name ?? 'Service #'.$o->service_id)?></td>
            <td><?=number_format($o->quantity)?></td>
            <td><?=marvy_money($o->charge, $o->currency)?></td>
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
      <h2 class="card-title mb-0">Recent activity</h2>
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
            <?=$tx->direction==='CREDIT' ? '+' : '−'?><?=marvy_money($tx->amount, $tx->currency)?>
          </span>
        </li>
      <?php endforeach; ?>
      <?php foreach (array_slice($notifications ?? array(), 0, 3) as $n): ?>
        <li class="text-sm"><span class="badge badge-info badge-dot">new</span> <?=htmlspecialchars($n->title)?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<section class="ws-action-grid">
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/services')?>"><h3 class="card-title">SMM services</h3><p class="muted mb-0">Catalogue of social media services.</p></a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/vtu')?>"><h3 class="card-title">VTU</h3><p class="muted mb-0">Airtime, data, and bills.</p></a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/numbers')?>"><h3 class="card-title">Phone numbers</h3><p class="muted mb-0">Temporary numbers for OTP.</p></a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/giftcards')?>"><h3 class="card-title">Gift cards</h3><p class="muted mb-0">Digital gift card catalogue.</p></a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/identity')?>"><h3 class="card-title">Identity</h3><p class="muted mb-0">Verification products.</p></a>
  <a class="card card-hover ws-action-card" href="<?=site_url('dashboard/marketplace')?>"><h3 class="card-title">Marketplace</h3><p class="muted mb-0">Accounts and digital goods.</p></a>
</section>
