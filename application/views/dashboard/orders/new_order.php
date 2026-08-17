<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 card">
    <h2 class="card-title">Place a new order</h2>
    <p class="muted">The full order form — service picker, live pricing, link validation,
      blacklist checks and wallet charge — ships with the order engine (Session 09).</p>

    <form class="mt-4 stack" onsubmit="return false">
      <label class="field">
        <span class="label">Service</span>
        <select class="select" disabled>
          <?php if (!empty($services)): ?>
            <?php foreach (array_slice($services,0,8) as $s): ?>
              <option><?=htmlspecialchars($s->name)?></option>
            <?php endforeach; ?>
          <?php else: ?>
            <option>No services available yet</option>
          <?php endif; ?>
        </select>
      </label>
      <label class="field">
        <span class="label">Link</span>
        <input class="input" type="url" placeholder="https://instagram.com/yourhandle" disabled>
      </label>
      <label class="field">
        <span class="label">Quantity</span>
        <input class="input" type="number" min="1" value="100" disabled>
      </label>
      <button class="btn btn-primary" disabled>Place order (coming soon)</button>
    </form>
  </div>

  <aside class="card">
    <h3 class="card-title">Order summary</h3>
    <p class="text-sm muted">Pricing is frozen at checkout and charged through the double-entry wallet ledger.</p>
    <dl class="mt-4 stack" style="gap:.5rem">
      <div class="row justify-between"><span class="muted">Charge</span><strong>—</strong></div>
      <div class="row justify-between"><span class="muted">Balance</span><strong><?=windels_money($wallet->balance ?? '0', $wallet->currency ?? 'USD')?></strong></div>
    </dl>
    <a class="btn btn-secondary btn-block mt-4" href="<?=site_url('dashboard/add-funds')?>">Add funds first →</a>
  </aside>
</div>
