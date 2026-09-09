<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Two-factor authentication</h1>
<p class="mt-1 text-sm text-slate-500">
  Enter the 6-digit code from your authenticator app<?=!empty($email) ? ' for '.htmlspecialchars($email) : ''?>. A recovery code also works.
</p>

<?=form_open('auth/mfa/verify', array('class' => 'mt-6 space-y-4'))?>
  <div>
    <label for="code" class="label">Verification code</label>
    <input id="code" name="code" type="text" class="input" inputmode="numeric" autocomplete="one-time-code"
           pattern="[0-9A-Za-z\-]+" maxlength="16" required autofocus
           style="letter-spacing:.35em;text-align:center;font-size:1.1rem">
  </div>
  <button type="submit" class="btn btn-primary btn-block">Verify</button>
<?=form_close()?>

<p class="ws-auth-aside">
  <form method="post" action="<?=site_url('logout')?>" class="inline-block m-0">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
    <button type="submit" class="btn btn-ghost btn-sm">Cancel and log out</button>
  </form>
</p>
