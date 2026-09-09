<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Shared designed empty state — one visual pattern for every empty module.
 *
 * Explains (1) what the section is, (2) why it is empty, (3) what to do next.
 * Callers pass:
 *   icon         partials/icon name (default 'package')
 *   title        heading
 *   body         short explanation
 *   action_href  optional CTA link
 *   action_label CTA text
 */
$icon         = $icon ?? 'package';
$title        = $title ?? 'Nothing here yet';
$body         = $body ?? '';
$action_href  = $action_href ?? '';
$action_label = $action_label ?? 'Get started';
?>
<div class="empty-state card">
  <span class="ws-empty-icon"><?php $this->load->view('partials/icon', array('name' => $icon, 'class' => 'w-6 h-6')); ?></span>
  <h3 style="margin-top:1rem;margin-bottom:.25rem"><?=htmlspecialchars($title)?></h3>
  <?php if ($body !== ''): ?>
    <p style="max-width:28rem;margin:0 auto 1rem"><?=htmlspecialchars($body)?></p>
  <?php endif; ?>
  <?php if ($action_href !== ''): ?>
    <a class="btn btn-primary btn-sm" href="<?=htmlspecialchars($action_href)?>"><?=htmlspecialchars($action_label)?></a>
  <?php endif; ?>
</div>
