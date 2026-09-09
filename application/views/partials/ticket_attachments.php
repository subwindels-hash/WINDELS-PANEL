<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Attachments hanging off one ticket message.
 *
 * Rendered for both the customer thread and the staff thread from one file,
 * so a screenshot looks and behaves the same on both sides. Names are escaped
 * and the link is `rel="noopener"` — the file name is customer-supplied text.
 */
$items = isset($attachments) && is_array($attachments) ? $attachments : array();
if (!$items) return;
$kb = function ($bytes) {
    $bytes = (int)$bytes;
    return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int)round($bytes / 1024)).' KB';
};
?>
<ul class="row mt-2" style="gap:.4rem;flex-wrap:wrap;list-style:none;padding:0">
  <?php foreach ($items as $file): ?>
    <li>
      <a class="btn btn-ghost btn-sm" style="gap:.35rem"
         href="<?=htmlspecialchars((string)$file->file_url)?>" target="_blank" rel="noopener noreferrer"
         download="<?=htmlspecialchars((string)$file->file_name)?>">
        📎 <?=htmlspecialchars((string)$file->file_name)?>
        <span class="muted text-xs"><?=htmlspecialchars($kb($file->size))?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
