<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Admin → Payments → Webhook events.
 *
 * The support view for "the customer says they paid but nothing arrived". A
 * callback that was received but could not be verified — or verified but
 * matched no transaction — is otherwise invisible.
 */
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
$can_manage = in_array('payments.manage', (array)$permissions, true)
    || in_array('*', (array)$permissions, true);
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-xs muted" href="<?=site_url('admin/payments')?>">← Payments</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Webhook events</h2>
    <p class="muted text-sm">
      Every gateway callback received, verified or not. Payloads are stored before anything is
      processed, so a failed payment always leaves a trace.
    </p>
  </div>
</div>

<div class="grid grid-4 mb-4">
  <div class="card">
    <div class="muted text-xs">Received</div>
    <div style="font-size:1.5rem;font-weight:600"><?=number_format($health['total'])?></div>
  </div>
  <div class="card">
    <div class="muted text-xs">Signature verified</div>
    <div style="font-size:1.5rem;font-weight:600;color:var(--color-success,#16a34a)">
      <?=number_format($health['verified'])?>
    </div>
  </div>
  <div class="card"
       style="<?=$health['unverified'] > 0 ? 'border-color:var(--color-warning,#f59e0b)' : ''?>">
    <div class="muted text-xs">Could not verify</div>
    <div style="font-size:1.5rem;font-weight:600"><?=number_format($health['unverified'])?></div>
    <?php if ($health['unverified'] > 0): ?>
      <div class="text-xs" style="color:var(--color-warning,#b45309)">
        No gateway secret configured — these credited nothing.
      </div>
    <?php endif; ?>
  </div>
  <div class="card"
       style="<?=$health['rejected'] > 0 ? 'border-color:var(--color-danger,#dc2626)' : ''?>">
    <div class="muted text-xs">Signature rejected</div>
    <div style="font-size:1.5rem;font-weight:600"><?=number_format($health['rejected'])?></div>
    <?php if ($health['rejected'] > 0): ?>
      <div class="text-xs muted">Forged or a wrong secret.</div>
    <?php endif; ?>
  </div>
</div>

<form method="get" action="<?=site_url('admin/payments/webhooks')?>"
      class="card row mb-4" style="gap:.5rem;flex-wrap:wrap;align-items:flex-end">
  <label class="field"><span class="label">Gateway</span>
    <input class="input" type="text" name="gateway" value="<?=htmlspecialchars((string)$filters['gateway'])?>"
           placeholder="fundsvera"></label>
  <label class="field"><span class="label">Signature</span>
    <select class="select" name="signature">
      <option value="">Any</option>
      <option value="1" <?=$filters['signature'] === '1' ? 'selected' : ''?>>Verified</option>
      <option value="0" <?=$filters['signature'] === '0' ? 'selected' : ''?>>Rejected</option>
      <option value="unverified" <?=$filters['signature'] === 'unverified' ? 'selected' : ''?>>Unverifiable</option>
    </select></label>
  <label class="field"><span class="label">Processed</span>
    <select class="select" name="processed">
      <option value="">Any</option>
      <option value="1" <?=$filters['processed'] === '1' ? 'selected' : ''?>>Yes</option>
      <option value="0" <?=$filters['processed'] === '0' ? 'selected' : ''?>>No</option>
    </select></label>
  <label class="field"><span class="label">Event reference</span>
    <input class="input mono" type="text" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>"></label>
  <button class="btn btn-secondary" type="submit">Filter</button>
</form>

<div class="card">
  <?php if (!$events): ?>
    <p class="muted text-sm">No webhook events match.</p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr><th>Received</th><th>Gateway</th><th>Event</th><th>Type</th>
          <th>Signature</th><th>Processed</th><th>Note</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($events as $e): ?>
      <tr>
        <td class="text-xs"><?=htmlspecialchars(date('j M H:i', strtotime($e->created_at)))?></td>
        <td class="text-xs"><?=htmlspecialchars($e->gateway_type)?></td>
        <td class="mono text-xs"><?=htmlspecialchars((string)$e->event_id)?></td>
        <td class="text-xs muted"><?=htmlspecialchars((string)$e->event_type)?></td>
        <td>
          <?php if ($e->signature_valid === null): ?>
            <span class="badge badge-warning" title="No secret configured — nothing was credited">Unverifiable</span>
          <?php elseif ((int)$e->signature_valid === 1): ?>
            <span class="badge badge-success">Verified</span>
          <?php else: ?>
            <span class="badge badge-danger">Rejected</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((int)$e->processed === 1): ?>
            <span class="badge badge-default">Yes</span>
          <?php else: ?>
            <span class="badge badge-warning">Pending</span>
          <?php endif; ?>
        </td>
        <td class="text-xs muted" style="max-width:20rem"><?=htmlspecialchars((string)$e->error)?></td>
        <td class="text-right">
          <?php if ($can_manage && (int)$e->signature_valid === 1): ?>
          <form method="post" style="margin:0"
                action="<?=site_url('admin/payments/webhooks/'.(int)$e->id.'/reprocess')?>">
            <?=$csrf()?>
            <button class="btn btn-secondary btn-sm" type="submit"
                    title="Replays the stored payload through the same idempotent path">Reprocess</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted text-xs mt-2">
    <?=number_format($total)?> event(s), page <?=(int)$page?> of <?=(int)$total_pages?>.
  </p>
  <?php endif; ?>
</div>
