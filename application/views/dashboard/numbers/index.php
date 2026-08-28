<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <h2 class="card-title mb-0">Rent a virtual number</h2>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/numbers/history')?>">History</a>
  </div>

  <?php $this->load->view('dashboard/numbers/_flash'); ?>

  <p class="muted text-sm mb-4">Wallet balance:
    <strong><?=marvy_money($wallet->balance)?></strong></p>

  <?php if (empty($countries) || empty($products)): ?>
    <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'smartphone',
        'title' => 'No virtual numbers on sale yet',
        'body'  => 'Once the operator prices virtual numbers, you will be able to rent one for SMS verification here.',
    )); ?>
  <?php else: ?>

  <form method="get" action="<?=site_url('dashboard/numbers')?>" class="mb-4">
    <label class="label" for="country">Country</label>
    <select class="select" id="country" name="country" data-autosubmit >
      <?php foreach ($countries as $c): ?>
        <option value="<?=htmlspecialchars($c->code)?>"
          <?=($country && $country->code === $c->code) ? 'selected' : ''?>>
          <?=htmlspecialchars(trim(($c->flag_emoji ?? '').' '.$c->name))?>
          <?=$c->dial_prefix ? '('.htmlspecialchars($c->dial_prefix).')' : ''?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-secondary btn-sm mt-2" type="submit">Change country</button></noscript>
  </form>

  <form method="post" action="<?=site_url('dashboard/numbers/rent')?>">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
           value="<?=$this->security->get_csrf_hash()?>">
    <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('nr', true))?>">
    <input type="hidden" name="country" value="<?=htmlspecialchars($country->code)?>">

    <label class="label" for="service">Service</label>
    <select class="select mb-4" id="service" name="service" required>
      <?php foreach ($products as $p): ?>
        <option value="<?=htmlspecialchars($p->service_code)?>">
          <?=htmlspecialchars($p->service_name)?> — <?=marvy_money($p->price)?>
          <?php if ($p->stock !== null): ?>(<?=number_format((int)$p->stock)?> available)<?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <p class="hint mb-4">
      You are charged when the number is reserved. If no code arrives before it
      expires, the charge is refunded automatically.
    </p>

    <?php // Module 36: one code works on every purchase in the panel. ?>
    <label class="label" for="coupon_code">Coupon code (optional)</label>
    <input class="input mb-4" id="coupon_code" name="coupon_code" maxlength="32"
           autocomplete="off" style="text-transform:uppercase" placeholder="Promo code">

    <button class="btn btn-primary" type="submit">Rent a number</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!empty($active)): ?>
<div class="card mt-4">
  <h3 class="card-title">Your live numbers</h3>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead><tr><th>Number</th><th>Status</th><th>Codes</th><th>Expires</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($active as $row): $n = $row['number']; ?>
        <tr>
          <td class="mono"><?=htmlspecialchars($n->msisdn)?></td>
          <td><span class="badge <?=$n->status === 'RECEIVED' ? 'badge-success' : 'badge-warning'?>">
            <?=htmlspecialchars($n->status)?></span></td>
          <td><?=number_format((int)$n->sms_count)?></td>
          <td class="text-sm muted"><?=htmlspecialchars((string)$n->expires_at)?> UTC</td>
          <td><a class="btn btn-primary btn-sm"
                 href="<?=site_url('dashboard/numbers/'.$row['tx']->public_id)?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
