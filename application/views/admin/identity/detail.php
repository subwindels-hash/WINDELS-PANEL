<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

$labels = array(
    'first_name'=>'First name', 'middle_name'=>'Middle name', 'last_name'=>'Last name',
    'date_of_birth'=>'Date of birth', 'gender'=>'Gender', 'phone_number'=>'Phone number',
    'nationality'=>'Nationality', 'state_of_origin'=>'State of origin',
    'lga_of_origin'=>'LGA of origin',
);

$terminal    = in_array($tx->status, array('FAILED','CANCELLED','REFUNDED'), true);
$can_refund  = !$terminal && bccomp((string)$tx->refunded_amount, (string)$tx->amount, 8) < 0;
$outstanding = bcsub((string)$tx->amount, (string)$tx->refunded_amount, 8);
$purged      = $check && !empty($check->purged_at);
$readable    = $check && $check->status === 'VERIFIED' && !$purged && !empty($check->result_encrypted);
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/identity')?>">← All checks</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=$check ? htmlspecialchars($check->id_type) : 'Identity'?> check
      <span class="mono text-xs"><?=htmlspecialchars($tx->public_id)?></span>
    </h2>
    <p class="muted text-sm">
      <span class="<?=DashboardStats::status_badge($tx->status)?>"><?=htmlspecialchars($tx->status)?></span>
      run <?=htmlspecialchars((string)$tx->created_at)?> via <?=htmlspecialchars((string)$tx->source)?>
    </p>
  </div>
</div>

<?php if ($check && $check->status === 'NOT_FOUND'): ?>
<div class="alert alert-info mb-4">
  The vendor completed this lookup and found no matching record. The customer
  was refunded automatically — this is not a failure to retry, and re-running
  it will bill us again for the same answer.
</div>
<?php endif; ?>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Check</h3>
    <table class="table">
      <tbody>
        <tr><th>Customer</th><td><?=htmlspecialchars((string)$tx->username)?>
            <span class="muted text-xs"><?=htmlspecialchars((string)$tx->email)?></span></td></tr>
        <tr><th>Product</th><td><?=$product ? htmlspecialchars($product->name) : htmlspecialchars((string)$tx->service_type)?></td></tr>
        <tr><th>Number checked</th><td class="mono">
          <?=$check && $check->identifier_last4 ? '•••••••'.htmlspecialchars($check->identifier_last4) : '—'?>
          <div class="muted text-xs">
            The full number is not stored — only a keyed hash and the last four digits.
          </div>
        </td></tr>
        <tr><th>Outcome</th><td>
          <span class="badge <?=($check && $check->status === 'VERIFIED') ? 'badge-success' : 'badge-default'?>">
            <?=$check ? htmlspecialchars($check->status) : '—'?></span>
        </td></tr>
        <tr><th>Consent</th><td class="text-xs">
          <?php if ($check && $check->consent_at): ?>
            Confirmed <?=htmlspecialchars((string)$check->consent_at)?> UTC
            <?=$check->consent_ip ? 'from '.htmlspecialchars((string)$check->consent_ip) : ''?>
          <?php else: ?><span class="muted">not recorded</span><?php endif; ?>
        </td></tr>
        <tr><th>Charged</th><td class="mono"><?=marvy_money($tx->amount, $tx->currency)?></td></tr>
        <?php if ($tx->provider_cost !== null): ?>
        <tr><th>Vendor cost</th><td class="mono"><?=marvy_money($tx->provider_cost, $tx->currency)?>
            <span class="muted text-xs">margin <?=marvy_money(bcsub((string)$tx->amount, (string)$tx->provider_cost, 8), $tx->currency)?></span></td></tr>
        <?php endif; ?>
        <?php if (bccomp((string)$tx->refunded_amount, '0', 8) > 0): ?>
        <tr><th>Refunded</th><td class="mono"><?=marvy_money($tx->refunded_amount, $tx->currency)?></td></tr>
        <?php endif; ?>
        <tr><th>Vendor</th><td>
          <?=$tx->provider_name ? htmlspecialchars($tx->provider_name) : '<span class="muted">— none</span>'?>
          <?php if ($check && $check->provider_reference): ?>
            <div class="mono text-xs muted"><?=htmlspecialchars((string)$check->provider_reference)?></div>
          <?php endif; ?>
        </td></tr>
        <tr><th>Result access</th><td class="text-xs">
          <?php if ($check && (int)$check->reveal_count > 0): ?>
            Opened <?=(int)$check->reveal_count?> time(s); last
            <?=htmlspecialchars((string)$check->last_revealed_at)?> UTC
          <?php else: ?><span class="muted">never opened</span><?php endif; ?>
        </td></tr>
        <tr><th>Retention</th><td class="text-xs">
          <?php if ($purged): ?>
            Result deleted <?=htmlspecialchars((string)$check->purged_at)?> UTC
          <?php else: ?>
            Deleted automatically <?=(int)$retention_days?> days after the check
          <?php endif; ?>
        </td></tr>
        <?php if ($tx->failure_reason): ?>
        <tr><th>Outcome note</th><td class="text-xs"><?=htmlspecialchars((string)$tx->failure_reason)?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Result</h3>

    <?php if ($entity): ?>
      <div class="alert alert-warning mb-4 text-sm">
        You opened this record. Your name, the time and this check have been
        written to the audit log.
      </div>
      <table class="table">
        <tbody>
        <?php foreach ($labels as $key => $label): if (empty($entity[$key])) continue; ?>
          <tr><th><?=htmlspecialchars($label)?></th>
              <td><?=htmlspecialchars((string)$entity[$key])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>

    <?php elseif ($purged): ?>
      <p class="muted text-sm">This result has been deleted. The check record remains.</p>

    <?php elseif (!$readable): ?>
      <p class="muted text-sm">There is no stored result for this check.</p>

    <?php elseif ($has('identity.reveal')): ?>
      <p class="muted text-sm mb-4">
        The result is encrypted at rest. Open it only if you need it to answer
        this customer — the access is logged against your account.
      </p>
      <form method="post" action="<?=site_url('admin/identity/'.$tx->public_id.'/reveal')?>"
            data-confirm="Open this identity record? Your access will be logged." >
        <?=$csrf()?>
        <button class="btn btn-secondary btn-sm" type="submit">Reveal result</button>
      </form>

    <?php else: ?>
      <p class="muted text-sm">
        A result is stored for this check. You do not have permission to open it.
      </p>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-4">
  <h3 class="text-sm font-semibold mb-2">Actions</h3>
  <?php if (!$has('identity.refund') && !$has('identity.manage')): ?>
    <p class="muted text-sm">You have read-only access to identity checks.</p>
  <?php else: ?>

    <?php if ($has('identity.refund') && $can_refund): ?>
    <form method="post" action="<?=site_url('admin/identity/'.$tx->public_id.'/refund')?>" class="mb-4"
          data-confirm="Refund <?=htmlspecialchars(marvy_money($outstanding, $tx->currency))?> to this customer&#39;s wallet?" >
      <?=$csrf()?>
      <label class="text-sm font-medium" for="reason">Refund reason</label>
      <input class="input mb-2" id="reason" name="reason" placeholder="Recorded in the status history">
      <p class="hint mb-2">Returns <?=marvy_money($outstanding, $tx->currency)?> — the charge less anything already refunded.</p>
      <button class="btn btn-secondary btn-sm" type="submit">Refund check</button>
    </form>
    <?php elseif ($has('identity.refund')): ?>
    <p class="muted text-sm mb-4">Nothing left to refund on this check.</p>
    <?php endif; ?>

    <?php if ($has('identity.manage') && $readable): ?>
    <form method="post" action="<?=site_url('admin/identity/'.$tx->public_id.'/purge')?>"
          data-confirm="Permanently delete the stored result for this check? This cannot be undone." >
      <?=$csrf()?>
      <p class="hint mb-2">
        For an erasure request. Deletes the encrypted record now, ahead of the
        retention sweep. The transaction and audit trail are kept.
      </p>
      <button class="btn btn-ghost btn-sm" type="submit">Delete stored result</button>
    </form>
    <?php endif; ?>

  <?php endif; ?>
</div>

<div class="card mt-4">
  <h3 class="text-sm font-semibold mb-2">Status history</h3>
  <?php if (empty($history)): ?>
    <p class="muted text-sm">No transitions recorded yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>When</th><th>From</th><th>To</th><th>Source</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td class="text-xs muted"><?=htmlspecialchars((string)$h->created_at)?></td>
          <td class="text-xs"><?=htmlspecialchars((string)($h->from_status ?? '—'))?></td>
          <td><span class="<?=DashboardStats::status_badge($h->to_status)?>"><?=htmlspecialchars((string)$h->to_status)?></span></td>
          <td class="text-xs"><?=htmlspecialchars((string)$h->source)?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($h->reason ?? ''))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card mt-4">
  <h3 class="text-sm font-semibold mb-2">Vendor calls</h3>
  <?php if (empty($provider_calls)): ?>
    <p class="muted text-sm">The vendor was never called for this check.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>When</th><th>Action</th><th>Result</th><th>Reference</th>
                 <th class="text-right">Latency</th><th>Error</th></tr></thead>
      <tbody>
      <?php foreach ($provider_calls as $c): ?>
        <tr>
          <td class="text-xs muted"><?=htmlspecialchars((string)$c->created_at)?></td>
          <td class="text-xs"><?=htmlspecialchars((string)$c->action)?></td>
          <td><span class="<?=$c->status === 'SUCCESS' ? 'badge badge-success' : 'badge badge-danger'?>"><?=htmlspecialchars((string)$c->status)?></span></td>
          <td class="mono text-xs"><?=htmlspecialchars((string)$c->provider_reference)?></td>
          <td class="text-right mono text-xs"><?=$c->latency_ms !== null ? (int)$c->latency_ms.' ms' : '—'?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)$c->error)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
