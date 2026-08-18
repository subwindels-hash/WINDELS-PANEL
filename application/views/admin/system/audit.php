<?php defined('BASEPATH') OR exit('No direct script access allowed');
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array(
        'resource' => $filters['resource'] ?? null,
        'actor'    => $filters['actor_id'] ?? null,
        'q'        => $filters['search'] ?? null,
        'from'     => $filters['from'] ?? null,
        'to'       => $filters['to'] ?? null,
        'page'     => $page,
    );
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};

/** A compact, escaped summary of a before/after JSON blob. */
$summarise = function ($json) {
    if ($json === null || $json === '') return '';
    $data = json_decode($json, true);
    if (!is_array($data)) return mb_strimwidth((string)$json, 0, 60, '…');
    $bits = array();
    foreach ($data as $k => $v) {
        if (is_array($v)) $v = '['.count($v).' items]';
        if (is_bool($v))  $v = $v ? 'true' : 'false';
        $bits[] = $k.'='.mb_strimwidth((string)$v, 0, 24, '…');
        if (count($bits) >= 4) { $bits[] = '…'; break; }
    }
    return implode(', ', $bits);
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Audit log</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> recorded action<?=$total == 1 ? '' : 's'?></p>
  </div>
</div>

<?php $this->load->view('admin/system/_tabs', array('tabs'=>$tabs,'area'=>$area)); ?>

<div class="alert alert-info mb-4">
  This trail is append-only. There is no edit or delete on this screen by design — a log an
  administrator can rewrite is not evidence of anything.
</div>

<form method="get" action="<?=site_url('admin/audit-logs')?>" class="row mb-4" style="gap:.35rem;flex-wrap:wrap">
  <select class="input" name="resource" aria-label="Filter by resource">
    <option value="">All resources</option>
    <?php foreach ($resources as $r): ?>
      <option value="<?=htmlspecialchars($r)?>" <?=($filters['resource'] ?? '') === $r ? 'selected' : ''?>>
        <?=htmlspecialchars($r)?>
      </option>
    <?php endforeach; ?>
  </select>
  <select class="input" name="actor" aria-label="Filter by staff member">
    <option value="">Anyone</option>
    <?php foreach ($staff as $s): ?>
      <option value="<?=(int)$s->id?>" <?=(int)($filters['actor_id'] ?? 0) === (int)$s->id ? 'selected' : ''?>>
        <?=htmlspecialchars($s->username)?>
      </option>
    <?php endforeach; ?>
  </select>
  <input class="input" type="date" name="from" value="<?=htmlspecialchars((string)($filters['from'] ?? ''))?>"
         aria-label="From date">
  <input class="input" type="date" name="to" value="<?=htmlspecialchars((string)($filters['to'] ?? ''))?>"
         aria-label="To date">
  <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
         placeholder="Action or record id" aria-label="Search the audit log" style="min-width:12rem">
  <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
</form>

<div class="card">
  <?php if (empty($entries)): ?>
    <p class="muted">No audit entries match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Change</th><th>From</th></tr>
      </thead>
      <tbody>
      <?php foreach ($entries as $e): ?>
        <tr>
          <td class="text-xs muted whitespace-nowrap">
            <?=htmlspecialchars(date('M j, H:i:s', strtotime($e->created_at)))?>
          </td>
          <td class="text-xs">
            <?php if (!empty($e->actor_public_id)): ?>
              <a href="<?=site_url('admin/customers/'.$e->actor_public_id)?>">
                <?=htmlspecialchars((string)$e->actor_name)?>
              </a>
            <?php else: ?>
              <span class="muted">system</span>
            <?php endif; ?>
          </td>
          <td class="mono text-xs"><?=htmlspecialchars((string)$e->action)?></td>
          <td class="text-xs muted">
            <?=htmlspecialchars((string)$e->resource)?>
            <?php if (!empty($e->resource_id)): ?>
              <span class="mono">#<?=htmlspecialchars((string)$e->resource_id)?></span>
            <?php endif; ?>
          </td>
          <td class="text-xs muted" style="max-width:22rem">
            <?php $b = $summarise($e->before_json); $a = $summarise($e->after_json); ?>
            <?php if ($b): ?><div>− <?=htmlspecialchars($b)?></div><?php endif; ?>
            <?php if ($a): ?><div>+ <?=htmlspecialchars($a)?></div><?php endif; ?>
          </td>
          <td class="text-xs muted mono"><?=htmlspecialchars((string)($e->ip ?: '—'))?></td>
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
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/audit-logs'.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/audit-logs'.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
