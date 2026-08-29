<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-4xl">
  <div class="row justify-between">
    <h2 class="card-title mb-0">Inbox
      <?php if ($unread > 0): ?><span class="badge badge-info badge-dot"><?=$unread?> new</span><?php endif; ?>
    </h2>
    <div class="row" style="gap:.5rem">
      <a class="btn btn-ghost btn-sm <?=$status==='UNREAD'?'is-disabled':''?>"
         href="<?=site_url('admin/inbox')?>">All</a>
      <a class="btn btn-ghost btn-sm <?=$status!=='UNREAD'?'is-disabled':''?>"
         href="<?=site_url('admin/inbox?status=UNREAD')?>">Unread</a>
      <form method="post" action="<?=site_url('admin/inbox/read')?>" style="margin:0">
        <input type="hidden" name="back" value="admin/inbox">
        <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
               value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
        <button class="btn btn-ghost btn-sm" type="submit" <?=empty($rows)?'disabled':''?>>Mark all as read</button>
      </form>
    </div>
  </div>
  <p class="hint mt-2 mb-0">
    Mail addressed to
    <span class="mono"><?=htmlspecialchars($admin_email !== '' ? (string)$admin_email : 'the configured account')?></span>
    — plus anything the system cannot attribute to a customer — lands here. Customer-addressed
    mail goes to the customer's own dashboard inbox instead. Replies queue through the normal
    mail pipeline.</p>

  <?php if (empty($rows)): ?>
    <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'inbox',
        'title' => 'No mail yet',
        'body'  => 'Messages pulled from the configured mailbox appear here as they arrive (every two minutes).',
    )); ?>
  <?php else: ?>
  <ul class="mt-3 stack" style="gap:.25rem">
    <?php foreach ($rows as $m): ?>
    <li class="ws-inbox-item row <?=$m->is_read?'is-read':''?>" style="gap:.75rem;padding:.85rem 1rem">
      <span style="width:8px;height:8px;border-radius:50%;background:<?=$m->is_read?'var(--slate-300)':'var(--brand-500)'?>"></span>
      <a class="flex-1 min-w-0" href="<?=site_url('admin/inbox/'.$m->public_id)?>" style="text-decoration:none">
        <div class="row justify-between" style="gap:.75rem">
          <span class="font-medium text-sm truncate" style="color:inherit">
            <?=htmlspecialchars((string)($m->from_name ?: ($m->from_email ?: 'Unknown sender')))?>
            <span class="muted text-xs">→ <?=htmlspecialchars((string)$m->to_email)?></span>
          </span>
          <span class="muted text-xs whitespace-nowrap">
            <?=$m->received_at ? date('M j, Y H:i', strtotime($m->received_at)) : '—'?>
          </span>
        </div>
        <div class="text-sm" style="color:inherit"><?=htmlspecialchars((string)$m->subject)?></div>
        <?php if (!empty($m->body_text)): ?>
          <div class="muted text-xs truncate"><?=htmlspecialchars(substr(strip_tags((string)$m->body_text), 0, 160))?></div>
        <?php endif; ?>
      </a>
      <?php if (!$m->is_read): ?>
      <form method="post" action="<?=site_url('admin/inbox/read')?>" style="margin:0">
        <input type="hidden" name="public_id" value="<?=htmlspecialchars($m->public_id)?>">
        <input type="hidden" name="back" value="admin/inbox">
        <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
               value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
        <button class="btn btn-ghost btn-sm" type="submit">Mark read</button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4">
    <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>"
       href="<?=site_url('admin/inbox?page='.max(1,$page-1).($status?'&status=UNREAD':''))?>">← Newer</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>"
       href="<?=site_url('admin/inbox?page='.min($total_pages,$page+1).($status?'&status=UNREAD':''))?>">Older →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<style>
.ws-inbox-item{border-bottom:1px solid var(--slate-100)}
.ws-inbox-item:last-child{border-bottom:0}
.ws-inbox-item.is-read{opacity:.65}
</style>
