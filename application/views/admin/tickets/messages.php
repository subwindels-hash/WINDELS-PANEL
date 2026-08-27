<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card">
  <?php if (empty($rows)): ?>
    <p class="muted mb-0">No visitor messages yet. Signed-in customers appear under Support tickets.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>When</th><th>From</th><th>Subject</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-xs muted"><?=htmlspecialchars($r->created_at)?></td>
          <td><?=htmlspecialchars($r->to_name ?: $r->to_email)?></td>
          <td><?=htmlspecialchars($r->subject)?></td>
          <td><span class="badge badge-default"><?=htmlspecialchars($r->status)?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<p class="muted text-sm"><a href="<?=site_url('admin/tickets')?>">← Support tickets</a></p>
