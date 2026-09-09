<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card">
  <?php if (empty($orders)): ?>
    <p class="muted mb-0">No refunded orders yet.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Order</th><th>Customer</th><th>Service</th><th>Charge</th><th>Refunded</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a class="mono text-xs" href="<?=site_url('admin/orders/'.$o->public_id)?>"><?=htmlspecialchars($o->public_id)?></a></td>
          <td><?=htmlspecialchars((string)$o->username)?></td>
          <td><?=htmlspecialchars((string)$o->service_name)?></td>
          <td class="mono"><?=marvy_money($o->charge, $o->currency ?? null)?></td>
          <td class="mono"><?=marvy_money($o->refunded_amount, $o->currency ?? null)?></td>
          <td><span class="badge badge-default"><?=htmlspecialchars($o->status)?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
