<?php defined('BASEPATH') OR exit('No direct script access allowed');
$status   = $order ? $order->status : 'PENDING';
$refunded = bccomp((string)$tx->refunded_amount, '0', 8) > 0;
$badge = array('DELIVERED'=>'badge-success','PLACED'=>'badge-warning',
               'PENDING'=>'badge-muted','FAILED'=>'badge-error','CANCELLED'=>'badge-muted');
// The one card whose plaintext this response is allowed to render, if any.
$plain_id = $plain ? (int)$plain['card_id'] : 0;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('dashboard/giftcards/history')?>">← All cards</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=$brand ? htmlspecialchars($brand->name) : 'Gift card'?>
      <?php if ($order && $order->face_value !== null): ?>
        <span class="text-sm muted">
          <?=htmlspecialchars($order->recipient_currency)?>
          <?=htmlspecialchars(rtrim(rtrim((string)$order->face_value, '0'), '.'))?>
          <?=(int)$order->quantity > 1 ? '× '.(int)$order->quantity : ''?>
        </span>
      <?php endif; ?>
    </h2>
    <p class="muted text-sm"><?=htmlspecialchars((string)$tx->created_at)?> UTC</p>
  </div>
</div>

<?php $this->load->view('dashboard/giftcards/_flash'); ?>

<?php if ($status === 'PLACED'): ?>
<div class="alert alert-warning mb-4">
  Your payment went through and the card is being issued. This page updates
  once the code arrives — usually within a minute. If it never arrives, you
  are refunded automatically.
</div>
<?php elseif ($status === 'FAILED'): ?>
<div class="alert alert-error mb-4">
  This order could not be completed<?=$order && $order->failure_reason ? ': '.htmlspecialchars((string)$order->failure_reason) : '.'?>
  <?=$refunded ? ' You have been refunded '.windels_money($tx->refunded_amount, $tx->currency).'.' : ''?>
</div>
<?php endif; ?>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">This order</h3>
    <table class="table">
      <tbody>
        <tr><th>Card</th><td><?=$product ? htmlspecialchars($product->name) : htmlspecialchars((string)$tx->service_type)?></td></tr>
        <tr><th>Quantity</th><td><?=$order ? (int)$order->quantity : 1?></td></tr>
        <tr><th>Status</th><td>
          <span class="badge <?=$badge[$status] ?? 'badge-muted'?>"><?=htmlspecialchars($status)?></span>
        </td></tr>
        <tr><th>Paid</th><td class="mono"><?=windels_money($tx->amount, $tx->currency)?>
          <?php if ($refunded): ?>
            <div class="muted text-xs">refunded <?=windels_money($tx->refunded_amount, $tx->currency)?></div>
          <?php endif; ?>
        </td></tr>
        <tr><th>Reference</th><td class="mono text-xs"><?=htmlspecialchars($tx->public_id)?></td></tr>
      </tbody>
    </table>
    <?php if ($brand && $brand->redeem_instructions): ?>
      <h4 class="text-sm font-semibold mt-4 mb-2">How to redeem</h4>
      <p class="text-sm muted"><?=htmlspecialchars((string)$brand->redeem_instructions)?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Your codes</h3>

    <?php if (empty($cards)): ?>
      <p class="muted text-sm">
        <?=$status === 'PLACED'
            ? 'No code yet — the vendor is still issuing it.'
            : 'There are no codes on this order.'?>
      </p>
    <?php else: ?>
      <p class="muted text-sm mb-4">
        Codes are stored encrypted. Open one to read it — we record each time
        it is opened. Anyone who sees a code can spend it.
      </p>
      <?php foreach ($cards as $c): ?>
        <div class="card mb-2" style="padding:.75rem">
          <div class="row justify-between" style="align-items:center;gap:.5rem;flex-wrap:wrap">
            <div>
              <div class="text-sm font-medium">Card <?=(int)$c->card_index?> of <?=count($cards)?></div>
              <div class="mono text-xs muted">
                <?=$c->card_last4 ? '•••• •••• •••• '.htmlspecialchars($c->card_last4) : 'PIN only'?>
              </div>
              <?php if ($c->expires_on): ?>
                <div class="text-xs muted">expires <?=htmlspecialchars((string)$c->expires_on)?></div>
              <?php endif; ?>
            </div>
            <?php if ($plain_id !== (int)$c->id): ?>
            <form method="post"
                  action="<?=site_url('dashboard/giftcards/'.$tx->public_id.'/reveal/'.$c->public_id)?>">
              <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
                     value="<?=$this->security->get_csrf_hash()?>">
              <button class="btn btn-primary btn-sm" type="submit">Show code</button>
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
            <p class="hint mt-2">
              Copy this somewhere safe now. It is shown on this page only, and
              opening it again is recorded.
            </p>
          <?php elseif ($c->revealed_at): ?>
            <p class="hint mt-2">First opened <?=htmlspecialchars((string)$c->revealed_at)?> UTC.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
