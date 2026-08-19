<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Two-factor authentication</h1>
<p class="mt-1 text-sm text-slate-500">
  Enter the 6-digit code from your authenticator app<?=!empty($email) ? ' for '.htmlspecialchars($email) : ''?>.
</p>

<?=form_open('auth/mfa/verify', array('class' => 'mt-6 space-y-4'))?>
  <div>
    <label for="code" class="block text-sm font-medium text-slate-700">Verification code</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
           pattern="[0-9]{6}" maxlength="6" required autofocus
           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 tracking-[0.5em] text-center text-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
  </div>
  <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
    Verify
  </button>
<?=form_close()?>

<p class="mt-6 text-center text-sm text-slate-500">
  <form method="post" action="<?=site_url('logout')?>" class="inline-block m-0">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
    <button type="submit" class="text-indigo-600 hover:text-indigo-700 bg-transparent border-0 p-0 cursor-pointer">Cancel and log out</button>
  </form>
</p>
