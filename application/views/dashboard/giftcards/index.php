<?php defined('BASEPATH') OR exit('No direct script access allowed');
// The catalogue drives the form. Denominations are grouped by brand because
// that is how a customer shops — "an Amazon card" first, "how much" second.
$by_brand = array();
foreach ($products as $p) $by_brand[$p->brand_name][] = $p;
ksort($by_brand);
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <h2 class="card-title mb-0">Buy a gift card</h2>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/giftcards/history')?>">My cards</a>
  </div>

  <?php $this->load->view('dashboard/giftcards/_flash'); ?>

  <p class="muted text-sm mb-4">Wallet balance:
    <strong><?=windels_money($wallet->balance)?></strong></p>

  <?php if (empty($products)): ?>
    <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'gift',
        'title' => 'Gift cards are not on sale yet',
        'body'  => 'As soon as the operator prices gift card products, they will appear here ready to buy from your wallet.',
    )); ?>
  <?php else: ?>

  <form method="post" action="<?=site_url('dashboard/giftcards/buy')?>">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
           value="<?=$this->security->get_csrf_hash()?>">
    <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('gc', true))?>">

    <label class="label" for="product">Which card?</label>
    <select class="select mb-4" id="product" name="product" required>
      <?php foreach ($by_brand as $brand => $rows): ?>
        <optgroup label="<?=htmlspecialchars((string)$brand)?>">
        <?php foreach ($rows as $p): ?>
          <option value="<?=htmlspecialchars($p->code)?>"
                  <?=($selected === $p->code) ? 'selected' : ''?>>
            <?=htmlspecialchars($p->name)?>
            — <?=windels_money($p->price)?>
          </option>
        <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>

    <label class="label" for="quantity">How many?</label>
    <input class="input mb-2" id="quantity" name="quantity" type="number"
           value="1" min="1" max="5" required inputmode="numeric"
           aria-describedby="quantity-hint">
    <p class="hint mb-4" id="quantity-hint">
      Each card is issued separately, so you can pass them on individually.
      Some cards have a lower per-order limit.
    </p>

    <label class="label" for="recipient_email">Email a copy to (optional)</label>
    <input class="input mb-2" id="recipient_email" name="recipient_email"
           type="email" autocomplete="off" placeholder="someone@example.com"
           aria-describedby="email-hint">
    <p class="hint mb-4" id="email-hint">
      Leave this blank to keep the card to yourself — it is always available
      here either way.
    </p>

    <p class="hint mb-4">
      You are charged when the order is placed. Codes usually arrive within a
      minute; if the card is never issued, the charge is refunded automatically.
    </p>

    <button class="btn btn-primary" type="submit" data-loading-text="Placing order…">Buy card</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!empty($brands)): ?>
<div class="card mt-4">
  <h3 class="card-title">Brands on sale</h3>
  <div class="overflow-x-auto">
    <table class="table">
      <tbody>
      <?php foreach ($brands as $b): ?>
        <tr>
          <th><?=htmlspecialchars($b->name)?></th>
          <td class="text-sm muted"><?=htmlspecialchars((string)$b->redeem_instructions)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card mt-4">
  <h3 class="card-title">How your codes are handled</h3>
  <ul class="text-sm muted" style="line-height:1.7">
    <li>Your codes are encrypted here and are shown only when you ask for them.</li>
    <li>Each time a code is opened, we record who opened it and when.</li>
    <li>Codes are never deleted on a schedule — the card is yours until you spend it.</li>
    <li>Treat a code like cash: anyone who sees it can spend it.</li>
  </ul>
</div>
