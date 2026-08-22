<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-3 max-w-5xl">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <h2 class="card-title">Change password</h2>
      <?=form_open('dashboard/security', array('class'=>'mt-4 stack'))?>
        <input type="hidden" name="action" value="change_password">
        <label class="field">
          <span class="label">Current password</span>
          <input class="input" type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label class="field">
          <span class="label">New password</span>
          <input class="input" type="password" name="new_password" required minlength="8" autocomplete="new-password">
          <span class="hint">At least 8 characters.</span>
        </label>
        <label class="field">
          <span class="label">Confirm new password</span>
          <input class="input" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </label>
        <div><button class="btn btn-primary" type="submit">Update password</button></div>
      <?=form_close()?>
    </div>

    <div class="card">
      <div class="row justify-between">
        <div>
          <h2 class="card-title">Two-factor authentication</h2>
          <p class="muted text-sm">Use an authenticator app for an extra layer of security.</p>
        </div>
        <?php if (!empty($mfa)): ?>
          <span class="badge badge-success badge-dot">Enabled</span>
        <?php else: ?>
          <span class="badge badge-default">Not enabled</span>
        <?php endif; ?>
      </div>
      <p class="hint mt-3">Two-factor authentication is enforced at login once enabled.</p>
    </div>

    <div class="card">
      <h2 class="card-title">Active API keys</h2>
      <?php if (empty($keys)): ?>
        <p class="muted mt-2">No API keys yet. <a href="<?=site_url('dashboard/api')?>">Create one</a>.</p>
      <?php else: ?>
        <div class="overflow-x-auto mt-3">
          <table class="table">
            <thead><tr><th>Name</th><th>Prefix</th><th>Created</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): ?>
              <tr>
                <td><?=htmlspecialchars($k->name)?></td>
                <td class="mono"><?=htmlspecialchars($k->prefix)?>…</td>
                <td class="text-xs muted"><?=date('M j, Y', strtotime($k->created_at))?></td>
                <td><?=$k->revoked_at ? '<span class="badge badge-default">revoked</span>' : '<span class="badge badge-success badge-dot">active</span>'?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <aside class="card">
    <h3 class="card-title">Last login</h3>
    <dl class="stack" style="gap:.5rem">
      <div><dt class="muted text-xs">When</dt><dd class="text-sm"><?=$current_user->last_login_at ? date('M j, Y H:i', strtotime($current_user->last_login_at)).' UTC' : '—'?></dd></div>
      <div><dt class="muted text-xs">IP</dt><dd class="mono text-sm"><?=htmlspecialchars($current_user->last_login_ip ?: '—')?></dd></div>
    </dl>
    <form method="post" action="<?=site_url('logout')?>" class="mt-4">
      <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
      <button class="btn btn-secondary btn-block btn-sm" type="submit">Log out everywhere</button>
    </form>
  </aside>
</div>
