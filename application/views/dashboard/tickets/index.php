<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-4xl">
  <div class="row justify-between">
    <h2 class="card-title mb-0">Support tickets</h2>
    <button class="btn btn-primary btn-sm" disabled>+ New ticket (Session 13)</button>
  </div>

  <?php if (empty($tickets)): ?>
    <p class="muted mt-4">No support tickets yet. Our team typically replies within a few hours.</p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead><tr><th>Subject</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr>
          <td><?=htmlspecialchars($t->subject)?></td>
          <td><span class="badge <?=($t->status==='OPEN'||$t->status==='PENDING')?'badge-info':'badge-default'?>"><?=htmlspecialchars($t->status)?></span></td>
          <td class="text-xs muted"><?=date('M j, Y', strtotime($t->updated_at))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
