<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Admin → System → Cron jobs.
 *
 * Answers the question an operator cannot otherwise answer from a browser:
 * *is the background work actually running on this host?* Order polling,
 * refill settlement, deposit reconciliation, escrow release and the mail queue
 * all depend on it, and until now the only way to check was SSH.
 */
$can_control = !empty($can_control);
$max_pause_hours = isset($max_pause_hours) ? (int)$max_pause_hours : 24;
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
$state_badge = array(
    'paused'      => array('badge badge-default', 'Paused'),
    'ok'          => array('badge badge-success badge-dot', 'Healthy'),
    'late'        => array('badge badge-warning', 'Overdue'),
    'failing'     => array('badge badge-danger', 'Failing'),
    'never'       => array('badge badge-default', 'Never run'),
    'unscheduled' => array('badge badge-warning', 'Not scheduled'),
);
$overdue = 0; $never = 0; $failing = 0; $paused = 0;
foreach ($jobs as $j) {
    if ($j['state'] === 'late') $overdue++;
    if ($j['state'] === 'never') $never++;
    if ($j['state'] === 'failing') $failing++;
    if (!empty($j['paused'])) $paused++;
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

<?php if ($can_control && ($overdue || $never)): ?>
  <div class="card mb-4" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:space-between">
    <div>
      <h3 class="text-sm font-semibold mb-1">Catch up the work that fell behind</h3>
      <p class="muted text-sm mb-0">
        Runs every <strong>overdue</strong> or <strong>never-run</strong> job once, through the same
        harness and exclusive lock as a scheduled tick. Use it while you repair the crontab below —
        it cannot replace the schedule, but it gets the panel current instead of leaving customers
        waiting for a job that has not run.
      </p>
    </div>
    <form method="post" action="<?=site_url('admin/cron/catchup')?>">
      <?=$csrf()?>
      <button class="btn btn-secondary" type="submit"
        data-confirm="Run every overdue cron job now? Each one runs exactly as a scheduled tick would, under the same exclusive lock.">
        ▶ Catch up overdue jobs
      </button>
    </form>
  </div>
<?php endif; ?>

<?php if ($paused): ?>
  <div class="alert alert-warning mb-4">
    <strong><?=$paused?> job<?=$paused === 1 ? ' is' : 's are'?> paused.</strong>
    A pause always expires — each one resumes by itself at the time shown below, so an incident
    switch cannot be left on by accident. Anything the paused job would have done is not happening
    until then.
  </div>
<?php endif; ?>

<?php
// The auto-run heartbeat answers the headline question on hosts where the
// crontab was never installed: something IS running the jobs. It rides on
// site traffic (and /health/live, which uptime monitors ping), so a working
// crontab is still the better arrangement on a quiet site.
$autorun = isset($autorun) && is_array($autorun) ? $autorun : array('enabled' => false, 'tick_age_minutes' => null);
?>
<?php if ($never === count($jobs) && count($jobs) > 0): ?>
  <div class="alert <?=$autorun['enabled'] ? 'alert-warning' : 'alert-danger'?> mb-4">
    <strong>No cron job has ever run on this installation.</strong>
    Orders will not settle, deposits will not reconcile and no email will be sent until the
    crontab below is installed. On cPanel: <em>Advanced → Cron Jobs</em>.
    <?=$autorun['enabled'] ? 'Until then, background work still runs automatically with site traffic (see Auto-run below) — the crontab simply makes it punctual.' : ''?>
    <?php if ($can_control): ?>
      You can also press <em>Run now</em> on any job below to run it once immediately —
      a quick way to confirm the jobs themselves work while you set the crontab up.
    <?php endif; ?>
  </div>
<?php elseif ($failing || $overdue): ?>
  <div class="alert alert-warning mb-4">
    <?php if ($failing): ?><strong><?=$failing?> job(s) failing.</strong> <?php endif; ?>
    <?php if ($overdue): ?><strong><?=$overdue?> job(s) overdue</strong> — they have not run for far
      longer than their schedule allows, which usually means the crontab is not installed and
      the site has had no traffic to auto-run them.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="card mb-4" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:space-between">
  <div>
    <h3 class="text-sm font-semibold mb-1">Auto-run</h3>
    <p class="muted text-sm mb-0">
      Background jobs run themselves with site traffic — no crontab required. Any page load
      (or an uptime monitor pinging <span class="mono">/health/live</span>) runs whatever is
      due, a few minutes behind at most on an active panel.
      <?php if (!$autorun['enabled']): ?>
        <strong>Currently turned off</strong> (CRON_AUTORUN env or the cron_autorun_enabled setting) —
        the crontab below is then the only scheduler.
      <?php elseif ($autorun['tick_age_minutes'] === null): ?>
        No auto tick recorded yet — it fires with the next request, a minute or more after the last one.
      <?php else: ?>
        Last auto tick <?=htmlspecialchars($ago($autorun['tick_age_minutes']))?>.
      <?php endif; ?>
      The crontab below stays the recommended arrangement for quiet sites: it runs on schedule
      whether anyone visits or not.
    </p>
  </div>
  <span class="badge <?=$autorun['enabled'] ? 'badge-success badge-dot' : 'badge-default'?>">
    <?=$autorun['enabled'] ? 'On' : 'Off'?>
  </span>
</div>

<div class="card mb-4">
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr>
          <th>Job</th><th>Schedule</th><th>State</th><th>Last run</th>
          <th class="text-right">Took</th><th class="text-right">Processed</th><th>Message</th>
          <?php if ($can_control): ?><th class="text-right">Control</th><?php endif; ?>
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
            <?php if (!empty($j['paused']) && !empty($j['control'])): ?>
              <div class="mt-1"><strong>Paused:</strong>
                <?=htmlspecialchars((string)$j['control']->reason)?></div>
              <div>Resumes automatically
                <?=htmlspecialchars((string)($j['control']->resume_at ?: 'shortly'))?> UTC</div>
            <?php endif; ?>
          </td>
          <?php if ($can_control): ?>
            <td class="text-right">
              <?php if (!empty($j['paused'])): ?>
                <form method="post" action="<?=site_url('admin/cron/resume')?>" style="display:inline">
                  <?=$csrf()?>
                  <input type="hidden" name="job" value="<?=htmlspecialchars($j['job'])?>">
                  <button class="btn btn-sm" type="submit">Resume now</button>
                </form>
              <?php else: ?>
                <div class="row" style="gap:.35rem;justify-content:flex-end;flex-wrap:wrap">
                  <form method="post" action="<?=site_url('admin/cron/run')?>" style="display:inline">
                    <?=$csrf()?>
                    <input type="hidden" name="job" value="<?=htmlspecialchars($j['job'])?>">
                    <button class="btn btn-secondary btn-sm" type="submit"
                      <?php if (!empty($j['money'])): ?>
                        data-confirm="Run <?=htmlspecialchars($j['job'])?> now? <?=htmlspecialchars($j['consequence'] ?? 'This job moves money.')?> It runs exactly as the scheduled tick would — under the same exclusive lock."
                      <?php endif; ?>
                    >▶ Run now</button>
                  </form>
                </div>
                <?php if ($j['schedule'] !== ''): ?>
                <details>
                  <summary class="btn btn-ghost btn-sm" style="cursor:pointer">Pause…</summary>
                  <form method="post" action="<?=site_url('admin/cron/pause')?>"
                        class="mt-2" style="text-align:left;min-width:16rem">
                    <?=$csrf()?>
                    <input type="hidden" name="job" value="<?=htmlspecialchars($j['job'])?>">
                    <?php if (!empty($j['consequence'])): ?>
                      <p class="alert alert-warning text-xs" style="padding:.5rem">
                        <strong>This job moves money.</strong>
                        <?=htmlspecialchars($j['consequence'])?>
                      </p>
                    <?php endif; ?>
                    <label class="text-xs" for="reason-<?=htmlspecialchars($j['job'])?>">
                      Why? (recorded in the audit log)
                    </label>
                    <input class="input input-sm" id="reason-<?=htmlspecialchars($j['job'])?>"
                           name="reason" required minlength="5"
                           placeholder="e.g. provider outage, ticket #1234">
                    <label class="text-xs mt-2" for="hours-<?=htmlspecialchars($j['job'])?>">
                      For how long? (max <?=$max_pause_hours?>h)
                    </label>
                    <input class="input input-sm" id="hours-<?=htmlspecialchars($j['job'])?>"
                           name="hours" type="number" min="1" max="<?=$max_pause_hours?>" value="1">
                    <button class="btn btn-sm btn-danger mt-2" type="submit">Pause this job</button>
                  </form>
                </details>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint mt-2">
    “Overdue” means a job has not run for more than three times its own interval. A job that has
    never run is not an error on a brand-new install — it is the crontab waiting to be added.
    <?php if ($can_control): ?>
      Pausing stops the work, not the schedule: the tick still happens and is recorded as
      <code>SKIPPED</code>, so a deliberate pause never looks like a broken crontab.
      “Run now” executes the same code the crontab would, through the same exclusive lock and
      run record — it can never overlap a scheduled tick or credit anything twice, which is what
      made a naive “trigger the sweep from a browser” dangerous. The schedule is still what keeps
      the panel running: <em>Run now</em> is for testing and catching up, not for replacing the
      crontab.
    <?php endif; ?>
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
