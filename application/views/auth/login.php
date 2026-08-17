<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Log in to your account</h1>
<p class="mt-1 text-sm text-slate-500">Welcome back. Enter your details to continue.</p>

<?=form_open('login', array('class' => 'mt-6 space-y-4', 'autocomplete' => 'on'))?>
  <div>
    <label for="identifier" class="block text-sm font-medium text-slate-700">Email or username</label>
    <input id="identifier" name="identifier" type="text" autocomplete="username" required autofocus
           value="<?=htmlspecialchars(set_value('identifier'))?>"
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
    <?=form_error('identifier', '<p class="mt-1 text-sm text-rose-600">', '</p>')?>
  </div>

  <div>
    <div class="flex items-center justify-between">
      <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
      <a href="<?=site_url('forgot-password')?>" class="text-sm text-indigo-600 hover:text-indigo-700">Forgot password?</a>
    </div>
    <input id="password" name="password" type="password" autocomplete="current-password" required
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
  </div>

  <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
    Log in
  </button>
<?=form_close()?>

<p class="mt-6 text-center text-sm text-slate-500">
  Don't have an account?
  <a href="<?=site_url(!empty($referral) ? 'register?ref='.urlencode($referral) : 'register')?>" class="font-medium text-indigo-600 hover:text-indigo-700">Create one</a>
</p>
