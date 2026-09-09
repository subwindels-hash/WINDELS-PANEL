<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

$order_badge = array('DELIVERED'=>'badge-success','PLACED'=>'badge-warning',
                     'PENDING'=>'badge-muted','FAILED'=>'badge-error',
                     'CANCELLED'=>'badge-muted');

$order_status = $order ? $order->status : 'PENDING';
$open         = $order && in_array($order_status, array('PENDING','PLACED'), true);
$terminal     = in_array($tx->status, array('FAILED','CANCELLED','REFUNDED'), true);
$can_refund   = !$terminal && bccomp((string)$tx->refunded_amount, (string)$tx->amount, 8) < 0;
$outstanding  = bcsub((string)$tx->amount, (string)$tx->refunded_amount, 8);
// The one card whose plaintext this response is allowed to render, if any.
$plain_id     = $plain ? (int)$plain['card_id'] : 0;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/giftcards')?>">← All gift cards</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=$brand ? htmlspecialchars($brand->name) : 'Gift card'?>
      <span class="mono text-xs"><?=htmlspecialchars($tx->public_id)?></span>
    </h2>
    <p class="muted text-sm">
      <span class="<?=DashboardStats::status_badge($tx->status)?>"><?=htmlspecialchars($tx->status)?></span>
      bought <?=htmlspecialchars((string)$tx->created_at)?> via <?=htmlspecialchars((string)$tx->source)?>
    </p>
  </div>
</div>

<?php if ($order_status === 'PLACED'): ?>
<div class="alert alert-warning mb-4">
  The vendor accepted this order and has not issued the code yet
  (<?=$order ? (int)$order->code_attempts : 0?> attempt(s) so far). The customer
  has paid and has nothing to spend. It is collected automatically every couple
  of minutes, and written off — refunding the customer — if nothing arrives
  within <?=(int)$give_up_minutes?> minutes. We are billed by the vendor either way.
</div>
<?php endif; ?>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Order</h3>
    <table class="table">
      <tbody>
        <tr><th>Customer</th><td><?=htmlspecialchars((string)$tx->username)?>
            <span class="muted text-xs"><?=htmlspecialchars((string)$tx->email)?></span></td></tr>
        <tr><th>Card</th><td><?=$product ? htmlspecialchars($product->name) : htmlspecialchars((string)$tx->service_type)?></td></tr>
        <tr><th>Denomination</th><td class="mono">
          <?php if ($order && $order->face_value !== null): ?>
            <?=htmlspecialchars($order->recipient_currency)?>
            <?=htmlspecialchars(rtrim(rtrim((string)$order->face_value, '0'), '.'))?>
            × <?=(int)$order->quantity?>
          <?php else: ?>—<?php endif; ?>
        </td></tr>
        <tr><th>Delivery</th><td>
          <span class="badge <?=$order_badge[$order_status] ?? 'badge-muted'?>">
            <?=htmlspecialchars($order_status)?></span>
          <?php if ($order && $order->delivered_at): ?>
            <div class="text-xs muted">delivered <?=htmlspecialchars((string)$order->delivered_at)?> UTC</div>
          <?php elseif ($order && $order->placed_at): ?>
            <div class="text-xs muted">placed <?=htmlspecialchars((string)$order->placed_at)?> UTC</div>
          <?php endif; ?>
        </td></tr>
        <tr><th>Emailed to</th><td class="text-xs">
          <?=$order && $order->recipient_email
              ? htmlspecialchars((string)$order->recipient_email)
              : '<span class="muted">not emailed — dashboard only</span>'?>
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
          <?php if ($order && $order->provider_order_id): ?>
            <div class="mono text-xs muted"><?=htmlspecialchars((string)$order->provider_order_id)?></div>
          <?php endif; ?>
        </td></tr>
        <tr><th>Code access</th><td class="text-xs">
          <?php if ($order && (int)$order->reveal_count > 0): ?>
            Opened <?=(int)$order->reveal_count?> time(s); last
            <?=htmlspecialchars((string)$order->last_revealed_at)?> UTC
          <?php else: ?><span class="muted">never opened</span><?php endif; ?>
        </td></tr>
        <?php if ($order && $order->failure_reason): ?>
        <tr><th>Outcome note</th><td class="text-xs"><?=htmlspecialchars((string)$order->failure_reason)?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Codes</h3>

    <?php if (empty($cards)): ?>
      <p class="muted text-sm">
        <?=$order_status === 'PLACED'
            ? 'No code has been issued for this order yet.'
            : 'There are no codes on this order.'?>
      </p>
    <?php elseif (!$has('giftcards.reveal')): ?>
      <p class="muted text-sm">
        <?=count($cards)?> code(s) are stored for this order. You do not have
        permission to open them.
      </p>
    <?php else: ?>
      <div class="alert alert-warning mb-4 text-sm">
        A gift card code is money to whoever holds it. Open one only if the
        customer cannot, and expect your name against it in the audit log.
      </div>
      <?php foreach ($cards as $c): ?>
        <div class="card mb-2" style="padding:.75rem">
          <div class="row justify-between" style="align-items:center;gap:.5rem;flex-wrap:wrap">
            <div>
              <div class="text-sm font-medium">Card <?=(int)$c->card_index?> of <?=count($cards)?></div>
              <div class="mono text-xs muted">
                <?=$c->card_last4 ? '•••• '.htmlspecialchars($c->card_last4) : 'PIN only'?>
              </div>
              <?php if ($c->revealed_at): ?>
                <div class="text-xs muted">first opened <?=htmlspecialchars((string)$c->revealed_at)?> UTC</div>
              <?php endif; ?>
            </div>
            <?php if ($plain_id !== (int)$c->id): ?>
            <form method="post"
                  action="<?=site_url('admin/giftcards/'.$tx->public_id.'/reveal/'.$c->public_id)?>"
                  data-confirm="Open this gift card code? Your access will be logged." >
              <?=$csrf()?>
              <button class="btn btn-secondary btn-sm" type="submit">Reveal code</button>
            </form>
            <?php endif; ?>
          </div>
          <?php if ($plain_id === (int)$c->id): ?>
            <table class="table mt-2">
              <tbody>
                <?php if (!empty($plain['card_number'])): ?>
                <tr><th>Code</th><td class="mono"><?=htmlspecialchars((string)$plain['card_number'])?></td></tr>
                <?php endif; ?>
                <?php if (!empty($plain['pin'])): ?>
                <tr><th>PIN</th><td class="mono"><?=htmlspecialchars((string)$plain['pin'])?></td></tr>
                <?php endif; ?>
                <?php if (!empty($plain['redemption_url'])): ?>
                <tr><th>Redeem at</th>
                    <td class="text-xs"><?=htmlspecialchars((string)$plain['redemption_url'])?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-4">
  <h3 class="text-sm font-semibold mb-2">Actions</h3>
  <?php if (!$has('giftcards.refund') && !$has('giftcards.manage')): ?>
    <p class="muted text-sm">You have read-only access to gift card orders.</p>
  <?php else: ?>

    <?php if ($has('giftcards.manage') && $open && $order && $order->provider_order_id): ?>
    <form method="post" action="<?=site_url('admin/giftcards/'.$tx->public_id.'/collect')?>" class="mb-4">
      <?=$csrf()?>
      <p class="hint mb-2">
        Ask the vendor for the code right now instead of waiting for the next
        sweep. Safe to press more than once — codes are only ever stored once.
      </p>
      <button class="btn btn-secondary btn-sm" type="submit">Collect codes now</button>
    </form>
    <?php endif; ?>

    <?php if ($has('giftcards.refund') && $open && empty($cards)): ?>
    <form method="post" action="<?=site_url('admin/giftcards/'.$tx->public_id.'/abandon')?>" class="mb-4"
          data-confirm="Write this order off and refund <?=htmlspecialchars(marvy_money($outstanding, $tx->currency))?> to the customer?" >
      <?=$csrf()?>
      <label class="text-sm font-medium" for="abandon-reason">Write-off reason</label>
      <input class="input mb-2" id="abandon-reason" name="reason" placeholder="Recorded in the status history">
      <p class="hint mb-2">
        For an order the vendor took and never filled. Refunds
        <?=marvy_money($outstanding, $tx->currency)?> to the customer; the vendor
        has still charged us, so this is a real loss worth raising with them.
      </p>
      <button class="btn btn-secondary btn-sm" type="submit">Write off and refund</button>
    </form>
    <?php endif; ?>

    <?php if ($has('giftcards.refund') && $can_refund && !$open): ?>
    <form method="post" action="<?=site_url('admin/giftcards/'.$tx->public_id.'/refund')?>" class="mb-4"
          data-confirm="Refund <?=htmlspecialchars(marvy_money($outstanding, $tx->currency))?> to this customer&#39;s wallet?" >
      <?=$csrf()?>
      <label class="text-sm font-medium" for="reason">Refund reason</label>
      <input class="input mb-2" id="reason" name="reason" placeholder="Recorded in the status history">
      <p class="hint mb-2">
        Returns <?=marvy_money($outstanding, $tx->currency)?> — the charge less
        anything already refunded.
        <?php if (!empty($cards)): ?>
          <strong>This order has delivered codes.</strong> Refunding does not
          claw them back, and the customer may already have spent them.
        <?php endif; ?>
      </p>
      <button class="btn btn-secondary btn-sm" type="submit">Refund purchase</button>
    </form>
    <?php elseif ($has('giftcards.refund') && !$can_refund && !$open): ?>
    <p class="muted text-sm mb-4">Nothing left to refund on this purchase.</p>
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
    <p class="muted text-sm">The vendor was never called for this order.</p>
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
