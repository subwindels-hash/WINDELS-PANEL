<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

$qs = function (array $over = array()) use ($filters, $page, $domain) {
    $base = array(
        'status'   => $filters['status'] ?? null,
        'q'        => $filters['search'] ?? null,
        'category' => $filters['category_id'] ?? ($filters['category'] ?? null),
        'severity' => $filters['severity'] ?? null,
        'audience' => $filters['audience'] ?? null,
        'page'     => $page,
    );
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};

$sev_badge = function ($s) {
    $map = array('INFO'=>'badge-default','SUCCESS'=>'badge-success','WARNING'=>'badge-warning','CRITICAL'=>'badge-danger');
    return $map[strtoupper((string)$s)] ?? 'badge-default';
};
$status_badge = function ($s) {
    $map = array('PUBLISHED'=>'badge-success','DRAFT'=>'badge-default','ARCHIVED'=>'badge-warning');
    return $map[strtoupper((string)$s)] ?? 'badge-default';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600"><?=htmlspecialchars(ContentService::label($domain))?></h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> item<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <a class="btn btn-primary" href="<?=site_url('admin/'.$domain.'/new')?>">+ New</a>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <?php foreach ($domains as $key => $label): ?>
    <?php if (!$has(ContentService::permission($key))) continue; ?>
    <a class="btn btn-sm <?=$domain === $key ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/'.$key)?>"><?=htmlspecialchars($label)?></a>
  <?php endforeach; ?>
</div>

<form method="get" action="<?=site_url('admin/'.$domain)?>" class="row mb-4" style="gap:.35rem;flex-wrap:wrap">
  <?php if ($domain === 'blog'): ?>
    <select class="input" name="status" aria-label="Filter by status">
      <option value="">All statuses</option>
      <?php foreach (ContentService::BLOG_STATUSES as $s): ?>
        <option value="<?=htmlspecialchars($s)?>" <?=($filters['status'] ?? '') === $s ? 'selected' : ''?>>
          <?=htmlspecialchars($s)?><?=isset($counts[$s]) ? ' ('.number_format($counts[$s]).')' : ''?>
        </option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="category" aria-label="Filter by category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?=(int)$c->id?>" <?=(int)($filters['category_id'] ?? 0) === (int)$c->id ? 'selected' : ''?>>
          <?=htmlspecialchars($c->name)?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php else: ?>
    <select class="input" name="status" aria-label="Filter by visibility">
      <option value="">Shown and hidden</option>
      <option value="active"   <?=($filters['status'] ?? '') === 'active' ? 'selected' : ''?>>Shown</option>
      <option value="inactive" <?=($filters['status'] ?? '') === 'inactive' ? 'selected' : ''?>>Hidden</option>
    </select>
  <?php endif; ?>

  <?php if ($domain === 'faq' && $categories): ?>
    <select class="input" name="category" aria-label="Filter by category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?=htmlspecialchars($c)?>" <?=($filters['category'] ?? '') === $c ? 'selected' : ''?>>
          <?=htmlspecialchars($c)?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>

  <?php if ($domain === 'announcements'): ?>
    <select class="input" name="severity" aria-label="Filter by severity">
      <option value="">All severities</option>
      <?php foreach (ContentService::SEVERITIES as $s): ?>
        <option value="<?=htmlspecialchars($s)?>" <?=($filters['severity'] ?? '') === $s ? 'selected' : ''?>>
          <?=htmlspecialchars($s)?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>

  <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
         placeholder="Search" aria-label="Search content" style="min-width:12rem">
  <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
</form>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="muted">Nothing here yet. Use <strong>+ New</strong> to add the first one.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <?php if ($domain === 'blog'): ?>
          <tr><th>Title</th><th>Category</th><th>Status</th><th class="text-right">Views</th>
              <th>Published</th><th></th></tr>
        <?php elseif ($domain === 'faq'): ?>
          <tr><th style="width:3rem">#</th><th>Question</th><th>Category</th><th>Visible</th><th></th></tr>
        <?php else: ?>
          <tr><th>Title</th><th>Severity</th><th>Audience</th><th>Window</th><th>Visible</th><th></th></tr>
        <?php endif; ?>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php $handle = $domain === 'faq' ? $r->id : $r->public_id; ?>
        <tr>
          <?php if ($domain === 'blog'): ?>
            <td>
              <a class="font-medium" href="<?=site_url('admin/blog/'.$handle)?>"><?=htmlspecialchars((string)$r->title)?></a>
              <div class="text-xs muted mono">/<?=htmlspecialchars((string)$r->slug)?></div>
            </td>
            <td class="text-xs muted"><?=htmlspecialchars((string)($r->category_name ?? '—'))?></td>
            <td><span class="badge <?=$status_badge($r->status)?>"><?=htmlspecialchars((string)$r->status)?></span></td>
            <td class="text-right mono text-xs"><?=number_format((int)$r->views)?></td>
            <td class="text-xs muted whitespace-nowrap">
              <?=$r->published_at ? htmlspecialchars(date('M j, Y', strtotime($r->published_at))) : '—'?>
            </td>

          <?php elseif ($domain === 'faq'): ?>
            <td class="mono text-xs muted"><?=(int)$r->sorting?></td>
            <td>
              <a class="font-medium" href="<?=site_url('admin/faq/'.$handle)?>">
                <?=htmlspecialchars(mb_strimwidth((string)$r->question, 0, 90, '…'))?>
              </a>
            </td>
            <td class="text-xs muted"><?=htmlspecialchars((string)($r->category ?: '—'))?></td>
            <td>
              <span class="badge <?=$r->is_active ? 'badge-success' : 'badge-default'?>">
                <?=$r->is_active ? 'shown' : 'hidden'?>
              </span>
            </td>

          <?php else: ?>
            <td>
              <a class="font-medium" href="<?=site_url('admin/announcements/'.$handle)?>">
                <?=htmlspecialchars((string)$r->title)?>
              </a>
            </td>
            <td><span class="badge <?=$sev_badge($r->severity)?>"><?=htmlspecialchars((string)$r->severity)?></span></td>
            <td class="text-xs muted"><?=htmlspecialchars((string)($r->audience ?: 'all'))?></td>
            <td class="text-xs muted whitespace-nowrap">
              <?=$r->starts_at ? htmlspecialchars(date('M j', strtotime($r->starts_at))) : 'now'?>
              →
              <?=$r->ends_at ? htmlspecialchars(date('M j', strtotime($r->ends_at))) : 'never'?>
            </td>
            <td>
              <span class="badge <?=$r->is_active ? 'badge-success' : 'badge-default'?>">
                <?=$r->is_active ? 'shown' : 'hidden'?>
              </span>
            </td>
          <?php endif; ?>

          <td>
            <div class="row" style="gap:.25rem;justify-content:flex-end">
              <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/'.$domain.'/'.$handle)?>">Edit</a>
              <?php if ($domain !== 'blog'): ?>
                <form method="post" action="<?=site_url('admin/'.$domain.'/'.$handle.'/status')?>" style="display:inline">
                  <?=$csrf()?>
                  <input type="hidden" name="is_active" value="<?=$r->is_active ? '0' : '1'?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?=$r->is_active ? 'Hide' : 'Show'?></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="row justify-between mt-4" style="align-items:center">
  <span class="muted text-sm">Page <?=number_format($page)?> of <?=number_format($total_pages)?></span>
  <div class="row" style="gap:.35rem">
    <?php if ($page > 1): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/'.$domain.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/'.$domain.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
