<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card">
  <h2 class="card-title">Electricity</h2>
  <?php $this->load->view('dashboard/vtu/_tabs', array('tab'=>$tab,'tabs'=>$tabs)); ?>
  <?php $this->load->view('dashboard/vtu/_flash'); ?>

  <p class="muted text-sm mb-4">Wallet balance:
    <strong><?=marvy_money($wallet->balance)?></strong></p>

  <?php if (empty($networks)): ?>
    <p class="muted">No distribution companies are configured yet.</p>
  <?php else: ?>
  <form method="post" action="<?=site_url('dashboard/vtu/buy/electricity')?>">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
           value="<?=$this->security->get_csrf_hash()?>">
    <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('el', true))?>">

    <label class="label" for="network">Distribution company</label>
    <select class="select mb-4" id="network" name="network" required>
      <?php foreach ($networks as $n): ?>
        <option value="<?=htmlspecialchars($n->code)?>"><?=htmlspecialchars($n->name)?></option>
      <?php endforeach; ?>
    </select>

    <label class="label" for="meter_type">Meter type</label>
    <select class="select mb-4" id="meter_type" name="meter_type" required>
      <option value="PREPAID">Prepaid</option>
      <option value="POSTPAID">Postpaid</option>
    </select>

    <label class="label" for="meter">Meter number</label>
    <input class="input mb-4" id="meter" name="meter" inputmode="numeric" required>

    <label class="label" for="amount">Amount</label>
    <input class="input mb-2" id="amount" name="amount" type="number" min="500" step="1" required>
    <p class="muted text-xs mb-4">Prepaid tokens appear on your receipt as soon as the
      purchase completes.</p>

    <button class="btn btn-primary" type="submit">Buy units</button>
  </form>
  <?php endif; ?>
</div>
