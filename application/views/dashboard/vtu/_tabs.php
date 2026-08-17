<?php defined('BASEPATH') OR exit('No direct script access allowed');
/* Shared sub-navigation for the five VTU services. */ ?>
<div class="row mb-4" style="gap:.5rem;flex-wrap:wrap">
  <?php foreach ($tabs as $key => $meta): ?>
    <a class="btn btn-sm <?=($tab === $key) ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('dashboard/vtu/'.$key)?>"><?=htmlspecialchars($meta[2])?></a>
  <?php endforeach; ?>
  <a class="btn btn-sm btn-ghost" href="<?=site_url('dashboard/vtu/history')?>">History</a>
</div>
