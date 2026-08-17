<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Reset your password</h1>
<p class="mt-1 text-sm text-slate-500">Enter your email or username and we'll send you a reset link.</p>

<?=form_open('forgot-password', array('class' => 'mt-6 space-y-4'))?>
  <div>
    <label for="identifier" class="block text-sm font-medium text-slate-700">Email or username</label>
    <input id="identifier" name="identifier" type="text" autocomplete="username" required autofocus
           value="<?=htmlspecialchars(set_value('identifier'))?>"
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
  </div>
  <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
    Send reset link
  </button>
<?=form_close()?>

<p class="mt-6 text-center text-sm text-slate-500">
  Remembered it? <a href="<?=site_url('login')?>" class="font-medium text-indigo-600 hover:text-indigo-700">Back to login</a>
</p>
