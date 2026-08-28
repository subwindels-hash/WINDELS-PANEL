<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

// Mirror TransactionEngine::transition(): a terminal purchase is closed, and a
// settled one may only be refunded. Offering an action the engine will reject
// is worse than not offering it.
$terminal    = in_array($tx->status, array('FAILED','CANCELLED','REFUNDED'), true);
$can_recheck = !$terminal && $tx->status === 'PROCESSING' && $tx->provider_reference;
$can_refund  = !$terminal && bccomp((string)$tx->refunded_amount, (string)$tx->amount, 8) < 0;
$outstanding = bcsub((string)$tx->amount, (string)$tx->refunded_amount, 8);
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/vtu')?>">← All VTU purchases</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=htmlspecialchars($tx->service_type)?> <span class="mono"><?=htmlspecialchars($tx->public_id)?></span>
    </h2>
    <p class="muted text-sm">
      <span class="<?=DashboardStats::status_badge($tx->status)?>"><?=htmlspecialchars($tx->status)?></span>
      bought <?=htmlspecialchars((string)$tx->created_at)?> via <?=htmlspecialchars((string)$tx->source)?>
    </p>
  </div>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Purchase</h3>
    <table class="table">
      <tbody>
        <tr><th>Customer</th><td><?=htmlspecialchars((string)$tx->username)?>
            <span class="muted text-xs"><?=htmlspecialchars((string)$tx->email)?></span></td></tr>
        <tr><th>Service</th><td><?=htmlspecialchars((string)$tx->service_domain)?>
            / <?=htmlspecialchars((string)$tx->service_type)?></td></tr>
        <?php if ($detail): ?>
        <tr><th>Recipient</th><td class="mono"><?=htmlspecialchars((string)$detail->recipient)?>
            <?php if ($detail->recipient_name): ?>
              <div class="muted text-xs"><?=htmlspecialchars((string)$detail->recipient_name)?></div>
            <?php endif; ?></td></tr>
        <?php if ($detail->variation_code): ?>
        <tr><th>Product code</th><td class="mono text-xs"><?=htmlspecialchars((string)$detail->variation_code)?></td></tr>
        <?php endif; ?>
        <?php if ($detail->face_value !== null): ?>
        <tr><th>Face value</th><td class="mono"><?=marvy_money($detail->face_value, $tx->currency)?></td></tr>
        <?php endif; ?>
        <?php if ($detail->token): ?>
        <tr><th>Token / PIN</th><td class="mono"><?=htmlspecialchars((string)$detail->token)?>
            <?php if ($detail->units): ?><span class="muted text-xs"><?=htmlspecialchars((string)$detail->units)?> units</span><?php endif; ?></td></tr>
        <?php endif; ?>
        <?php endif; ?>
        <tr><th>Charged</th><td class="mono"><?=marvy_money($tx->amount, $tx->currency)?></td></tr>
        <?php if ($tx->provider_cost !== null): ?>
        <tr><th>Provider cost</th><td class="mono"><?=marvy_money($tx->provider_cost, $tx->currency)?>
            <span class="muted text-xs">margin <?=marvy_money(bcsub((string)$tx->amount, (string)$tx->provider_cost, 8), $tx->currency)?></span></td></tr>
        <?php endif; ?>
        <?php if (bccomp((string)$tx->refunded_amount, '0', 8) > 0): ?>
        <tr><th>Refunded</th><td class="mono"><?=marvy_money($tx->refunded_amount, $tx->currency)?></td></tr>
        <?php endif; ?>
        <tr><th>Provider</th><td>
          <?=$tx->provider_name ? htmlspecialchars($tx->provider_name) : '<span class="muted">— none</span>'?>
          <?php if ($tx->provider_reference): ?>
            <div class="mono text-xs muted">#<?=htmlspecialchars((string)$tx->provider_reference)?></div>
          <?php endif; ?>
        </td></tr>
        <?php if ($tx->failure_reason): ?>
        <tr><th>Failure</th><td class="text-xs"><?=htmlspecialchars((string)$tx->failure_reason)?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Actions</h3>
    <?php if (!$has('vtu.manage') && !$has('vtu.refund')): ?>
      <p class="muted text-sm">You have read-only access to VTU purchases.</p>
    <?php elseif ($terminal): ?>
      <p class="muted text-sm">This purchase is <?=htmlspecialchars($tx->status)?> and can no longer be changed.</p>
    <?php else: ?>

      <?php if ($has('vtu.manage')): ?>
        <?php if ($can_recheck): ?>
        <form method="post" action="<?=site_url('admin/vtu/'.$tx->public_id.'/recheck')?>" class="mb-4">
          <?=$csrf()?>
          <p class="hint mb-2">Ask the provider what happened to this purchase. A confirmed
             failure refunds the customer automatically.</p>
          <button class="btn btn-secondary btn-sm" type="submit">Re-check with provider</button>
        </form>
        <?php else: ?>
        <p class="muted text-sm mb-4">
          <?=$tx->status === 'PROCESSING'
              ? 'No provider reference on this purchase, so it cannot be re-checked.'
              : 'Only a PROCESSING purchase can be re-checked.'?>
        </p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($has('vtu.refund') && $can_refund): ?>
      <form method="post" action="<?=site_url('admin/vtu/'.$tx->public_id.'/refund')?>"
            data-confirm="Refund <?=htmlspecialchars(marvy_money($outstanding, $tx->currency))?> to this customer&#39;s wallet?" >
        <?=$csrf()?>
        <label class="text-sm font-medium" for="reason">Refund reason</label>
        <input class="input mb-2" id="reason" name="reason" placeholder="Recorded in the status history">
        <p class="hint mb-2">Returns <?=marvy_money($outstanding, $tx->currency)?> — the charge less anything already refunded.</p>
        <button class="btn btn-secondary btn-sm" type="submit">Refund purchase</button>
      </form>
      <?php elseif ($has('vtu.refund')): ?>
      <p class="muted text-sm">Nothing left to refund on this purchase.</p>
      <?php endif; ?>

    <?php endif; ?>
  </div>
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
  <h3 class="text-sm font-semibold mb-2">Provider calls</h3>
  <?php if (empty($provider_calls)): ?>
    <p class="muted text-sm">The provider was never called for this purchase.</p>
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
