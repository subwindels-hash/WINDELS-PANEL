<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Admin → Website content → Pages.
 *
 * Lists the public pages whose text an administrator can change without a
 * developer. A page with no override still serves the copy bundled with the
 * panel, which is why the status column distinguishes "Default" from "Custom".
 */
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Website pages</h2>
    <p class="muted text-sm">
      Edit the public policy and marketing pages. Changes go live immediately — no deployment needed.
    </p>
  </div>
</div>

<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>Page</th>
        <th>URL</th>
        <th>Status</th>
        <th>Last updated</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($catalogue as $key => $meta): ?>
      <?php $override = isset($overrides[$key]) ? $overrides[$key] : null; ?>
      <tr>
        <td><strong><?=htmlspecialchars($meta[0])?></strong></td>
        <td>
          <a class="mono text-xs" href="<?=site_url($meta[1])?>" target="_blank" rel="noopener">
            /<?=htmlspecialchars($meta[1])?>
          </a>
        </td>
        <td>
          <?php if (!$override): ?>
            <span class="badge badge-default" title="Serving the text bundled with the panel">Default</span>
          <?php elseif ((int)$override->is_published === 1): ?>
            <span class="badge badge-success badge-dot">Custom</span>
          <?php else: ?>
            <span class="badge badge-warning" title="Saved but not published — visitors see the default text">Draft</span>
          <?php endif; ?>
        </td>
        <td class="text-xs muted">
          <?=$override && $override->updated_at
              ? htmlspecialchars(date('j M Y, H:i', strtotime($override->updated_at.' UTC'))).' UTC'
              : '—'?>
        </td>
        <td class="text-right">
          <a class="btn btn-secondary btn-sm" href="<?=site_url('admin/pages/'.$key)?>">Edit</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p class="muted text-xs mt-3">
  Pages you have never edited show “Default” and render the text that ships with the panel, so a fresh
  install is never missing a policy. Resetting a page removes your override and brings that text back.
</p>
