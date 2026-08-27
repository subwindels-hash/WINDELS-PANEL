<?php defined('BASEPATH') OR exit('No direct script access allowed');?>
<!-- SMM-style sidebar with neon accents -->
<div class="ws-sidebar-brand" style="background: linear-gradient(135deg, #0f0f1a 0%, #1a1a24 100%); padding: 1.5rem 1rem 1rem;">
  <a href="<?=site_url()?>" class="ws-brand" style="display: flex; align-items: center; gap: 0.75rem;">
    <img src="<?=base_url('assets/images/logo-white.png')?>" alt="WINDELS" class="w-6 h-6">
    <span class="font-bold tracking-tight text-purple-400">WINDELSOCIALS</span>
  </a>
</div>

<nav class="ws-sidebar-nav" style="margin-top: 1.5rem;">
  <?php foreach ($nav_groups as $gi => $group): if (empty($group[1])) continue; ?>
    <?php
      $group_open = false;
      foreach ($group[1] as $item) {
        $href = $item[0];
        if ($active === $href || ($href !== 'admin' && $href !== 'dashboard' && strpos($active, $href.'/') === 0)) {
          $group_open = true;
          break;
        }
      }
    ?>
    <details class="ws-nav-group" style="margin-bottom: 0.75rem; border-radius: 8px; overflow: hidden;">
      <summary class="ws-nav-group-label" style="background: rgba(255,255,255,0.05); padding: 0.75rem 1rem; font-size: 0.875rem; font-weight: 500; color: #fff; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.3s ease;">
          <line x1="5" y1="12" x2="19" y2="12"></line>
          <polyline points="5 5 10 12 15 5"></polyline>
        </svg>
        <?=htmlspecialchars($group[0])?>
      </summary>
      <div class="ws-nav-group-items" style="padding: 0.5rem 1rem; background: rgba(255,255,255,0.03);">
        <?php foreach ($group[1] as $item): list($href,$label,$perm) = $item; ?>
          <?php if ($perm && !in_array('*', $permissions, true) && !in_array($perm, $permissions, true)) continue; ?>
          <?php $is_active = ($active === $href) || ($href !== 'admin' && $href !== 'dashboard' && strpos($active, $href.'/') === 0); ?>
          <a href="<?=site_url($href)?>"
             class="ws-nav-link<?=$is_active ? ' bg-[rgba(168,85,247,0.1)]' : ''?> block px-3 py-2 rounded hover:bg-purple-500/5 transition-colors text-slate-300 text-sm font-medium<?=$is_active ? ' font-semibold text-purple-400' : ''?>"
             style="color: #fff; display: block; width: 100%; text-align: left;">
            <span class="mr-2" style="width: 18px; height: 18px; flex-shrink: 0;">
              <?php $this->load->view('partials/icon', array('name'=>$item[3] ?? 'circle', 'class'=>'w-4 h-4')); ?>
            </span>
            <span><?=htmlspecialchars($label)?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
</div>

<div class="ws-sidebar-user" style="padding: 1rem; border-top: 1px solid rgba(255,255,255,0.1); margin-top: 2rem;">
  <div class="ws-avatar-initial" style="width: 40px; height: 40px; background: linear-gradient(135deg, #a855f7, #ec4899); border-radius: 50%; font-weight: 700; color: white; font-size: 1.2rem; margin-bottom: 0.5rem;">
    U<?=substr($current_user->username ?? 'U', 0, 1)?>
  </div>
  <div class="text-sm">
    <div class="font-medium text-slate-300 truncate"><?=htmlspecialchars($current_user->username ?? '')?></div>
    <div class="text-xs text-slate-500 truncate"><?=htmlspecialchars($current_user->email ?? '')?></div>
  </div>
  <form method="post" action="<?=site_url('logout')?>" class="mt-2">
    <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" value="<?= $this->security->get_csrf_hash() ?>">
    <button type="submit" title="Log out" class="w-6 h-6 opacity-50 hover:opacity-100 transition inline-flex items-center justify-center">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="18" y1="6" x2="18" y2="18"></line>
        <line x1="18" y1="6" x2="6" y2="18"></line>
      </svg>
    </button>
  </form>
</div>