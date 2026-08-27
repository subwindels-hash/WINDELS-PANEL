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
          <h2 class="card-title">Transaction PIN</h2>
          <p class="muted text-sm">
            A four-digit PIN, separate from your password, for confirming sensitive actions.
          </p>
        </div>
        <?php if (!empty($pin_set)): ?>
          <span class="badge badge-success badge-dot">Set</span>
        <?php else: ?>
          <span class="badge badge-default">Not set</span>
        <?php endif; ?>
      </div>

      <?php if (!empty($pin_locked)): ?>
      <div class="alert alert-warning mt-3">
        Your PIN is locked after repeated incorrect attempts. It unlocks automatically in
        about <?=max(1, (int)ceil($pin_locked / 60))?> minute<?=((int)ceil($pin_locked / 60) === 1) ? '' : 's'?>,
        or support can lift the lock for you.
      </div>
      <?php endif; ?>

      <?=form_open('dashboard/security', array('class'=>'mt-4 stack'))?>
        <input type="hidden" name="action" value="set_pin">
        <?php if (!empty($pin_set)): ?>
        <label class="field">
          <span class="label">Current PIN</span>
          <input class="input mono" type="password" name="current_pin" required
                 inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off">
        </label>
        <?php endif; ?>
        <label class="field">
          <span class="label"><?=!empty($pin_set) ? 'New PIN' : 'Choose a PIN'?></span>
          <input class="input mono" type="password" name="new_pin" required
                 inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off">
          <span class="hint">Exactly four digits. Avoid 1111 or 1234 — they are rejected.</span>
        </label>
        <label class="field">
          <span class="label">Confirm PIN</span>
          <input class="input mono" type="password" name="confirm_pin" required
                 inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off">
        </label>
        <div>
          <button class="btn btn-primary" type="submit">
            <?=!empty($pin_set) ? 'Update PIN' : 'Set PIN'?>
          </button>
        </div>
        <p class="muted text-xs">
          Your PIN is stored as a one-way hash. Nobody — including our staff — can read it. If you forget it,
          support can clear it so you can choose a new one.
        </p>
      <?=form_close()?>
    </div>

    <div class="card" id="ws-mfa-section"
         data-endpoint-setup="<?=htmlspecialchars(site_url('auth/mfa/setup'))?>"
         data-endpoint-confirm="<?=htmlspecialchars(site_url('auth/mfa/confirm'))?>"
         data-endpoint-disable="<?=htmlspecialchars(site_url('auth/mfa/disable'))?>">
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

      <?php if (empty($mfa)): ?>
      <div id="ws-mfa-enable">
        <p class="hint mt-2">Two-factor authentication is enforced at login once enabled.</p>
        <button type="button" class="btn btn-primary mt-3" id="ws-mfa-start">Enable two-factor authentication</button>

        <div id="ws-mfa-enroll" hidden class="mt-4">
          <p class="muted text-sm">Scan the QR code with your authenticator app (Google Authenticator, Authy, 1Password, &hellip;), or enter the secret key manually.</p>
          <div class="row" style="gap:1.5rem;align-items:flex-start">
            <div id="ws-mfa-qr" style="background:#fff;border:1px solid var(--slate-200);border-radius:.75rem;padding:.75rem;display:inline-block"></div>
            <div style="flex:1;min-width:220px">
              <label class="label" for="ws-mfa-secret">Secret key</label>
              <div class="row" style="gap:.4rem;align-items:center">
                <code id="ws-mfa-secret" class="mono" style="word-break:break-all;font-size:.85rem"></code>
                <button type="button" class="btn btn-ghost btn-sm" id="ws-mfa-copy-secret">Copy</button>
              </div>
              <label class="label mt-3" for="ws-mfa-code">Verification code</label>
              <input class="input" id="ws-mfa-code" inputmode="numeric" autocomplete="one-time-code"
                     maxlength="6" pattern="[0-9]*" placeholder="6-digit code">
              <div class="row mt-2" style="gap:.5rem">
                <button type="button" class="btn btn-primary btn-sm" id="ws-mfa-confirm">Verify &amp; enable</button>
                <button type="button" class="btn btn-ghost btn-sm" id="ws-mfa-cancel">Cancel</button>
              </div>
              <p class="form-error" id="ws-mfa-error" hidden></p>
            </div>
          </div>

          <div class="mt-4">
            <h3 class="card-meta" style="margin:0 0 .5rem;text-transform:uppercase;letter-spacing:.05em">Recovery codes</h3>
            <p class="hint">Store these somewhere safe. Each can be used once to sign in if you lose your device.</p>
            <div id="ws-mfa-recovery" class="row" style="gap:.4rem"></div>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div id="ws-mfa-disable">
        <p class="hint mt-2">Two-factor authentication is enforced at login while enabled.</p>
        <button type="button" class="btn btn-secondary mt-3" id="ws-mfa-disable-btn">Disable two-factor authentication</button>
        <div id="ws-mfa-disable-confirm" hidden class="mt-3">
          <label class="label" for="ws-mfa-disable-code">Enter a code from your authenticator app to confirm</label>
          <div class="row" style="gap:.5rem">
            <input class="input" id="ws-mfa-disable-code" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="6" pattern="[0-9]*" placeholder="6-digit code" style="max-width:10rem">
            <button type="button" class="btn btn-danger btn-sm" id="ws-mfa-disable-confirm-btn">Disable</button>
          </div>
          <p class="form-error" id="ws-mfa-disable-error" hidden></p>
        </div>
      </div>
      <?php endif; ?>
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

  <script src="<?=base_url('assets/js/qrcode.js')?>" defer></script>

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
