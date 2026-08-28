<?php defined('BASEPATH') OR exit('No direct script access allowed');
// The catalogue drives the form: lookup_field decides what the customer is
// asked for and how the field is validated, so adding a "find NIN by phone"
// product needs no change here.
$by_type = array();
foreach ($products as $p) $by_type[$p->id_type][] = $p;
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <h2 class="card-title mb-0">Verify an identity</h2>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/identity/history')?>">History</a>
  </div>

  <?php $this->load->view('dashboard/identity/_flash'); ?>

  <p class="muted text-sm mb-4">Wallet balance:
    <strong><?=marvy_money($wallet->balance)?></strong></p>

  <?php if (empty($products)): ?>
    <p class="muted">Identity checks are not on sale yet. Check back shortly.</p>
  <?php else: ?>

  <form method="post" action="<?=site_url('dashboard/identity/verify')?>">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
           value="<?=$this->security->get_csrf_hash()?>">
    <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('id', true))?>">

    <label class="label" for="product">What do you want to check?</label>
    <select class="select mb-4" id="product" name="product" required>
      <?php foreach ($by_type as $type => $rows): ?>
        <optgroup label="<?=htmlspecialchars($type)?>">
        <?php foreach ($rows as $p): ?>
          <option value="<?=htmlspecialchars($p->code)?>"
                  <?=($selected === $p->code) ? 'selected' : ''?>>
            <?=htmlspecialchars($p->name)?> — <?=marvy_money($p->price)?>
          </option>
        <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>

    <label class="label" for="identifier">Number to check</label>
    <input class="input mb-2" id="identifier" name="identifier" required
           inputmode="numeric" autocomplete="off" maxlength="14"
           placeholder="11 digits"
           aria-describedby="identifier-hint">
    <p class="hint mb-4" id="identifier-hint">
      A NIN or BVN is exactly 11 digits. We do not store the number you enter —
      only its last four digits, so you can recognise this check later.
    </p>

    <label class="row mb-4" style="gap:.5rem;align-items:flex-start">
      <input type="checkbox" name="consent" value="1" required style="margin-top:.25rem">
      <span class="text-sm">
        I confirm I have the permission of the person this number belongs to,
        and that I am checking it for a lawful purpose.
      </span>
    </label>

    <p class="hint mb-4">
      You are charged when the check runs. If the number is not found in the
      national database, the charge is refunded automatically.
    </p>

    <?php // Module 36: one code works on every purchase in the panel. ?>
    <label class="label" for="coupon_code">Coupon code (optional)</label>
    <input class="input mb-4" id="coupon_code" name="coupon_code" maxlength="32"
           autocomplete="off" style="text-transform:uppercase" placeholder="Promo code">

    <button class="btn btn-primary" type="submit">Run check</button>
  </form>
  <?php endif; ?>
</div>

<div class="card mt-4">
  <h3 class="card-title">How your data is handled</h3>
  <ul class="text-sm muted" style="line-height:1.7">
    <li>The number you enter is sent to the verification provider and is never saved here.</li>
    <li>The result is encrypted, and only you and our support staff can open it.</li>
    <li>Every time a result is opened, we record who opened it and when.</li>
    <li>Results are deleted automatically after their retention period.</li>
    <li>We never store or display photographs returned by the provider.</li>
  </ul>
</div>
