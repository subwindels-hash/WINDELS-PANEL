<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between mb-4">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Drip-feed</h2>
    <p class="muted text-sm">Split a large order into scheduled runs. The total charge is reserved up-front.</p>
  </div>
  <button class="btn btn-primary" data-dialog-open="ws-new-drip" >+ New drip-feed</button>
</div>

<div class="card">
<?php if (empty($dripfeeds)): ?>
  <p class="muted">No drip-feed schedules yet.</p>
<?php else: ?>
<div class="overflow-x-auto">
  <table class="table">
    <thead><tr><th>ID</th><th>Service</th><th>Runs</th><th>Charge</th><th>Next run</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($dripfeeds as $d):
      $svc = null;
      foreach ($services as $s) { if ((int)$s->id === (int)$d->service_id) { $svc = $s; break; } }
    ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars(substr($d->public_id,0,12))?>…</td>
        <td><?=htmlspecialchars($svc->name ?? 'Service #'.$d->service_id)?></td>
        <td class="mono"><?=(int)$d->runs_completed?>/<?=(int)$d->runs?></td>
        <td><?=marvy_money($d->charge)?></td>
        <td class="text-xs muted"><?=$d->next_run_at ? date('M j, H:i', strtotime($d->next_run_at)) : '—'?></td>
        <td><span class="badge <?=($d->status==='ACTIVE'?'badge-success':($d->status==='PAUSED'?'badge-warning':'badge-default'))?>"><?=htmlspecialchars($d->status)?></span></td>
        <td><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/drip-feed/'.$d->public_id)?>">Manage →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</div>

<dialog id="ws-new-drip" class="ws-dialog" data-dialog-light-dismiss >
  <?=form_open('dashboard/drip-feed/create', array('class'=>'stack'))?>
    <h3 class="card-title mb-0">New drip-feed</h3>
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
    <label class="field"><span class="label">Service</span>
      <select name="service" class="select" required>
        <?php foreach ($services as $s): if (!(int)$s->dripfeed_supported) continue; ?>
          <option value="<?=htmlspecialchars($s->public_id)?>" data-min="<?=(int)$s->min_quantity?>" data-max="<?=(int)$s->max_quantity?>"><?=htmlspecialchars($s->name)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span class="label">Link</span><input class="input" type="url" name="link" required></label>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Quantity per run</span><input class="input" type="number" name="quantity_per_run" min="1" value="100" required></label>
      <label class="field" style="flex:1"><span class="label">Runs</span><input class="input" type="number" name="runs" min="2" max="100" value="4" required></label>
      <label class="field" style="flex:1"><span class="label">Interval (min)</span><input class="input" type="number" name="interval_minutes" min="5" value="60" required></label>
    </div>
    <p class="hint">Total = quantity per run × runs, and must be within the service min/max.</p>
    <input type="hidden" name="total_quantity" id="ws-total" value="400">
    <div class="row" style="justify-content:flex-end">
      <button type="button" class="btn btn-ghost" data-dialog-close="ws-new-drip" >Cancel</button>
      <button type="submit" class="btn btn-primary">Create schedule</button>
    </div>
  <?=form_close()?>
</dialog>

<style>.ws-dialog{border:0;border-radius:1rem;padding:0;width:min(620px,92vw);box-shadow:0 30px 80px -20px rgba(0,0,0,.4)}
.ws-dialog::backdrop{background:rgba(15,23,42,.55)} .ws-dialog form{padding:1.5rem}</style>
<script <?=csp_nonce_attr()?>>
(function(){
  var d=document.getElementById('ws-new-drip'); if(!d)return;
  function recalc(){
    var q=parseInt(d.querySelector('[name=quantity_per_run]').value,10)||0;
    var r=parseInt(d.querySelector('[name=runs]').value,10)||0;
    document.getElementById('ws-total').value=q*r;
  }
  d.querySelector('[name=quantity_per_run]').addEventListener('input',recalc);
  d.querySelector('[name=runs]').addEventListener('input',recalc);
})();
</script>
