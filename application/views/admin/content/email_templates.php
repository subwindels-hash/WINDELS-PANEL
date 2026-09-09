<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = $this->security->get_csrf_token_name();
$hash = $this->security->get_csrf_hash();
?>
<form method="post" action="<?=site_url('admin/email-templates/create')?>" class="card mb-4">
  <input type="hidden" name="<?=htmlspecialchars($csrf)?>" value="<?=htmlspecialchars($hash)?>" readonly>
  <h3 class="card-title">New template</h3>
  <p class="muted text-sm mb-3">
    Add your own reply starters — a key beginning <code>contact.reply</code> also appears in the
    reply picker under Admin → Messages. Variables like <code>{{name}}</code> stay in the text and
    are filled in when the email is sent.
  </p>
  <div class="row" style="gap:1rem;flex-wrap:wrap">
    <label class="field mb-0" style="flex:1;min-width:14rem">
      <span class="label">Key</span>
      <input class="input mono" name="template_key" maxlength="127" required
             pattern="[a-z0-9][a-z0-9_.]{2,126}" placeholder="contact.reply_promo">
    </label>
    <label class="field mb-0" style="flex:2;min-width:16rem">
      <span class="label">Subject</span>
      <input class="input" name="subject" maxlength="255" required placeholder="Re: {{subject}}">
    </label>
  </div>
  <label class="label mt-4">HTML body</label>
  <textarea class="textarea" name="body_html" rows="4"
            placeholder="<p>Hi {{name}},</p>…"></textarea>
  <label class="label mt-4">Plain text</label>
  <textarea class="textarea" name="body_text" rows="3" placeholder="Hi {{name}}, …"></textarea>
  <label class="row mt-3" style="gap:.4rem"><input type="checkbox" name="is_active" value="1" checked>
    <span class="text-sm">Active</span></label>
  <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Add template</button></div>
</form>

<?php if (empty($rows)): ?>
  <div class="card"><p class="muted mb-0">No email templates yet — add one above or import the production SQL seed.</p></div>
<?php else: ?>
  <?php foreach ($rows as $t): ?>
  <form method="post" action="<?=site_url('admin/email-templates/'.$t->id)?>" class="card">
    <input type="hidden" name="<?=htmlspecialchars($csrf)?>" value="<?=htmlspecialchars($hash)?>">
    <div class="row justify-between">
      <h3 class="card-title mb-0"><code><?=htmlspecialchars($t->template_key)?></code></h3>
      <label class="row" style="gap:.4rem"><input type="checkbox" name="is_active" value="1" <?=((int)$t->is_active===1)?'checked':''?>> Active</label>
    </div>
    <label class="label">Subject</label>
    <input class="input" name="subject" value="<?=htmlspecialchars($t->subject)?>" required>
    <label class="label mt-4">HTML body</label>
    <textarea class="textarea" name="body_html" rows="8"><?=htmlspecialchars($t->body_html)?></textarea>
    <label class="label mt-4">Plain text</label>
    <textarea class="textarea" name="body_text" rows="4"><?=htmlspecialchars((string)$t->body_text)?></textarea>
    <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Save</button></div>
  </form>
  <?php endforeach; ?>
<?php endif; ?>
