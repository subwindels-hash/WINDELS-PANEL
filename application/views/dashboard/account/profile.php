<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-3 max-w-5xl">
  <div class="lg:col-span-2 card">
    <h2 class="card-title">Profile</h2>
    <p class="muted">This information appears on your orders and invoices.</p>

    <?=form_open('dashboard/profile', array('class'=>'mt-4 grid', 'style'=>'grid-template-columns:1fr 1fr;gap:1rem'))?>
      <label class="field">
        <span class="label">Username</span>
        <input class="input" value="<?=htmlspecialchars($current_user->username)?>" disabled>
      </label>
      <label class="field">
        <span class="label">Email</span>
        <input class="input" value="<?=htmlspecialchars($current_user->email)?>" disabled>
      </label>
      <label class="field">
        <span class="label">First name</span>
        <input class="input" name="first_name" value="<?=htmlspecialchars(set_value('first_name', $current_user->first_name))?>" maxlength="100">
      </label>
      <label class="field">
        <span class="label">Last name</span>
        <input class="input" name="last_name" value="<?=htmlspecialchars(set_value('last_name', $current_user->last_name))?>" maxlength="100">
      </label>
      <label class="field">
        <span class="label">Phone</span>
        <input class="input" name="phone" value="<?=htmlspecialchars(set_value('phone', $current_user->phone))?>" maxlength="32">
      </label>
      <label class="field">
        <span class="label">Timezone</span>
        <select class="select" name="timezone">
          <?php foreach (array('UTC','America/New_York','Europe/London','Africa/Lagos','Asia/Kolkata','Asia/Dubai','Australia/Sydney') as $tz): ?>
            <option value="<?=htmlspecialchars($tz)?>" <?=($current_user->timezone===$tz)?'selected':''?>><?=htmlspecialchars($tz)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div style="grid-column:1/-1">
        <button class="btn btn-primary" type="submit">Save changes</button>
      </div>
    <?=form_close()?>
  </div>

  <aside class="card">
    <h3 class="card-title">Account</h3>
    <dl class="stack" style="gap:.5rem">
      <div><dt class="muted text-xs">Role</dt><dd class="font-medium"><?=htmlspecialchars($current_user->role)?></dd></div>
      <div><dt class="muted text-xs">Referral code</dt><dd class="font-mono"><?=htmlspecialchars($current_user->referral_code)?></dd></div>
      <div><dt class="muted text-xs">Email verified</dt>
        <dd><?php if ($current_user->email_verified_at): ?><span class="badge badge-success">Verified</span><?php else: ?><span class="badge badge-warning">Pending</span><?php endif; ?></dd>
      </div>
      <div><dt class="muted text-xs">Member since</dt><dd class="text-sm"><?=date('M j, Y', strtotime($current_user->created_at))?></dd></div>
    </dl>
  </aside>
</div>
