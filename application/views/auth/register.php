<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Create your account</h1>
<p class="mt-1 text-sm text-slate-500">A wallet is created with the account. No payment is required to register.</p>

<?=form_open('register', array('class' => 'mt-6 space-y-4', 'autocomplete' => 'on'))?>
  <?php if (!empty($referral)): ?>
    <input type="hidden" name="ref" value="<?=htmlspecialchars($referral)?>">
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-3">
    <div>
      <label for="first_name" class="label">First name</label>
      <input id="first_name" name="first_name" type="text" class="input" autocomplete="given-name"
             value="<?=htmlspecialchars(set_value('first_name'))?>">
    </div>
    <div>
      <label for="last_name" class="label">Last name</label>
      <input id="last_name" name="last_name" type="text" class="input" autocomplete="family-name"
             value="<?=htmlspecialchars(set_value('last_name'))?>">
    </div>
  </div>

  <div>
    <label for="username" class="label">Username</label>
    <input id="username" name="username" type="text" class="input" autocomplete="username" required minlength="3" maxlength="64"
           value="<?=htmlspecialchars(set_value('username'))?>">
    <?=form_error('username', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <label for="email" class="label">Email</label>
    <input id="email" name="email" type="email" class="input" autocomplete="email" required maxlength="255"
           value="<?=htmlspecialchars(set_value('email'))?>">
    <?=form_error('email', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <label for="password" class="label">Password</label>
    <div class="ws-password">
      <input id="password" name="password" type="password" class="input" autocomplete="new-password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password" aria-pressed="false">Show</button>
    </div>
    <p class="hint">At least 8 characters.</p>
    <?=form_error('password', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <label for="password_confirm" class="label">Confirm password</label>
    <div class="ws-password">
      <input id="password_confirm" name="password_confirm" type="password" class="input" autocomplete="new-password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password_confirm" aria-pressed="false">Show</button>
    </div>
  </div>

  <label class="checkbox">
    <input type="checkbox" name="terms" value="1" required <?=set_checkbox('terms', '1')?>>
    <span>I agree to the <a href="<?=site_url('terms')?>" target="_blank" rel="noopener">Terms of Service</a> and <a href="<?=site_url('privacy')?>" target="_blank" rel="noopener">Privacy Policy</a>.</span>
  </label>
  <?=form_error('terms', '<p class="form-error">', '</p>')?>

  <button type="submit" class="btn btn-primary btn-block">Create account</button>
<?=form_close()?>

<p class="ws-auth-aside">
  Already have an account? <a href="<?=site_url('login')?>">Log in</a>
</p>
