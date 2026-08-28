<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Log in to your account</h1>
<p class="mt-1 text-sm text-slate-500">Enter the email or username you registered with.</p>

<?=form_open('login', array('class' => 'mt-6 space-y-4', 'autocomplete' => 'on'))?>
  <div>
    <label for="identifier" class="label">Email or username</label>
    <input id="identifier" name="identifier" type="text" class="input" autocomplete="username" required autofocus
           value="<?=htmlspecialchars(set_value('identifier'))?>">
    <?=form_error('identifier', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <div class="flex items-center justify-between">
      <label for="password" class="label" style="margin-bottom:0">Password</label>
      <a href="<?=site_url('forgot-password')?>" class="text-sm">Forgot password?</a>
    </div>
    <div class="ws-password mt-1">
      <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
      <button type="button" class="ws-password-toggle" data-password-toggle="password" aria-pressed="false">Show</button>
    </div>
  </div>

  <label class="checkbox">
    <input type="checkbox" name="remember" value="1" <?=set_checkbox('remember', '1')?>>
    Remember me on this device
  </label>

  <button type="submit" class="btn btn-primary btn-block" data-loading-text="Signing in…">Log in</button>
<?=form_close()?>

<p class="ws-auth-aside">
  Don't have an account?
  <a href="<?=site_url(!empty($referral) ? 'register?ref='.urlencode($referral) : 'register')?>">Create one</a>
</p>
