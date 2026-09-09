<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <?php foreach ($tabs as $key => $tab): ?>
    <?php if (!$tab['allowed']) continue; ?>
    <a class="btn btn-sm <?=$queue === $key ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url($tab['url'])?>"><?=htmlspecialchars($tab['label'])?></a>
  <?php endforeach; ?>
</div>
