<?php defined('BASEPATH') OR exit('No direct script access allowed');
$this->load->view('admin/system/_tabs');
?>
<div class="card">
  <?php if (empty($files)): ?>
    <p class="muted mb-0">No log files under storage/logs.</p>
  <?php else: ?>
    <form method="get" class="table-toolbar">
      <select class="select" name="file" data-autosubmit >
        <?php foreach ($files as $f): ?>
          <option value="<?=htmlspecialchars($f)?>" <?=$file===$f?'selected':''?>><?=htmlspecialchars($f)?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <pre class="ws-landing-code" style="max-height:32rem"><?=htmlspecialchars($tail)?></pre>
  <?php endif; ?>
</div>
