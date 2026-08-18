<?php defined('BASEPATH') OR exit('No direct script access allowed');
$badge = function ($status) {
    $map = array('PENDING'=>'badge-warning','APPROVED'=>'badge-default','PAID'=>'badge-success',
        'REJECTED'=>'badge-danger','CANCELLED'=>'badge-default');
    return 'badge '.($map[$status] ?? 'badge-default');
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div><a class="text-sm muted" href="<?=site_url('dashboard/withdrawals')?>">← All withdrawals</a>
    <h2 class="mb-0" style="font-size:1.3rem;font-weight:600">Withdrawal <span class="mono"><?=htmlspecialchars($withdrawal->public_id)?></span></h2>
    <span class="<?=$badge($withdrawal->status)?>"><?=htmlspecialchars($withdrawal->status)?></span>
  </div>
  <?php if ($withdrawal->status === 'PENDING'): ?>
  <?=form_open('dashboard/withdrawals/'.$withdrawal->public_id.'/cancel')?>
    <button class="btn btn-secondary btn-sm" type="submit">Cancel &amp; return funds</button>
  <?=form_close()?>
  <?php endif; ?>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card"><h3 class="card-title">Payout</h3><table class="table"><tbody>
    <tr><th>Destination</th><td><?=htmlspecialchars($withdrawal->destination_label)?></td></tr>
    <tr><th>Gross reserved</th><td class="mono"><?=windels_money($withdrawal->amount, $withdrawal->currency)?></td></tr>
    <tr><th>Fee</th><td class="mono"><?=windels_money($withdrawal->fee_amount, $withdrawal->currency)?></td></tr>
    <tr><th>Expected payout</th><td class="mono font-bold"><?=windels_money($withdrawal->payout_amount, $withdrawal->currency)?></td></tr>
    <?php if ($withdrawal->payout_reference): ?><tr><th>Transfer reference</th><td class="mono text-xs"><?=htmlspecialchars($withdrawal->payout_reference)?></td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card"><h3 class="card-title">Timeline</h3><table class="table"><tbody>
    <tr><th>Requested</th><td><?=htmlspecialchars($withdrawal->created_at)?></td></tr>
    <?php if ($withdrawal->approved_at): ?><tr><th>Approved</th><td><?=htmlspecialchars($withdrawal->approved_at)?></td></tr><?php endif; ?>
    <?php if ($withdrawal->paid_at): ?><tr><th>Paid</th><td><?=htmlspecialchars($withdrawal->paid_at)?></td></tr><?php endif; ?>
    <?php if ($withdrawal->resolved_at): ?><tr><th>Resolved</th><td><?=htmlspecialchars($withdrawal->resolved_at)?></td></tr><?php endif; ?>
  </tbody></table>
  <?php if ($withdrawal->status === 'PENDING'): ?><p class="hint mt-3">You may cancel until an operator approves the request.</p>
  <?php elseif ($withdrawal->status === 'APPROVED'): ?><p class="hint mt-3">Approved for external transfer. It can no longer be cancelled.</p>
  <?php elseif (in_array($withdrawal->status, array('REJECTED','CANCELLED'), true)): ?><div class="alert alert-warning mt-3">The full gross amount was returned to your wallet.</div>
  <?php endif; ?>
  <?php if ($withdrawal->admin_note): ?><p class="text-sm mt-3"><strong>Note:</strong> <?=htmlspecialchars($withdrawal->admin_note)?></p><?php endif; ?>
  </div>
</div>
