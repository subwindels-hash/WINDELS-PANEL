<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Create your account</h1>
<p class="mt-1 text-sm text-slate-500">Start ordering in minutes. No credit card required to sign up.</p>

<?=form_open('register', array('class' => 'mt-6 space-y-4', 'autocomplete' => 'on'))?>
  <?php if (!empty($referral)): ?>
    <input type="hidden" name="ref" value="<?=htmlspecialchars($referral)?>">
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-3">
    <div>
      <label for="first_name" class="block text-sm font-medium text-slate-700">First name</label>
      <input id="first_name" name="first_name" type="text" autocomplete="given-name"
             value="<?=htmlspecialchars(set_value('first_name'))?>"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
    </div>
    <div>
      <label for="last_name" class="block text-sm font-medium text-slate-700">Last name</label>
      <input id="last_name" name="last_name" type="text" autocomplete="family-name"
             value="<?=htmlspecialchars(set_value('last_name'))?>"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
    </div>
  </div>

  <div>
    <label for="username" class="block text-sm font-medium text-slate-700">Username</label>
    <input id="username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="64"
           value="<?=htmlspecialchars(set_value('username'))?>"
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
    <?=form_error('username', '<p class="mt-1 text-sm text-rose-600">', '</p>')?>
  </div>

  <div>
    <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
    <input id="email" name="email" type="email" autocomplete="email" required maxlength="255"
           value="<?=htmlspecialchars(set_value('email'))?>"
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
    <?=form_error('email', '<p class="mt-1 text-sm text-rose-600">', '</p>')?>
  </div>

  <div>
    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8"
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
    <?=form_error('password', '<p class="mt-1 text-sm text-rose-600">', '</p>')?>
  </div>

  <div>
    <label for="password_confirm" class="block text-sm font-medium text-slate-700">Confirm password</label>
    <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required minlength="8"
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
  </div>

  <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
    Create account
  </button>
<?=form_close()?>

<p class="mt-6 text-center text-sm text-slate-500">
  Already have an account?
  <a href="<?=site_url('login')?>" class="font-medium text-indigo-600 hover:text-indigo-700">Log in</a>
</p>
