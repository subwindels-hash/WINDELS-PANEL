<?php defined('BASEPATH') OR exit('No direct script access allowed');
$d = $dripfeed;
?>
<nav class="text-sm muted mb-4"><a href="<?=site_url('dashboard/drip-feed')?>">Drip-feed</a> · <span class="text-slate-700"><?=htmlspecialchars(substr($d->public_id,0,12))?>…</span></nav>

<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <div class="row justify-between">
        <div>
          <h2 class="card-title"><?=htmlspecialchars($service->name ?? 'Service #'.$d->service_id)?></h2>
          <p class="muted text-sm"><?=htmlspecialchars($d->link)?></p>
        </div>
        <span class="badge <?=($d->status==='ACTIVE'?'badge-success':($d->status==='PAUSED'?'badge-warning':'badge-default'))?>" style="align-self:flex-start"><?=htmlspecialchars($d->status)?></span>
      </div>
      <dl class="grid grid-4 mt-4" style="gap:1rem">
        <div><dt class="muted text-xs">Progress</dt><dd class="font-semibold"><?=(int)$d->runs_completed?>/<?=(int)$d->runs?> runs</dd></div>
        <div><dt class="muted text-xs">Per run</dt><dd><?=number_format($d->quantity_per_run)?></dd></div>
        <div><dt class="muted text-xs">Total</dt><dd><?=number_format($d->total_quantity)?></dd></div>
        <div><dt class="muted text-xs">Interval</dt><dd><?=(int)$d->interval_minutes?> min</dd></div>
      </dl>
      <div class="row mt-5" style="gap:.5rem;flex-wrap:wrap">
        <?php if ($d->status==='ACTIVE'): ?>
        <form method="post" action="<?=site_url('dashboard/drip-feed/'.$d->public_id.'/pause')?>">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <button class="btn btn-secondary" type="submit">Pause</button>
        </form>
        <?php elseif ($d->status==='PAUSED'): ?>
        <form method="post" action="<?=site_url('dashboard/drip-feed/'.$d->public_id.'/resume')?>">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <button class="btn btn-secondary" type="submit">Resume</button>
        </form>
        <?php endif; ?>
        <?php if (!in_array($d->status,array('CANCELED','COMPLETED'),true)): ?>
        <form method="post" action="<?=site_url('dashboard/drip-feed/'.$d->public_id.'/cancel')?>" onsubmit="return confirm('Cancel this drip-feed? Unspent reserve is refunded.')">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <button class="btn btn-danger" type="submit">Cancel &amp; refund</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h3 class="card-title">Runs</h3>
      <div class="overflow-x-auto mt-2">
      <table class="table">
        <thead><tr><th>#</th><th>Status</th><th>Executed</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
          <tr>
            <td class="mono"><?=(int)$r->run_number?></td>
            <td><span class="badge <?=$r->status==='COMPLETED'?'badge-success':($r->status==='FAILED'?'badge-danger':'badge-default')?>"><?=htmlspecialchars($r->status)?></span></td>
            <td class="text-xs muted"><?=$r->executed_at ? date('M j, H:i', strtotime($r->executed_at)) : 'scheduled'?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <aside class="card">
    <h3 class="card-title">Schedule</h3>
    <dl class="stack" style="gap:.5rem">
      <div class="row justify-between"><span class="muted">Charge reserved</span><strong><?=marvy_money($d->charge)?></strong></div>
      <div class="row justify-between"><span class="muted">Starts</span><span class="text-sm"><?=date('M j, H:i', strtotime($d->start_at))?> UTC</span></div>
      <div class="row justify-between"><span class="muted">Next run</span><span class="text-sm"><?=$d->next_run_at?date('M j, H:i',strtotime($d->next_run_at)):'—'?></span></div>
    </dl>
  </aside>
</div>
