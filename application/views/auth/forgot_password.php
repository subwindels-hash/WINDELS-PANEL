<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Reset your password</h1>
<p class="mt-1 text-sm text-slate-500">Enter your email or username. If an account matches, we email a reset link. The confirmation you see is the same either way, so this form cannot be used to check whether an address is registered.</p>

<?=form_open('forgot-password', array('class' => 'mt-6 space-y-4'))?>
  <div>
    <label for="identifier" class="label">Email or username</label>
    <input id="identifier" name="identifier" type="text" class="input" autocomplete="username" required autofocus
           value="<?=htmlspecialchars(set_value('identifier'))?>">
  </div>
  <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
<?=form_close()?>

<p class="ws-auth-aside">
  Remembered it? <a href="<?=site_url('login')?>">Back to login</a>
</p>
