<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-3xl">
  <div class="row justify-between mb-2" style="gap:.75rem">
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/inbox')?>">← Inbox</a>
    <form method="post" action="<?=site_url('dashboard/inbox/'.$msg->public_id.'/delete')?>" style="margin:0"
          data-confirm="Delete this message from your inbox?">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
             value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
      <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--slate-500)">Delete</button>
    </form>
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

  <div class="alert alert-info mt-4 mb-0">
    To answer, open this mail in your own mail app and press <strong>Reply</strong> — the
    conversation keeps going with your regular mail.
  </div>
</div>
