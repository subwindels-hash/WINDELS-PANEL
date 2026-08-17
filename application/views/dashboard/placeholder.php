<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-2xl text-center" style="margin:2rem auto;padding:3rem">
  <div class="text-4xl">🚧</div>
  <h2 class="card-title mt-3"><?=htmlspecialchars($feature ?? 'This feature')?></h2>
  <p class="muted">This screen is scaffolded and ships fully in <strong>Session <?=htmlspecialchars($session ?? '—')?></strong>.</p>
  <a class="btn btn-primary mt-4" href="<?=site_url('dashboard')?>">← Back to dashboard</a>
</div>
