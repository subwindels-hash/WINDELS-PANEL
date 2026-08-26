<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card">
  <h2 class="card-title">Buy data</h2>
  <?php $this->load->view('dashboard/vtu/_tabs', array('tab'=>$tab,'tabs'=>$tabs)); ?>
  <?php $this->load->view('dashboard/vtu/_flash'); ?>

  <p class="muted text-sm mb-4">Wallet balance:
    <strong><?=marvy_money($wallet->balance)?></strong></p>

  <?php if (empty($networks)): ?>
    <p class="muted">No data networks are configured yet.</p>
  <?php else: ?>
  <form method="post" action="<?=site_url('dashboard/vtu/buy/data')?>">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
           value="<?=$this->security->get_csrf_hash()?>">
    <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('dt', true))?>">

    <label class="label" for="network">Network</label>
    <select class="select mb-4" id="network" name="network" required>
      <?php foreach ($networks as $n): ?>
        <option value="<?=htmlspecialchars($n->code)?>"><?=htmlspecialchars($n->name)?></option>
      <?php endforeach; ?>
    </select>

    <label class="label" for="product">Bundle</label>
    <select class="select mb-4" id="product" name="product" required>
      <?php foreach ($products as $p): ?>
        <option value="<?=htmlspecialchars($p->code)?>">
          <?=htmlspecialchars($p->name)?>
          <?php if ($p->price !== null): ?> — <?=marvy_money($p->price)?><?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label class="label" for="msisdn">Phone number</label>
    <input class="input mb-4" id="msisdn" name="msisdn" inputmode="tel"
           placeholder="08031234567" required>

    <button class="btn btn-primary" type="submit">Buy data</button>
  </form>
  <?php endif; ?>
</div>
