<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = $this->security->get_csrf_token_name();
$hash = $this->security->get_csrf_hash();
?>
<?php if (empty($rows)): ?>
  <div class="card"><p class="muted mb-0">No email templates. Import the production SQL seed.</p></div>
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
