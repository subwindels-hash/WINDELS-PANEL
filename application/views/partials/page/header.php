<?php defined('BASEPATH') OR exit('No direct script access allowed');
if (!empty($hide_page_header)) return;
$page_title = $page_title ?? ($title ?? '');
$page_description = $page_description ?? '';
$page_actions = $page_actions ?? array();
$breadcrumbs = $breadcrumbs ?? array();
if ($page_title === '' && $page_description === '' && empty($page_actions) && empty($breadcrumbs)) return;
?>
<header class="ws-page-head">
  <?php if (!empty($breadcrumbs)): ?>
    <nav class="ws-breadcrumb" aria-label="Breadcrumb">
      <?php foreach ($breadcrumbs as $i => $crumb): ?>
        <?php if ($i > 0): ?><span class="ws-breadcrumb-sep" aria-hidden="true">/</span><?php endif; ?>
        <?php if (!empty($crumb['href']) && $i < count($breadcrumbs) - 1): ?>
          <a href="<?=htmlspecialchars($crumb['href'])?>"><?=htmlspecialchars($crumb['label'])?></a>
        <?php else: ?>
          <span><?=htmlspecialchars($crumb['label'])?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <div class="ws-page-head-row">
    <div class="ws-page-head-copy">
      <?php if ($page_title !== ''): ?>
        <h1 class="ws-page-title"><?=htmlspecialchars($page_title)?></h1>
      <?php endif; ?>
      <?php if ($page_description !== ''): ?>
        <p class="ws-page-desc"><?=htmlspecialchars($page_description)?></p>
      <?php endif; ?>
    </div>
    <?php if (!empty($page_actions)): ?>
      <div class="ws-page-actions">
        <?php foreach ($page_actions as $action): ?>
          <a class="<?=htmlspecialchars($action['class'] ?? 'btn btn-primary')?>" href="<?=htmlspecialchars($action['href'])?>">
            <?=htmlspecialchars($action['label'])?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</header>
