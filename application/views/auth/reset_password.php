<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Set a new password</h1>
<p class="mt-1 text-sm text-slate-500">Choose a new password of at least 8 characters. This link works once.</p>

<?=form_open('reset-password/'.($token ?? ''), array('class' => 'mt-6 space-y-4'))?>
  <div>
    <label for="password" class="label">New password</label>
    <div class="ws-password">
      <input id="password" name="password" type="password" class="input" autocomplete="new-password" required minlength="8" autofocus>
      <button type="button" class="ws-password-toggle" data-password-toggle="password" aria-pressed="false">Show</button>
    </div>
    <?=form_error('password', '<p class="form-error">', '</p>')?>
  </div>
  <div>
    <label for="password_confirm" class="label">Confirm new password</label>
    <div class="ws-password">
      <input id="password_confirm" name="password_confirm" type="password" class="input" autocomplete="new-password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password_confirm" aria-pressed="false">Show</button>
    </div>
  </div>
  <button type="submit" class="btn btn-primary btn-block">Reset password</button>
<?=form_close()?>
