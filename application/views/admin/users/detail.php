<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

$can_edit  = $has('users.edit');
$can_role  = $has('staff.manage');
$can_price       = $has('pricing.manage');
$can_money       = $has('wallets.adjust');
$can_impersonate = $has('users.impersonate');
$self            = (int)$current_user->id === (int)$user->id;

$status_badge = function ($s) {
    $map = array('ACTIVE'=>'badge-success','SUSPENDED'=>'badge-warning','BANNED'=>'badge-danger','PENDING'=>'badge-default');
    return $map[strtoupper((string)$s)] ?? 'badge-default';
};
// One nonce per render: the adjustment form's idempotency key. Re-submitting
// the same rendered page cannot pay the customer twice.
$nonce = bin2hex(random_bytes(8));
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-xs muted" href="<?=site_url('admin/customers')?>">← Customers</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=htmlspecialchars((string)$user->username)?>
      <span class="badge <?=$status_badge($user->status)?>"><?=htmlspecialchars((string)$user->status)?></span>
    </h2>
    <p class="muted text-sm">
      <?=htmlspecialchars((string)$user->email)?>
      · <span class="mono text-xs"><?=htmlspecialchars((string)$user->public_id)?></span>
      · joined <?=htmlspecialchars(date('M j, Y', strtotime($user->created_at)))?>
    </p>
  </div>
  <div class="text-right">
    <div class="muted text-xs">Wallet balance</div>
    <div style="font-size:1.5rem;font-weight:600" class="mono">
      <?=windels_money($user->wallet->balance, $user->wallet->currency)?>
    </div>
  </div>
</div>

<?php if ($self): ?>
<div class="alert alert-info mb-4">
  This is your own account. Role and status changes are disabled here — ask another admin.
</div>
<?php endif; ?>
<?php if (!empty($is_last_admin)): ?>
<div class="alert alert-warning mb-4">
  This is the only active super admin. It cannot be demoted or suspended until someone else is promoted.
</div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.75rem" class="mb-4">
  <div class="card">
    <div class="muted text-xs">Lifetime deposited</div>
    <div class="mono" style="font-size:1.1rem"><?=windels_money($user->wallet->total_deposited, $user->wallet->currency)?></div>
  </div>
  <div class="card">
    <div class="muted text-xs">Lifetime spent</div>
    <div class="mono" style="font-size:1.1rem"><?=windels_money($user->wallet->total_spent, $user->wallet->currency)?></div>
  </div>
  <div class="card">
    <div class="muted text-xs">Role</div>
    <div style="font-size:1.1rem"><?=htmlspecialchars((string)$user->role)?></div>
  </div>
  <div class="card">
    <div class="muted text-xs">Last login</div>
    <div class="text-sm"><?=$user->last_login_at ? htmlspecialchars(date('M j, Y H:i', strtotime($user->last_login_at))) : 'never'?></div>
  </div>
</div>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-3">Account</h3>
  <div class="row" style="gap:.5rem;flex-wrap:wrap">

    <?php if ($can_edit && !$self): ?>
    <form method="post" action="<?=site_url('admin/customers/'.$user->public_id.'/status')?>"
          class="row" style="gap:.35rem;align-items:flex-end">
      <?=$csrf()?>
      <label class="field"><span class="label">Status</span>
        <select class="select" name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?=htmlspecialchars($s)?>" <?=$user->status === $s ? 'selected' : ''?>>
              <?=htmlspecialchars($s)?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span class="label">Reason</span>
        <input class="input" name="reason" placeholder="Optional, kept in the audit log">
      </label>
      <button class="btn btn-secondary btn-sm" type="submit">Apply</button>
    </form>
    <?php endif; ?>

    <?php if ($can_role && !$self): ?>
    <form method="post" action="<?=site_url('admin/customers/'.$user->public_id.'/role')?>"
          class="row" style="gap:.35rem;align-items:flex-end">
      <?=$csrf()?>
      <label class="field"><span class="label">Role</span>
        <select class="select" name="role">
          <?php foreach ($roles as $r): ?>
            <option value="<?=htmlspecialchars($r)?>" <?=$user->role === $r ? 'selected' : ''?>>
              <?=htmlspecialchars($r)?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-secondary btn-sm" type="submit">Change role</button>
    </form>
    <?php endif; ?>

    <?php if ($can_price): ?>
    <form method="post" action="<?=site_url('admin/customers/'.$user->public_id.'/price-group')?>"
          class="row" style="gap:.35rem;align-items:flex-end">
      <?=$csrf()?>
      <label class="field"><span class="label">Price group</span>
        <select class="select" name="price_group_id">
          <option value="">Standard rates</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?=(int)$g->id?>" <?=(int)$user->price_group_id === (int)$g->id ? 'selected' : ''?>>
              <?=htmlspecialchars($g->name)?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-secondary btn-sm" type="submit">Save</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($can_impersonate && !$self && $user->status === 'ACTIVE' && $user->role === 'CUSTOMER'): ?>
<div class="card mb-4" style="border-color:var(--color-warning,#f59e0b)">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Read-only customer impersonation</h3>
  <p class="muted text-xs mb-3">
    Open this customer's dashboard for support diagnosis. The session is audited, expires after 30 minutes,
    blocks every write action and never reveals credentials. Use only with customer authorization or an approved support reason.
  </p>
  <form method="post" action="<?=site_url('admin/customers/'.$user->public_id.'/impersonate')?>">
    <?=$csrf()?>
    <label class="field mb-2"><span class="label">Support reason</span>
      <textarea class="input" name="reason" rows="2" minlength="5" maxlength="500" required
                placeholder="Ticket reference and issue being investigated"></textarea>
    </label>
    <label class="row text-xs mb-3" style="gap:.5rem;align-items:flex-start">
      <input type="checkbox" name="confirm" value="1" required style="margin-top:.2rem">
      <span>I understand this switches my effective identity to this customer and I must use the warning banner to return to my staff account.</span>
    </label>
    <button class="btn btn-warning btn-sm" type="submit">Start read-only impersonation</button>
  </form>
</div>
<?php endif; ?>

<?php if ($can_money): ?>
<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Adjust wallet</h3>
  <p class="muted text-xs mb-3">
    Recorded in the ledger against your account. A debit cannot take the balance below zero.
  </p>
  <form method="post" action="<?=site_url('admin/customers/'.$user->public_id.'/adjust')?>"
        class="row" style="gap:.5rem;align-items:flex-end;flex-wrap:wrap">
    <?=$csrf()?>
    <input type="hidden" name="nonce" value="<?=htmlspecialchars($nonce)?>">
    <label class="field"><span class="label">Direction</span>
      <select class="select" name="direction">
        <option value="CREDIT">Credit — add funds</option>
        <option value="DEBIT">Debit — take funds back</option>
      </select>
    </label>
    <label class="field"><span class="label">Amount (<?=htmlspecialchars(windels_base_currency())?>)</span>
      <input class="input mono" type="number" name="amount" step="0.01" min="0.01" required
             placeholder="0.00" style="max-width:9rem">
    </label>
    <label class="field" style="flex:1;min-width:14rem"><span class="label">Reason (required)</span>
      <input class="input" name="reason" required maxlength="255"
             placeholder="e.g. goodwill for failed order WX1234">
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Apply adjustment</button>
  </form>
</div>
<?php endif; ?>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-3">Recent wallet movements</h3>
  <?php if (empty($movements)): ?>
    <p class="muted text-sm">No wallet activity yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>When</th><th>Type</th><th class="text-right">Amount</th>
                 <th class="text-right">Balance after</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td class="text-xs muted whitespace-nowrap"><?=htmlspecialchars(date('M j, H:i', strtotime($m->created_at)))?></td>
          <td class="text-xs"><?=htmlspecialchars(DashboardStats::transaction_label($m))?></td>
          <td class="text-right mono <?=$m->direction === 'CREDIT' ? 'text-green-600' : ''?>">
            <?=$m->direction === 'CREDIT' ? '+' : '−'?><?=windels_money($m->amount, $m->currency)?>
          </td>
          <td class="text-right mono muted"><?=windels_money($m->balance_after, $m->currency)?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($m->note ?? ''))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="row" style="gap:.75rem;flex-wrap:wrap;align-items:flex-start">
  <div class="card" style="flex:1;min-width:20rem">
    <h3 style="font-size:1rem;font-weight:600" class="mb-3">Recent SMM orders</h3>
    <?php if (empty($orders)): ?>
      <p class="muted text-sm">No orders.</p>
    <?php else: ?>
      <table class="table">
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><a class="mono text-xs" href="<?=site_url('admin/orders/'.$o->public_id)?>"><?=htmlspecialchars($o->public_id)?></a></td>
            <td class="text-xs"><?=htmlspecialchars((string)($o->service_name ?? ''))?></td>
            <td class="text-right mono text-xs"><?=windels_money($o->charge, $o->currency ?? null)?></td>
            <td><span class="<?=DashboardStats::status_badge($o->status)?>"><?=htmlspecialchars($o->status)?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" style="flex:1;min-width:20rem">
    <h3 style="font-size:1rem;font-weight:600" class="mb-3">Recent service purchases</h3>
    <?php if (empty($services)): ?>
      <p class="muted text-sm">No VTU, number, identity or gift card purchases.</p>
    <?php else: ?>
      <table class="table">
        <tbody>
        <?php foreach ($services as $s): ?>
          <tr>
            <td class="mono text-xs"><?=htmlspecialchars($s->public_id)?></td>
            <td class="text-xs"><?=htmlspecialchars((string)$s->service_domain)?> · <?=htmlspecialchars((string)$s->service_type)?></td>
            <td class="text-right mono text-xs"><?=windels_money($s->amount, $s->currency ?? null)?></td>
            <td><span class="<?=DashboardStats::status_badge($s->status)?>"><?=htmlspecialchars($s->status)?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
