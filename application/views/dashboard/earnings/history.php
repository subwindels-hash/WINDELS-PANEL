<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between mb-4">
  <div>
    <a class="text-xs muted" href="<?=site_url('dashboard/earnings')?>">← Earnings</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Earnings history</h2>
  </div>
</div>

<div class="card">
  <?php if (!$entries): ?>
    <p class="muted text-sm">Nothing here yet.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Reference</th><th>Source</th><th>Description</th><th>Status</th><th class="text-right">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($entries as $e): ?>
      <tr>
        <td class="text-xs"><?=htmlspecialchars(date('j M Y H:i', strtotime($e->created_at)))?></td>
        <td class="mono text-xs"><?=htmlspecialchars($e->public_id)?></td>
        <td><?=htmlspecialchars(ucfirst(strtolower($e->source)))?></td>
        <td class="text-xs muted"><?=htmlspecialchars((string)$e->description)?></td>
        <td><span class="badge badge-default"><?=htmlspecialchars($e->status)?></span></td>
        <td class="mono text-right"><?=marvy_money($e->amount)?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted text-xs mt-2"><?=number_format($total)?> entries.</p>
  <?php endif; ?>
</div>
