<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Admin → System → Cron jobs.
 *
 * Answers the question an operator cannot otherwise answer from a browser:
 * *is the background work actually running on this host?* Order polling,
 * refill settlement, deposit reconciliation, escrow release and the mail queue
 * all depend on it, and until now the only way to check was SSH.
 */
$state_badge = array(
    'ok'          => array('badge badge-success badge-dot', 'Healthy'),
    'late'        => array('badge badge-warning', 'Overdue'),
    'failing'     => array('badge badge-danger', 'Failing'),
    'never'       => array('badge badge-default', 'Never run'),
    'unscheduled' => array('badge badge-warning', 'Not scheduled'),
);
$overdue = 0; $never = 0; $failing = 0;
foreach ($jobs as $j) {
    if ($j['state'] === 'late') $overdue++;
    if ($j['state'] === 'never') $never++;
    if ($j['state'] === 'failing') $failing++;
}
$ago = function ($minutes) {
    if ($minutes === null) return '—';
    if ($minutes < 1) return 'just now';
    if ($minutes < 60) return $minutes.' min ago';
    if ($minutes < 1440) return round($minutes / 60).' h ago';
    return round($minutes / 1440).' d ago';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Cron jobs</h2>
    <p class="muted text-sm">
      <?=count($jobs)?> job<?=count($jobs) === 1 ? '' : 's'?> · schedules come from the application,
      so this list and the crontab below can never drift apart.
    </p>
  </div>
</div>

<?php if ($never === count($jobs) && count($jobs) > 0): ?>
  <div class="alert alert-danger mb-4">
    <strong>No cron job has ever run on this installation.</strong>
    Orders will not settle, deposits will not reconcile and no email will be sent until the
    crontab below is installed. On cPanel: <em>Advanced → Cron Jobs</em>.
  </div>
<?php elseif ($failing || $overdue): ?>
  <div class="alert alert-warning mb-4">
    <?php if ($failing): ?><strong><?=$failing?> job(s) failing.</strong> <?php endif; ?>
    <?php if ($overdue): ?><strong><?=$overdue?> job(s) overdue</strong> — they have not run for far
      longer than their schedule allows, which usually means the crontab is not installed or the
      PHP path in it is wrong.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr>
          <th>Job</th><th>Schedule</th><th>State</th><th>Last run</th>
          <th class="text-right">Took</th><th class="text-right">Processed</th><th>Message</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($jobs as $j): $badge = $state_badge[$j['state']] ?? $state_badge['never']; ?>
        <tr>
          <td>
            <div class="mono text-sm"><?=htmlspecialchars($j['job'])?></div>
            <div class="text-xs muted mono"><?=htmlspecialchars($j['command'])?></div>
          </td>
          <td>
            <div class="mono text-xs"><?=htmlspecialchars($j['schedule'] ?: '—')?></div>
            <div class="text-xs muted"><?=htmlspecialchars($j['human'])?></div>
          </td>
          <td><span class="<?=$badge[0]?>"><?=$badge[1]?></span></td>
          <td class="text-xs">
            <?php if (!empty($j['last'])): ?>
              <div><?=htmlspecialchars(date('M j, H:i', strtotime($j['last']->started_at)))?> UTC</div>
              <div class="muted"><?=htmlspecialchars($ago($j['age']))?></div>
            <?php else: ?><span class="muted">never</span><?php endif; ?>
          </td>
          <td class="text-right mono text-xs">
            <?=!empty($j['last']) && $j['last']->duration_ms !== null
                ? number_format((int)$j['last']->duration_ms).' ms' : '—'?>
          </td>
          <td class="text-right mono text-xs">
            <?=!empty($j['last']) && $j['last']->processed !== null
                ? number_format((int)$j['last']->processed) : '—'?>
          </td>
          <td class="text-xs muted" style="max-width:22rem">
            <?=htmlspecialchars((string)($j['last']->message ?? ''))?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint mt-2">
    “Overdue” means a job has not run for more than three times its own interval. A job that has
    never run is not an error on a brand-new install — it is the crontab waiting to be added.
  </p>
</div>

<div class="card mb-4">
  <h3 class="text-sm font-semibold mb-2">Crontab to install</h3>
  <p class="muted text-sm mb-2">
    Generated from the schedules this build actually uses. Replace the document root, then paste it
    into <code>crontab -e</code> — or add the lines individually in cPanel → Advanced → Cron Jobs.
  </p>
  <pre class="mono text-xs" style="background:var(--slate-900);color:#e2e8f0;padding:1rem;border-radius:.75rem;overflow:auto"><?=htmlspecialchars(implode("\n", $crontab))?></pre>
  <p class="hint mt-2">
    Each job takes an exclusive lock, so an overlapping tick is safe: a slow run never gets a second
    copy of itself.
  </p>
</div>

<div class="card">
  <h3 class="text-sm font-semibold mb-2">Recent runs</h3>
  <?php if (empty($runs)): ?>
    <p class="muted text-sm">Nothing has run yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>Started</th><th>Job</th><th>Status</th><th class="text-right">Took</th>
                 <th class="text-right">Processed</th><th class="text-right">Failed</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td class="text-xs whitespace-nowrap"><?=htmlspecialchars(date('M j, H:i:s', strtotime($r->started_at)))?></td>
          <td class="mono text-xs"><?=htmlspecialchars($r->job)?></td>
          <td>
            <span class="badge <?=$r->status === 'FAILED' ? 'badge-danger' : ($r->status === 'RUNNING' ? 'badge-info' : 'badge-success')?>">
              <?=htmlspecialchars($r->status)?>
            </span>
          </td>
          <td class="text-right mono text-xs"><?=$r->duration_ms === null ? '—' : number_format((int)$r->duration_ms).' ms'?></td>
          <td class="text-right mono text-xs"><?=$r->processed === null ? '—' : number_format((int)$r->processed)?></td>
          <td class="text-right mono text-xs"><?=$r->failed === null ? '—' : number_format((int)$r->failed)?></td>
          <td class="text-xs muted" style="max-width:26rem"><?=htmlspecialchars((string)$r->message)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
