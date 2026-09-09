<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
$can_mint_super = isset($current_user) && (string)$current_user->role === 'SUPER_ADMIN';
?>
<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Add an administrator</h3>
  <p class="muted text-xs mb-3">
    Creates a panel account that can reach the admin area. You choose the starting password and hand it
    over privately — the new administrator should change it from Account → Security on first sign-in.
    Only a super admin can create another super admin.
  </p>
  <form method="post" action="<?=site_url('admin/administrators/create')?>" class="row" style="gap:.75rem;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
    <label class="field mb-0">
      <span class="label">Username</span>
      <input class="input" name="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_-]{3,64}"
             autocomplete="off" placeholder="e.g. ops-manager">
    </label>
    <label class="field mb-0">
      <span class="label">Email</span>
      <input class="input" type="email" name="email" required maxlength="255" autocomplete="off"
             placeholder="name@example.com">
    </label>
    <label class="field mb-0">
      <span class="label">Starting password</span>
      <input class="input" type="text" name="password" required minlength="8" maxlength="72"
             autocomplete="off" spellcheck="false" placeholder="At least 8 characters">
    </label>
    <label class="field mb-0">
      <span class="label">Role</span>
      <select class="select" name="role" required>
        <option value="ADMIN" selected>ADMIN</option>
        <?php if ($can_mint_super): ?>
        <option value="SUPER_ADMIN">SUPER_ADMIN</option>
        <?php endif; ?>
      </select>
    </label>
    <button class="btn btn-primary" type="submit">Create administrator</button>
  </form>
</div>

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
        <td>
          <?=htmlspecialchars($u->username)?>
          <?php if (!empty($u->user_code)): ?>
            <div class="text-xs" title="Six-digit account number — also a valid login identifier">
              ID <span class="mono" style="letter-spacing:.15em"><?=htmlspecialchars((string)$u->user_code)?></span>
            </div>
          <?php endif; ?>
        </td>
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
