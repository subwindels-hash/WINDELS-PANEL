<?php defined('BASEPATH') OR exit('No direct script access allowed');
$this->load->view('admin/system/_tabs');
?>
<div class="card">
  <?php if (empty($rows)): ?>
    <p class="muted mb-0">No API usage recorded yet.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>When</th><th>Key</th><th>User</th><th>Method</th><th>Endpoint</th><th>Status</th><th>ms</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-xs muted"><?=htmlspecialchars($r->created_at)?></td>
          <td class="mono text-xs"><?=htmlspecialchars((string)$r->prefix)?></td>
          <td><?=htmlspecialchars((string)$r->username)?></td>
          <td><?=htmlspecialchars((string)$r->method)?></td>
          <td class="mono text-xs"><?=htmlspecialchars($r->endpoint)?></td>
          <td><?=htmlspecialchars((string)$r->status)?></td>
          <td class="mono"><?=htmlspecialchars((string)$r->duration_ms)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
