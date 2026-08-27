<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<form method="get" class="table-toolbar">
  <input class="input" type="search" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>" placeholder="Username or email">
  <select class="select" name="role">
    <option value="">All admins</option>
    <option value="SUPER_ADMIN" <?=(($filters['role'] ?? '')==='SUPER_ADMIN')?'selected':''?>>SUPER_ADMIN</option>
    <option value="ADMIN" <?=(($filters['role'] ?? '')==='ADMIN')?'selected':''?>>ADMIN</option>
  </select>
  <button class="btn btn-secondary" type="submit">Filter</button>
</form>
<div class="card">
  <?php if (empty($staff)): ?>
    <p class="muted mb-0">No administrator accounts match.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($staff as $u): ?>
      <tr>
        <td><?=htmlspecialchars($u->username)?></td>
        <td><?=htmlspecialchars($u->email)?></td>
        <td><span class="badge badge-brand"><?=htmlspecialchars($u->role)?></span></td>
        <td><?=htmlspecialchars($u->status)?></td>
        <td><a href="<?=site_url('admin/customers/'.$u->public_id)?>">Open file</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
