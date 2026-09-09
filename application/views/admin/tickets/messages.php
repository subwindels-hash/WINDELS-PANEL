<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
$csrf = $this->security->get_csrf_token_name();
$hash = $this->security->get_csrf_hash();
$can_reply = in_array('*', $permissions ?? array(), true)
    || in_array('tickets.reply', $permissions ?? array(), true);
$new_count = 0;
foreach ($rows as $r) { if ($r->status !== 'REPLIED') $new_count++; }
?>
<div class="card mb-4">
  <div class="row justify-between" style="align-items:baseline">
    <div>
      <h2 class="card-title mb-0">Customer messages</h2>
      <p class="muted text-sm mb-0 mt-1">
        Messages from the public contact form. Signed-in customers open
        <a href="<?=site_url('admin/tickets')?>">support tickets</a> instead.
        <?=$new_count > 0 ? '<strong>'.number_format($new_count).'</strong> still need'.($new_count === 1 ? 's' : '').' a reply.' : 'Everything is answered.'?>
      </p>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?=site_url('admin/email-templates')?>">Edit reply templates →</a>
  </div>
</div>

<?php if (empty($rows)): ?>
<div class="card"><p class="muted mb-0">No visitor messages yet.</p></div>
<?php else: ?>
<?php foreach ($rows as $r): ?>
<div class="card mb-3"<?=$r->status !== 'REPLIED' ? ' style="border-color:var(--color-warning,#f59e0b)"' : ''?>>
  <details<?=$r->status !== 'REPLIED' ? ' open' : ''?>>
    <summary style="cursor:pointer;list-style:none">
      <div class="row justify-between" style="align-items:center;gap:1rem;flex-wrap:wrap">
        <div style="min-width:0">
          <div class="text-sm font-semibold" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?=htmlspecialchars($r->subject)?>
          </div>
          <div class="muted text-xs mt-1">
            <?=htmlspecialchars($r->name)?> &lt;<?=htmlspecialchars($r->email)?>&gt; · <?=htmlspecialchars($r->created_at)?> UTC
          </div>
        </div>
        <span class="badge <?=$r->status === 'REPLIED' ? 'badge-success' : 'badge-warning'?>"
              style="flex-shrink:0"><?=htmlspecialchars($r->status === 'REPLIED' ? 'Replied' : 'New')?></span>
      </div>
    </summary>

    <div class="mt-3" style="border-top:1px solid var(--border,#e2e8f0);padding-top:1rem">
      <div class="text-sm"><?=nl2br(htmlspecialchars($r->message))?></div>

      <?php if (!empty($r->replied_at)): ?>
      <div class="mt-4" style="border-left:3px solid var(--color-success,#16a34a);padding-left:1rem">
        <div class="muted text-xs mb-1">
          Our reply · <?=htmlspecialchars($r->replied_at)?> UTC
          <?=!empty($r->reply_subject) ? ' · '.htmlspecialchars($r->reply_subject) : ''?>
        </div>
        <div class="text-sm"><?=nl2br(htmlspecialchars((string)$r->reply_body))?></div>
      </div>
      <?php endif; ?>

      <?php if ($can_reply): ?>
      <form method="post" action="<?=site_url('admin/messages/reply/'.(int)$r->id)?>" class="mt-4">
        <input type="hidden" name="<?=htmlspecialchars($csrf)?>" value="<?=htmlspecialchars($hash)?>" readonly>
        <?php if (!empty($templates)): ?>
        <label class="label">Start from a template</label>
        <select class="select" data-ws-template-for="ws-reply-<?=$r->id?>">
          <option value="">— write your own —</option>
          <?php foreach ($templates as $t): ?>
          <option value="<?=htmlspecialchars($t->template_key)?>"><?=htmlspecialchars($t->template_key)?> · <?=htmlspecialchars($t->subject)?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="label mt-3">Reply subject <span class="muted">(defaults to “Re: …”)</span></label>
        <input class="input" name="subject" maxlength="255" value="<?=htmlspecialchars('Re: '.$r->subject)?>">
        <label class="label mt-3">Reply</label>
        <textarea class="textarea" id="ws-reply-<?=$r->id?>" name="message" rows="5" required
                  placeholder="Type the reply this visitor will receive by email…"></textarea>
        <p class="muted text-xs mt-1">
          Placeholders like <code>{{name}}</code>, <code>{{subject}}</code> and <code>{{site_name}}</code> are filled in when the email is sent.
        </p>
        <div class="form-actions">
          <button class="btn btn-primary btn-sm" type="submit"
                  data-confirm="Send this reply to <?=htmlspecialchars($r->email)?>?">Send reply</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </details>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($templates)): ?>
<script <?=csp_nonce_attr()?>>
/** Fill the reply box from the picked template. Enhancement only: without
 *  JavaScript the select does nothing and the reply is typed by hand. */
(function () {
  'use strict';
  var templates = <?=json_encode(array_reduce($templates, function ($out, $t) {
      $out[$t->template_key] = array('subject' => $t->subject, 'body' => (string)$t->body_text);
      return $out;
  }, array()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;

  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || !sel.dataset || !sel.dataset.wsTemplateFor) return;
    var tpl = templates[sel.value];
    if (!tpl) return;
    var box = document.getElementById(sel.dataset.wsTemplateFor);
    var form = sel.closest('form');
    if (box) box.value = tpl.body;
    var subject = form ? form.querySelector('[name=subject]') : null;
    if (subject && tpl.subject) subject.value = tpl.subject;
  });
})();
</script>
<?php endif; ?>
