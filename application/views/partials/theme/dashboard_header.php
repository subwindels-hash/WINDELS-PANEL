<?php defined('BASEPATH') OR exit('No direct script access allowed');?>
<!-- Dashboard header theme with neon accents -->
<header class="border-b border-white/10 bg-[gradient_legacy] from-[#0a0a0f] to-[#12121a] shadow-lg">
  <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <a href="<?=site_url()?>" class="flex items-center gap-2">
        <img src="<?=base_url('assets/images/logo-white.png')?>" alt="WINDELS" class="w-6 h-6">
        <span class="text-xl font-bold tracking-widest text-purple-400">WINDELSOCIALS</span>
      </a>
    </div>
    <div class="hidden sm:flex items-center gap-6">
      <a href="<?=site_url('dashboard')?>" class="text-slate-400 hover:text-white transition-colors font-medium">Dashboard</a>
      <a href="<?=site_url('dashboard/orders')?>" class="text-slate-400 hover:text-white transition-colors font-medium">Orders</a>
      <a href="<?=site_url('dashboard/wallet')?>" class="text-slate-400 hover:text-white transition-colors font-medium">Wallet</a>
      <a href="<?=site_url('dashboard/earnings')?>" class="text-slate-400 hover:text-white transition-colors font-medium">Earnings</a>
    </div>
    <div class="flex items-center gap-3">
      <button class="btn btn-ghost btn-sm theme-toggle dark:hidden" aria-label="Toggle light mode">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="21.78" y2="21.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="21.78" y2="4.22"></line>
        </svg>
      </button>
      <button class="btn btn-ghost btn-sm hidden sm:block" aria-label="Open menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="3" y1="6" x2="21" y2="6"></line>
          <line x1="3" y1="12" x2="21" y2="12"></line>
          <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
      </button>
      <a href="<?=site_url('auth/logout')?>" class="btn btn-ghost text-sm px-4 py-2 rounded-lg hover:bg-white/10 transition-colors">
        Log out
      </a>
    </div>
  </div>
</header>