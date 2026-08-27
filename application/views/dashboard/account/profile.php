<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
$avatar    = $current_user->avatar_url ?? null;
$initial   = strtoupper(substr($current_user->username ?? 'U', 0, 1));
?>
<div class="grid gap-6 lg:grid-cols-3 max-w-5xl">
  <div class="lg:col-span-2 space-y-6">

    <div class="card">
      <h2 class="card-title">Profile picture</h2>
      <p class="muted">A JPEG, PNG, GIF or WebP image. It appears in the panel next to your name.</p>
      <div class="row mt-4" style="gap:1rem;align-items:center;flex-wrap:wrap">
        <?php if ($avatar): ?>
          <img src="<?=htmlspecialchars($avatar)?>" alt="Your profile picture"
               width="72" height="72"
               style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--slate-200,#e2e8f0)">
        <?php else: ?>
          <div class="ws-avatar-initial" style="width:72px;height:72px;font-size:1.75rem"><?=htmlspecialchars($initial)?></div>
        <?php endif; ?>

        <form method="post" action="<?=site_url('dashboard/profile')?>" enctype="multipart/form-data"
              class="row" style="gap:.5rem;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
          <input type="hidden" name="action" value="avatar">
          <input class="input" type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" required>
          <button class="btn btn-primary" type="submit">Upload</button>
        </form>

        <?php if ($avatar): ?>
          <form method="post" action="<?=site_url('dashboard/profile')?>"
                data-confirm="Remove your profile picture?">
            <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
            <input type="hidden" name="action" value="avatar_remove">
            <button class="btn btn-ghost" type="submit">Remove</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2 class="card-title">Your details</h2>
      <p class="muted">This information appears on your orders and invoices. Changing your email address means confirming it again.</p>

      <?=form_open('dashboard/profile', array('class'=>'mt-4 grid', 'style'=>'grid-template-columns:1fr 1fr;gap:1rem'))?>
        <label class="field">
          <span class="label">Username</span>
          <input class="input" name="username" required minlength="3" maxlength="64"
                 value="<?=htmlspecialchars(set_value('username', $current_user->username))?>">
          <span class="hint">Letters, numbers, dashes and underscores.</span>
        </label>
        <label class="field">
          <span class="label">Email</span>
          <input class="input" type="email" name="email" required maxlength="255"
                 value="<?=htmlspecialchars(set_value('email', $current_user->email))?>">
          <span class="hint">Order notices and password resets go here.</span>
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
            <?php foreach (array('UTC','Africa/Lagos','Africa/Accra','Africa/Nairobi','Africa/Johannesburg','America/New_York','America/Chicago','America/Los_Angeles','Europe/London','Europe/Berlin','Asia/Kolkata','Asia/Dubai','Asia/Singapore','Australia/Sydney') as $tz): ?>
              <option value="<?=htmlspecialchars($tz)?>" <?=($current_user->timezone === $tz) ? 'selected' : ''?>><?=htmlspecialchars($tz)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="label">Language</span>
          <select class="select" name="locale">
            <?php foreach (array('en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'pt' => 'Português') as $code => $label): ?>
              <option value="<?=htmlspecialchars($code)?>" <?=($current_user->locale === $code) ? 'selected' : ''?>><?=htmlspecialchars($label)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div style="grid-column:1/-1" class="row" style="gap:.5rem">
          <button class="btn btn-primary" type="submit">Save changes</button>
          <a class="btn btn-ghost" href="<?=site_url('dashboard/security')?>">Password &amp; security →</a>
        </div>
      <?=form_close()?>
    </div>
  </div>

  <aside class="card" style="align-self:flex-start">
    <h3 class="card-title">Account</h3>
    <dl class="stack" style="gap:.5rem">
      <div><dt class="muted text-xs">Role</dt><dd class="font-medium"><?=htmlspecialchars($current_user->role)?></dd></div>
      <div><dt class="muted text-xs">Referral code</dt><dd class="mono"><?=htmlspecialchars($current_user->referral_code)?></dd></div>
      <div><dt class="muted text-xs">Email verified</dt>
        <dd><?php if ($current_user->email_verified_at): ?><span class="badge badge-success">Verified</span><?php else: ?><span class="badge badge-warning">Pending</span><?php endif; ?></dd>
      </div>
      <div><dt class="muted text-xs">Member since</dt><dd class="text-sm"><?=date('M j, Y', strtotime($current_user->created_at))?></dd></div>
    </dl>
  </aside>
</div>
