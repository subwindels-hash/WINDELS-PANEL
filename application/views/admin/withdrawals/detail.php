<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$can_process = in_array('*',$perms,true) || in_array('withdrawals.process',$perms,true);
$can_reveal = in_array('*',$perms,true) || in_array('withdrawals.reveal',$perms,true);
$badge = function ($status) {
    $map = array('PENDING'=>'badge-warning','APPROVED'=>'badge-default','PAID'=>'badge-success',
        'REJECTED'=>'badge-danger','CANCELLED'=>'badge-default');
    return 'badge '.($map[$status] ?? 'badge-default');
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div><a class="text-sm muted" href="<?=site_url('admin/withdrawals')?>">← Withdrawal queue</a>
    <h2 class="mb-0" style="font-size:1.3rem;font-weight:600">Withdrawal <span class="mono"><?=htmlspecialchars($withdrawal->public_id)?></span></h2>
    <span class="<?=$badge($withdrawal->status)?>"><?=htmlspecialchars($withdrawal->status)?></span>
  </div>
</div>
<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card"><h3 class="card-title">Request</h3><table class="table"><tbody>
    <tr><th>Customer</th><td><?=htmlspecialchars((string)$withdrawal->username)?> <span class="text-xs muted"><?=htmlspecialchars((string)$withdrawal->email)?></span></td></tr>
    <tr><th>Destination</th><td><?=htmlspecialchars($withdrawal->destination_label)?></td></tr>
    <tr><th>Gross reserved</th><td class="mono"><?=windels_money($withdrawal->amount,$withdrawal->currency)?></td></tr>
    <tr><th>Fee</th><td class="mono"><?=windels_money($withdrawal->fee_amount,$withdrawal->currency)?></td></tr>
    <tr><th>Transfer amount</th><td class="mono font-bold"><?=windels_money($withdrawal->payout_amount,$withdrawal->currency)?></td></tr>
    <tr><th>Requested</th><td class="text-xs"><?=htmlspecialchars($withdrawal->created_at)?></td></tr>
    <?php if ($withdrawal->payout_reference): ?><tr><th>Transfer reference</th><td class="mono text-xs"><?=htmlspecialchars($withdrawal->payout_reference)?></td></tr><?php endif; ?>
    <?php if ($withdrawal->admin_note): ?><tr><th>Note</th><td><?=htmlspecialchars($withdrawal->admin_note)?></td></tr><?php endif; ?>
  </tbody></table>
  <?php if ($can_reveal): ?><?=form_open('admin/withdrawals/'.$withdrawal->public_id.'/reveal',array('class'=>'mt-3'))?><button class="btn btn-secondary btn-sm" type="submit">Reveal payout destination</button><?=form_close()?>
  <?php else: ?><p class="hint mt-3">Payout destination reveal requires separate permission.</p><?php endif; ?>
  </div>

  <div class="card"><h3 class="card-title">Process</h3>
    <?php if (!$can_process): ?><p class="muted">You have read-only access to withdrawals.</p>
    <?php elseif ($withdrawal->status==='PENDING'): ?>
      <p class="text-sm muted">Approving confirms the request is ready for an external transfer. It does not move wallet funds again.</p>
      <?=form_open('admin/withdrawals/'.$withdrawal->public_id.'/approve',array('class'=>'mt-3 mb-4'))?><input class="input mb-2" name="note" maxlength="500" placeholder="Review note (optional)"><button class="btn btn-primary btn-sm" type="submit">Approve for payment</button><?=form_close()?>
      <?=form_open('admin/withdrawals/'.$withdrawal->public_id.'/reject')?><input class="input mb-2" name="reason" minlength="3" maxlength="500" required placeholder="Rejection reason"><button class="btn btn-secondary btn-sm" type="submit">Reject &amp; return full amount</button><?=form_close()?>
    <?php elseif ($withdrawal->status==='APPROVED'): ?>
      <?php if (empty($destination)): ?><div class="alert alert-warning">Reveal and verify the destination before recording an external transfer.</div><?php endif; ?>
      <?=form_open('admin/withdrawals/'.$withdrawal->public_id.'/paid',array('class'=>'mt-3 mb-4'))?><label class="field"><span class="label">Bank / processor transfer reference</span><input class="input" name="payout_reference" minlength="3" maxlength="128" required></label><input class="input mt-2 mb-2" name="note" maxlength="500" placeholder="Settlement note (optional)"><button class="btn btn-primary btn-sm" type="submit">Confirm externally paid</button><?=form_close()?>
      <?=form_open('admin/withdrawals/'.$withdrawal->public_id.'/reject')?><input class="input mb-2" name="reason" minlength="3" maxlength="500" required placeholder="Rejection reason"><button class="btn btn-secondary btn-sm" type="submit">Reject &amp; return full amount</button><?=form_close()?>
    <?php elseif ($withdrawal->status==='PAID'): ?><div class="alert alert-success">External payment recorded. No additional wallet movement was made.</div>
    <?php else: ?><div class="alert alert-warning">This request is resolved. The gross reserved amount was returned exactly once.</div><?php endif; ?>
  </div>
</div>
<?php if (!empty($destination)): ?>
<div class="card mt-4"><div class="alert alert-warning">Sensitive payout details are visible for this response only. Access was recorded.</div><h3 class="card-title mt-3">Decrypted destination</h3><table class="table"><tbody>
  <tr><th>Bank / provider</th><td><?=htmlspecialchars($destination['bank_name'])?></td></tr>
  <?php if (!empty($destination['bank_code'])): ?><tr><th>Bank code</th><td class="mono"><?=htmlspecialchars($destination['bank_code'])?></td></tr><?php endif; ?>
  <tr><th>Account number</th><td class="mono"><?=htmlspecialchars($destination['account_number'])?></td></tr><tr><th>Account holder</th><td><?=htmlspecialchars($destination['account_name'])?></td></tr>
</tbody></table></div>
<?php endif; ?>
<div class="card mt-4"><h3 class="card-title">Event log</h3><?php if(empty($events)):?><p class="muted">No events.</p><?php else:?><div class="overflow-x-auto"><table class="table"><thead><tr><th>When</th><th>Event</th><th>From</th><th>To</th><th>Actor</th><th>Note</th></tr></thead><tbody><?php foreach($events as $event):?><tr><td class="text-xs muted"><?=htmlspecialchars($event->created_at)?></td><td><?=htmlspecialchars($event->event_type)?></td><td><?=htmlspecialchars((string)($event->from_status??'—'))?></td><td><?=htmlspecialchars((string)($event->to_status??'—'))?></td><td><?=htmlspecialchars((string)($event->actor_id??'system'))?></td><td class="text-xs muted"><?=htmlspecialchars((string)$event->note)?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
