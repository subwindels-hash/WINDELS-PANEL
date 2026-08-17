<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Set a new password</h1>
<p class="mt-1 text-sm text-slate-500">Choose a new password for your account.</p>

<?=form_open('reset-password/'.($token ?? ''), array('class' => 'mt-6 space-y-4'))?>
  <div>
    <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" autofocus
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
    <?=form_error('password', '<p class="mt-1 text-sm text-rose-600">', '</p>')?>
  </div>
  <div>
    <label for="password_confirm" class="block text-sm font-medium text-slate-700">Confirm new password</label>
    <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required minlength="8"
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
  </div>
  <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
    Reset password
  </button>
<?=form_close()?>
