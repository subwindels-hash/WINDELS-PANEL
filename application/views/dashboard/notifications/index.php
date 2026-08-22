<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-3xl">
  <div class="row justify-between">
    <h2 class="card-title mb-0">Notifications</h2>
    <form method="post" action="<?=site_url('dashboard/notifications/read')?>">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
      <button class="btn btn-ghost btn-sm" type="submit" <?=empty($notifications)?'disabled':''?>>Mark all as read</button>
    </form>
  </div>

  <?php if (empty($notifications)): ?>
    <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'bell',
        'title' => 'No notifications yet',
        'body'  => 'Order updates, payment confirmations and account alerts will appear here.',
    )); ?>
  <?php else: ?>
  <ul class="mt-4 stack" style="gap:.25rem">
    <?php foreach ($notifications as $n): ?>
    <li class="ws-notif row <?=$n->is_read?'is-read':''?>" style="gap:.75rem;padding:.85rem 1rem">
      <span class="dot" style="width:8px;height:8px;border-radius:50%;background:<?=$n->is_read?'var(--slate-300)':'var(--brand-500)'?>"></span>
      <div class="flex-1 min-w-0">
        <div class="font-medium text-sm"><?=htmlspecialchars($n->title)?></div>
        <?php if (!empty($n->body)): ?><div class="muted text-sm"><?=htmlspecialchars($n->body)?></div><?php endif; ?>
        <div class="muted text-xs mt-1"><?=date('M j, Y H:i', strtotime($n->created_at))?> UTC · <?=htmlspecialchars($n->channel)?></div>
      </div>
      <?php if (!$n->is_read): ?>
      <form method="post" action="<?=site_url('dashboard/notifications/read')?>">
        <input type="hidden" name="public_id" value="<?=htmlspecialchars($n->public_id)?>">
        <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
        <button class="btn btn-ghost btn-sm" type="submit">Mark read</button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4">
    <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="<?=site_url('dashboard/notifications?page='.max(1,$page-1))?>">← Newer</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>" href="<?=site_url('dashboard/notifications?page='.min($total_pages,$page+1))?>">Older →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<style>
.ws-notif{border-bottom:1px solid var(--slate-100)}
.ws-notif:last-child{border-bottom:0}
.ws-notif.is-read{opacity:.65}
</style>
