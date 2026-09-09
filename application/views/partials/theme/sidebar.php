<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Themed sidebar for the authenticated shell.
 *
 * Renders the same navigation tree as partials/navigation/sidebar (built by
 * layouts/_app_context.php) with the MarvySocials accent treatment. Every
 * variable is defaulted so a layout that forgets one renders a nav instead of
 * a 500.
 */
$active       = $active ?? '';
$permissions  = $permissions ?? array();
$nav_groups   = $nav_groups ?? array();
$brand        = $brand ?? array();
$current_user = $current_user ?? null;
?>
<div class="ws-sidebar-brand">
  <a href="<?=site_url()?>" class="ws-brand">
    <?php if (!empty($brand['brand_logo_url'])): ?>
      <img src="<?=htmlspecialchars($brand['brand_logo_url'])?>" alt="<?=htmlspecialchars(function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials')?>" class="ws-logo">
    <?php else: ?>
      <?php $this->load->view('partials/brand_logo', array('variant'=>'icon','height'=>32,'force_legacy'=>true)); ?>
      <span class="font-bold tracking-tight ws-brand-word"><?=htmlspecialchars(function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials')?></span>
    <?php endif; ?>
  </a>
</div>

<nav class="ws-sidebar-nav" aria-label="Primary">
  <?php foreach ($nav_groups as $group): if (empty($group[1])) continue; ?>
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
    <details class="ws-nav-group" open>
      <summary class="ws-nav-group-label"><?=htmlspecialchars($group[0])?></summary>
      <div class="ws-nav-group-items">
        <?php foreach ($group[1] as $item): ?>
          <?php
            $href  = $item[0];
            $label = $item[1];
            $perm  = $item[2] ?? null;
            if ($perm && !in_array('*', $permissions, true) && !in_array($perm, $permissions, true)) continue;
            $is_active = ($active === $href) || ($href !== 'admin' && $href !== 'dashboard' && strpos($active, $href.'/') === 0);
          ?>
          <a href="<?=site_url($href)?>" class="ws-nav-link<?=$is_active ? ' is-active' : ''?>">
            <?php $this->load->view('partials/icon', array('name'=>$item[3] ?? 'circle', 'class'=>'w-[18px] h-[18px]')); ?>
            <span><?=htmlspecialchars($label)?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
</nav>

<div class="ws-sidebar-user">
  <?php if (!empty($current_user->avatar_url)): ?>
    <img class="ws-avatar" src="<?=htmlspecialchars($current_user->avatar_url)?>" alt=""
         width="40" height="40" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex:none">
  <?php else: ?>
    <div class="ws-avatar-initial"><?=htmlspecialchars(strtoupper(substr($current_user->username ?? 'U', 0, 1)))?></div>
  <?php endif; ?>
  <div class="min-w-0 flex-1">
    <div class="font-medium truncate text-sm"><?=htmlspecialchars($current_user->username ?? '')?></div>
    <div class="text-xs text-slate-500 truncate"><?=htmlspecialchars($current_user->email ?? '')?></div>
  </div>
  <form method="post" action="<?=site_url('logout')?>" class="m-0">
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
           value="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
    <button type="submit" title="Log out" class="btn-icon" aria-label="Log out">
      <?php $this->load->view('partials/icon', array('name'=>'logout','class'=>'w-[18px] h-[18px]')); ?>
    </button>
  </form>
</div>
