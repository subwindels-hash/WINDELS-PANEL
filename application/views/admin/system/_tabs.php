<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <?php foreach ($tabs as $key => $tab): list($label, $url, $allowed) = $tab; ?>
    <?php if (!$allowed) continue; ?>
    <a class="btn btn-sm <?=$area === $key ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url($url)?>"><?=htmlspecialchars($label)?></a>
  <?php endforeach; ?>
</div>
