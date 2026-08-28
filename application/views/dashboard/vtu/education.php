<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card">
  <h2 class="card-title">Exam PINs</h2>
  <?php $this->load->view('dashboard/vtu/_tabs', array('tab'=>$tab,'tabs'=>$tabs)); ?>
  <?php $this->load->view('dashboard/vtu/_flash'); ?>

  <p class="muted text-sm mb-4">Wallet balance:
    <strong><?=marvy_money($wallet->balance)?></strong></p>

  <?php if (empty($networks)): ?>
    <p class="muted">No exam bodies are configured yet.</p>
  <?php else: ?>
  <form method="post" action="<?=site_url('dashboard/vtu/buy/education')?>">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
           value="<?=$this->security->get_csrf_hash()?>">
    <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('ed', true))?>">

    <label class="label" for="network">Exam body</label>
    <select class="select mb-4" id="network" name="network" required>
      <?php foreach ($networks as $n): ?>
        <option value="<?=htmlspecialchars($n->code)?>"><?=htmlspecialchars($n->name)?></option>
      <?php endforeach; ?>
    </select>

    <label class="label" for="product">PIN type</label>
    <select class="select mb-4" id="product" name="product" required>
      <?php foreach ($products as $p): ?>
        <option value="<?=htmlspecialchars($p->code)?>">
          <?=htmlspecialchars($p->name)?>
          <?php if ($p->price !== null): ?> — <?=marvy_money($p->price)?><?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label class="label" for="quantity">Quantity</label>
    <input class="input mb-4" id="quantity" name="quantity" type="number"
           min="1" max="10" value="1" required>
    <?php // Module 36: one code works on every purchase in the panel. ?>
    <label class="label" for="coupon_code_<?=$tab?>">Coupon code (optional)</label>
    <input class="input mb-4" id="coupon_code_<?=$tab?>" name="coupon_code" maxlength="32"
           autocomplete="off" style="text-transform:uppercase" placeholder="Promo code">

    <button class="btn btn-primary" type="submit">Buy PIN</button>
  </form>
  <?php endif; ?>
</div>
