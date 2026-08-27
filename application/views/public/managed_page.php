<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Renders a page whose body an administrator edits from
 * Admin → Website content → Pages.
 *
 * `body_html` is sanitised on write by ContentService::sanitize_html() — the
 * tag allowlist, event-handler stripping and javascript:/data: URL rejection
 * all happen before it reaches the database. It is echoed unescaped here
 * because it is authored HTML; escaping it would print the markup instead of
 * rendering it. The write-side sanitiser is what makes that safe, so it must
 * never be bypassed by writing to this table directly.
 */
$page = isset($data['page']) ? $data['page'] : null;
if (!$page) return;

$updated = !empty($page->updated_at) ? strtotime($page->updated_at.' UTC') : null;
?>
<section class="py-12">
  <div class="container" style="max-width:820px">
    <h1><?=htmlspecialchars($page->title)?></h1>
    <?php if ($updated): ?>
      <p class="muted text-sm">Last updated <?=htmlspecialchars(date('j F Y', $updated))?></p>
    <?php endif; ?>

    <div class="ws-prose mt-6"><?=$page->body_html?></div>
  </div>
</section>

<style>
.ws-prose{line-height:1.75;color:var(--slate-700)}
.ws-prose h2{margin:2rem 0 .75rem;font-size:1.3rem}
.ws-prose h3{margin:1.5rem 0 .5rem;font-size:1.1rem}
.ws-prose p{margin:0 0 1rem}
.ws-prose ul,.ws-prose ol{margin:0 0 1rem 1.25rem}
.ws-prose li{margin:.35rem 0}
.ws-prose a{color:var(--brand-700);text-decoration:underline}
.ws-prose table{width:100%;border-collapse:collapse;margin:0 0 1rem}
.ws-prose th,.ws-prose td{border:1px solid var(--slate-200);padding:.5rem .75rem;text-align:left}
.ws-prose blockquote{margin:0 0 1rem;padding:.75rem 1rem;border-left:3px solid var(--brand-300);background:var(--slate-50)}
</style>
