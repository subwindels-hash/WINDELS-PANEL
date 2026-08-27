<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

// Mirror the two state machines this screen sits on top of. The transaction's
// terminal rule comes from TransactionEngine::transition(); the reservation's
// live rule comes from NumberService. Offering an action either one will
// reject is worse than not offering it.
$terminal    = in_array($tx->status, array('FAILED','CANCELLED','REFUNDED'), true);
$live        = $number && in_array($number->status, array('RESERVED','RECEIVED'), true);
$can_refund  = !$terminal && bccomp((string)$tx->refunded_amount, (string)$tx->amount, 8) < 0;
$outstanding = bcsub((string)$tx->amount, (string)$tx->refunded_amount, 8);
$expires     = $number && $number->expires_at ? strtotime($number->expires_at.' UTC') : null;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/numbers')?>">← All reservations</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=$number ? htmlspecialchars($number->msisdn) : 'Reservation'?>
      <span class="mono text-xs"><?=htmlspecialchars($tx->public_id)?></span>
    </h2>
    <p class="muted text-sm">
      <span class="<?=DashboardStats::status_badge($tx->status)?>"><?=htmlspecialchars($tx->status)?></span>
      rented <?=htmlspecialchars((string)$tx->created_at)?> via <?=htmlspecialchars((string)$tx->source)?>
    </p>
  </div>
</div>

<?php if ($live && $expires !== null && $expires <= time()): ?>
<div class="alert alert-warning mb-4">
  This reservation is past its deadline but has not been settled yet. Re-check
  it — if no code arrived, the customer is refunded automatically.
</div>
<?php endif; ?>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Reservation</h3>
    <table class="table">
      <tbody>
        <tr><th>Customer</th><td><?=htmlspecialchars((string)$tx->username)?>
            <span class="muted text-xs"><?=htmlspecialchars((string)$tx->email)?></span></td></tr>
        <tr><th>Number</th><td class="mono"><?=$number ? htmlspecialchars($number->msisdn) : '—'?>
            <?php if ($number && $number->operator): ?>
              <div class="muted text-xs"><?=htmlspecialchars($number->operator)?></div>
            <?php endif; ?></td></tr>
        <tr><th>For</th><td>
          <?=$service ? htmlspecialchars($service->name) : '—'?>
          <?=$country ? '<span class="muted text-xs">'.htmlspecialchars($country->name).'</span>' : ''?>
        </td></tr>
        <tr><th>State</th><td>
          <span class="badge <?=($number && $number->status === 'RECEIVED') ? 'badge-success' : 'badge-default'?>">
            <?=$number ? htmlspecialchars($number->status) : '—'?></span>
          <span class="muted text-xs"><?=$number ? (int)$number->sms_count : 0?> message(s)</span>
        </td></tr>
        <tr><th>Expires</th><td class="text-xs"><?=$number ? htmlspecialchars((string)$number->expires_at) : '—'?> UTC</td></tr>
        <?php if ($number && $number->released_at): ?>
        <tr><th>Released</th><td class="text-xs"><?=htmlspecialchars((string)$number->released_at)?> UTC</td></tr>
        <?php endif; ?>
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
          <?php if ($number && $number->provider_order_id): ?>
            <div class="mono text-xs muted">#<?=htmlspecialchars((string)$number->provider_order_id)?></div>
          <?php endif; ?>
        </td></tr>
        <?php if ($tx->failure_reason): ?>
        <tr><th>Outcome</th><td class="text-xs"><?=htmlspecialchars((string)$tx->failure_reason)?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Actions</h3>
    <?php if (!$has('numbers.manage') && !$has('numbers.refund')): ?>
      <p class="muted text-sm">You have read-only access to reservations.</p>
    <?php else: ?>

      <?php if ($has('numbers.manage')): ?>
        <?php if ($live): ?>
        <form method="post" action="<?=site_url('admin/numbers/'.$tx->public_id.'/recheck')?>" class="mb-4">
          <?=$csrf()?>
          <p class="hint mb-2">Poll the vendor now. A code settles the purchase;
             an expired reservation refunds the customer automatically.</p>
          <button class="btn btn-secondary btn-sm" type="submit">Re-check with vendor</button>
        </form>

        <form method="post" action="<?=site_url('admin/numbers/'.$tx->public_id.'/release')?>" class="mb-4"
              onsubmit="return confirm('Release this number back to the vendor?')">
          <?=$csrf()?>
          <p class="hint mb-2">Hand the number back. If no code ever arrived the
             charge is refunded as part of releasing it.</p>
          <button class="btn btn-ghost btn-sm" type="submit">Release reservation</button>
        </form>
        <?php else: ?>
        <p class="muted text-sm mb-4">
          The vendor no longer holds this number, so it cannot be re-checked or released.
        </p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($has('numbers.refund') && $can_refund): ?>
      <form method="post" action="<?=site_url('admin/numbers/'.$tx->public_id.'/refund')?>"
            onsubmit="return confirm('Refund <?=htmlspecialchars(marvy_money($outstanding, $tx->currency))?> to this customer\'s wallet?')">
        <?=$csrf()?>
        <label class="text-sm font-medium" for="reason">Refund reason</label>
        <input class="input mb-2" id="reason" name="reason" placeholder="Recorded in the status history">
        <p class="hint mb-2">Returns <?=marvy_money($outstanding, $tx->currency)?> — the charge less anything already refunded.</p>
        <button class="btn btn-secondary btn-sm" type="submit">Refund reservation</button>
      </form>
      <?php elseif ($has('numbers.refund')): ?>
      <p class="muted text-sm">Nothing left to refund on this reservation.</p>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<div class="card mt-4">
  <h3 class="text-sm font-semibold mb-2">Codes received</h3>
  <?php if (empty($messages)): ?>
    <p class="muted text-sm">No messages have been delivered to this number.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>Code</th><th>From</th><th>Message</th><th>Received</th></tr></thead>
      <tbody>
      <?php foreach ($messages as $m): ?>
        <tr>
          <td class="mono"><?=htmlspecialchars((string)($m->code ?? '—'))?></td>
          <td class="text-xs"><?=htmlspecialchars((string)$m->sender)?></td>
          <td class="text-xs"><?=htmlspecialchars((string)$m->body)?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)$m->received_at)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
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
    <p class="muted text-sm">The vendor was never called for this reservation.</p>
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
