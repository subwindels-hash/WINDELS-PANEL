<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card">
  <h2 class="card-title">Buy airtime</h2>
  <?php $this->load->view('dashboard/vtu/_tabs', array('tab'=>$tab,'tabs'=>$tabs)); ?>
  <?php $this->load->view('dashboard/vtu/_flash'); ?>

  <p class="muted text-sm mb-4">Wallet balance:
    <strong><?=marvy_money($wallet->balance)?></strong></p>

  <?php if (empty($networks)): ?>
    <p class="muted">No networks are configured yet.</p>
  <?php else: ?>
  <form method="post" action="<?=site_url('dashboard/vtu/buy/airtime')?>">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
           value="<?=$this->security->get_csrf_hash()?>">
    <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('af', true))?>">

    <label class="label" for="network">Network</label>
    <select class="select mb-4" id="network" name="network" required>
      <?php foreach ($networks as $n): ?>
        <option value="<?=htmlspecialchars($n->code)?>"><?=htmlspecialchars($n->name)?></option>
      <?php endforeach; ?>
    </select>

    <label class="label" for="msisdn">Phone number</label>
    <input class="input mb-4" id="msisdn" name="msisdn" inputmode="tel"
           placeholder="08031234567" required>

    <label class="label" for="amount">Amount</label>
    <input class="input mb-4" id="amount" name="amount" type="number"
           min="50" step="1" placeholder="1000" required>

    <button class="btn btn-primary" type="submit">Buy airtime</button>
  </form>
  <?php endif; ?>
</div>
