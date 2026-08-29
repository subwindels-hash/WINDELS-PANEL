<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-4xl">
  <div class="row justify-between mb-2" style="gap:.75rem">
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/inbox')?>">← Inbox</a>
    <div class="row" style="gap:.5rem">
      <?php if (!empty($msg->from_email)): ?>
        <a class="btn btn-secondary btn-sm" href="<?=site_url('admin/inbox/'.$msg->public_id.'?reply=1')?>">
          <?php $this->load->view('partials/icon', array('name'=>'reply','class'=>'w-4 h-4')); ?> Reply
        </a>
      <?php endif; ?>
      <form method="post" action="<?=site_url('admin/inbox/'.$msg->public_id.'/delete')?>" style="margin:0"
            data-confirm="Delete this message from the admin inbox?">
        <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
               value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--slate-500)">
          <?php $this->load->view('partials/icon', array('name'=>'trash','class'=>'w-4 h-4')); ?> Delete
        </button>
      </form>
    </div>
  </div>

  <h2 class="card-title mb-1"><?=htmlspecialchars((string)$msg->subject)?></h2>
  <div class="muted text-sm">
    From
    <strong><?=htmlspecialchars((string)($msg->from_name ?: ''))?>
      <span class="mono"><?=htmlspecialchars((string)$msg->from_email)?></span></strong>
    · to
    <span class="mono"><?=htmlspecialchars((string)$msg->to_email)?></span>
  </div>
  <div class="muted text-xs">
    Received <?=$msg->received_at ? date('M j, Y H:i', strtotime($msg->received_at)) : '—'?>
    <?php if (!empty($msg->message_id)): ?> · <span class="mono">#<?=htmlspecialchars((string)$msg->message_id)?></span><?php endif; ?>
  </div>

  <div class="mt-4" style="white-space:pre-wrap;word-break:break-word;line-height:1.55">
    <?=htmlspecialchars((string)$msg->body_text)?>
  </div>

  <?php if (!empty($msg->body_html)): ?>
  <details class="mt-4">
    <summary class="cursor-pointer muted text-sm">Original HTML (reference only — not rendered)</summary>
    <pre class="mt-2 text-xs muted" style="white-space:pre-wrap;word-break:break-all;max-height:420px;overflow:auto"><?=htmlspecialchars((string)$msg->body_html)?></pre>
  </details>
  <?php endif; ?>

  <?php if (!empty($msg->from_email) && $reply): ?>
  <form method="post" action="<?=site_url('admin/inbox/'.$msg->public_id.'/reply')?>" class="mt-4"
        data-confirm="Send the reply to <?=htmlspecialchars((string)$msg->from_email)?>?">
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
           value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
    <h3 class="text-sm font-semibold mb-2">Reply to
      <?=htmlspecialchars((string)($msg->from_name ?: $msg->from_email))?></h3>
    <textarea class="input" name="body" rows="6" required
              placeholder="Write the reply — it goes out from the panel's From address…"><?=htmlspecialchars($this->input->post('body', true))?></textarea>
    <div class="row" style="gap:.5rem;margin-top:.6rem">
      <button class="btn btn-primary btn-sm" type="submit">Queue reply</button>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/inbox/'.$msg->public_id)?>">Cancel</a>
    </div>
    <p class="hint mt-2 mb-0">The reply is queued like every other panel mail and sends on the next
      mail-queue run (every five minutes).</p>
  </form>
  <?php endif; ?>
</div>
