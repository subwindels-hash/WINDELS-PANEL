<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
  <div class="rounded-2xl border border-slate-200 bg-white p-6">
    <div class="text-sm text-slate-500">Wallet balance</div>
    <div class="mt-2 text-3xl font-bold tracking-tight">
      <?=windels_money($wallet->balance ?? '0', $wallet->currency ?? 'USD')?>
    </div>
    <a href="<?=site_url('dashboard/add-funds')?>" class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700">Add funds →</a>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-6">
    <div class="text-sm text-slate-500">Quick start</div>
    <p class="mt-2 text-sm text-slate-600">Place your first SMM order or browse the service catalog.</p>
    <a href="<?=site_url('dashboard/new-order')?>" class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700">New order →</a>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-6">
    <div class="text-sm text-slate-500">Reseller API</div>
    <p class="mt-2 text-sm text-slate-600">Automate orders with the /api/v1 endpoint.</p>
    <a href="<?=site_url('dashboard/api')?>" class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700">Manage keys →</a>
  </div>
</div>

<?php if (empty($current_user->email_verified_at)): ?>
  <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
    <div class="font-medium text-amber-900">Please verify your email</div>
    <p class="mt-1 text-sm text-amber-800">
      Some features are restricted until you confirm your email address.
    </p>
    <form method="post" action="<?=site_url('verify-email/resend')?>" class="mt-3">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
      <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Resend verification email</button>
    </form>
  </div>
<?php endif; ?>
