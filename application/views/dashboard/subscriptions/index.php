<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between mb-4">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Subscriptions</h2>
    <p class="muted text-sm">Automatically order on a daily, weekly or monthly cadence.</p>
  </div>
  <button class="btn btn-primary" data-dialog-open="ws-new-sub" >+ New subscription</button>
</div>

<div class="card">
<?php if (empty($subscriptions)): ?>
  <?php $this->load->view('partials/empty_state', array(
      'icon'  => 'repeat',
      'title' => 'No subscriptions yet',
      'body'  => 'Recurring orders you set up will appear here with their next run and status.',
  )); ?>
<?php else: ?>
<div class="overflow-x-auto">
  <table class="table">
    <thead><tr><th>ID</th><th>Service</th><th>Qty</th><th>Interval</th><th>Next</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($subscriptions as $s):
      $svc = null;
      foreach ($services as $x) { if ((int)$x->id===(int)$s->service_id) { $svc=$x; break; } }
    ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars(substr($s->public_id,0,12))?>…</td>
        <td><?=htmlspecialchars($svc->name ?? 'Service #'.$s->service_id)?></td>
        <td><?=number_format($s->quantity)?></td>
        <td class="text-sm"><?=htmlspecialchars(ucfirst($s->interval_type))?></td>
        <td class="text-xs muted"><?=$s->next_execution_at?date('M j, H:i',strtotime($s->next_execution_at)):'—'?></td>
        <td><span class="badge <?=($s->status==='ACTIVE'?'badge-success':($s->status==='PAUSED'?'badge-warning':'badge-default'))?>"><?=htmlspecialchars($s->status)?></span></td>
        <td>
          <?php if ($s->status==='ACTIVE'): ?>
          <form method="post" action="<?=site_url('dashboard/subscriptions/'.$s->public_id.'/pause')?>" class="inline"><input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly><button class="btn btn-ghost btn-sm">Pause</button></form>
          <?php elseif ($s->status==='PAUSED'): ?>
          <form method="post" action="<?=site_url('dashboard/subscriptions/'.$s->public_id.'/resume')?>" class="inline"><input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly><button class="btn btn-ghost btn-sm">Resume</button></form>
          <?php endif; ?>
          <?php if (!in_array($s->status,array('CANCELED','EXPIRED'),true)): ?>
          <form method="post" action="<?=site_url('dashboard/subscriptions/'.$s->public_id.'/cancel')?>" class="inline"><input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly><button class="btn btn-ghost btn-sm text-rose-600">Cancel</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</div>

<dialog id="ws-new-sub" class="ws-dialog" data-dialog-light-dismiss >
  <?=form_open('dashboard/subscriptions/create', array('class'=>'stack'))?>
    <h3 class="card-title mb-0">New subscription</h3>
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
    <label class="field"><span class="label">Service</span>
      <select name="service" class="select" required>
        <?php foreach ($services as $s): if (!(int)$s->subscription_supported) continue; ?>
          <option value="<?=htmlspecialchars($s->public_id)?>"><?=htmlspecialchars($s->name)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span class="label">Target profile / link</span><input class="input" name="target" required maxlength="500"></label>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Quantity</span><input class="input" type="number" name="quantity" min="1" value="100" required></label>
      <label class="field" style="flex:1"><span class="label">Interval</span>
        <select class="select" name="interval_type">
          <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
        </select>
      </label>
    </div>
    <label class="field"><span class="label">Posts to cover (optional)</span><input class="input" type="number" name="posts" min="1" placeholder="unlimited"></label>
    <div class="row" style="justify-content:flex-end">
      <button type="button" class="btn btn-ghost" data-dialog-close="ws-new-sub" >Cancel</button>
      <button type="submit" class="btn btn-primary">Create</button>
    </div>
  <?=form_close()?>
</dialog>
<style>.ws-dialog{border:0;border-radius:1rem;padding:0;width:min(560px,92vw);box-shadow:0 30px 80px -20px rgba(0,0,0,.4)}
.ws-dialog::backdrop{background:rgba(15,23,42,.55)} .ws-dialog form{padding:1.5rem}
.inline{display:inline}</style>
