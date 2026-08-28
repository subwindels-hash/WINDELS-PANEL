<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between mb-4">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Support tickets</h2>
    <p class="muted text-sm">Questions about an order or deposit? Open a ticket and our team will reply.</p>
  </div>
  <button class="btn btn-primary" data-dialog-open="ws-new-ticket" >+ New ticket</button>
</div>

<div class="card">
<?php if (empty($tickets)): ?>
  <?php $this->load->view('partials/empty_state', array(
      'icon'  => 'message-square',
      'title' => 'No support tickets yet',
      'body'  => 'Questions about an order or payment can be raised here and tracked in one thread.',
      'action_href'  => site_url('dashboard/tickets/create'),
      'action_label' => 'Open a ticket',
  )); ?>
<?php else: ?>
<div class="overflow-x-auto">
  <table class="table">
    <thead><tr><th>Reference</th><th>Subject</th><th>Status</th><th>Priority</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars(substr($t->public_id,0,12))?>…</td>
        <td><?=htmlspecialchars($t->subject)?></td>
        <td><span class="badge <?=$t->status==='OPEN'?'badge-success':($t->status==='CLOSED'?'badge-default':'badge-warning')?>"><?=htmlspecialchars($t->status)?></span></td>
        <td class="text-sm"><?=htmlspecialchars($t->priority)?></td>
        <td class="text-xs muted"><?=date('M j, H:i', strtotime($t->updated_at))?> UTC</td>
        <td><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/tickets/'.$t->public_id)?>">Open →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</div>

<dialog id="ws-new-ticket" class="ws-dialog" data-dialog-light-dismiss >
  <?=form_open_multipart('dashboard/tickets/create', array('class'=>'stack'))?>
    <h3 class="card-title mb-0">Open a ticket</h3>
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
    <label class="field"><span class="label">Subject</span>
      <input class="input" name="subject" required maxlength="255" placeholder="e.g. Order not progressing">
    </label>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Department</span>
        <select class="select" name="department">
          <option>orders</option><option>payments</option><option>api</option><option>other</option>
        </select>
      </label>
      <label class="field" style="flex:1"><span class="label">Priority</span>
        <select class="select" name="priority"><option>LOW</option><option selected>MEDIUM</option><option>HIGH</option></select>
      </label>
    </div>
    <label class="field"><span class="label">Related order (optional, ULID)</span>
      <input class="input mono" name="order_id" maxlength="26" placeholder="01ORDER...">
    </label>
    <label class="field"><span class="label">Message</span>
      <textarea class="textarea" name="message" required rows="5" maxlength="20000"></textarea>
    </div>
    <div>
      <label class="text-sm font-medium" for="new-attachments">Attach files (optional)</label>
      <input class="input" id="new-attachments" type="file" name="attachments[]" multiple
             accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
      <p class="hint">A screenshot usually answers the first question support would ask. Up to 5 files.</p>
    </label>
    <div class="row" style="justify-content:flex-end">
      <button type="button" class="btn btn-ghost" data-dialog-close="ws-new-ticket" >Cancel</button>
      <button type="submit" class="btn btn-primary">Open ticket</button>
    </div>
  <?=form_close()?>
</dialog>
<style>.ws-dialog{border:0;border-radius:1rem;padding:0;width:min(620px,92vw);box-shadow:0 30px 80px -20px rgba(0,0,0,.4)}
.ws-dialog::backdrop{background:rgba(15,23,42,.55)} .ws-dialog form{padding:1.5rem}</style>
