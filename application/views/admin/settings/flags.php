<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<form method="post" action="<?=site_url('admin/settings/flags')?>" class="card">
  <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
  <?php if (empty($flags)): ?>
    <p class="muted">No feature flags in the database. Import marvysocials.sql or seed Core.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Flag</th><th>Description</th><th>Enabled</th></tr></thead>
      <tbody>
      <?php foreach ($flags as $f): ?>
        <tr>
          <td class="mono"><?=htmlspecialchars($f->flag_key)?></td>
          <td class="muted text-sm"><?=htmlspecialchars((string)$f->description)?></td>
          <td>
            <label class="row" style="gap:.4rem">
              <input type="checkbox" name="flags[<?=htmlspecialchars($f->flag_key)?>]" value="1" <?=((int)$f->enabled===1)?'checked':''?>>
              On
            </label>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save flags</button></div>
  <?php endif; ?>
</form>
<p class="muted text-sm"><a href="<?=site_url('admin/settings')?>">← Settings</a></p>
