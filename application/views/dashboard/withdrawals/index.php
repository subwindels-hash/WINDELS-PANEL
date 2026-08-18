<?php defined('BASEPATH') OR exit('No direct script access allowed');
$currency = $wallet->currency ?? windels_base_currency();
$badge = function ($status) {
    $map = array('PENDING'=>'badge-warning','APPROVED'=>'badge-default','PAID'=>'badge-success',
        'REJECTED'=>'badge-danger','CANCELLED'=>'badge-default');
    return 'badge '.($map[$status] ?? 'badge-default');
};
$total_pages = max(1, (int)ceil($total / $per_page));
?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <h2 class="card-title">Request a withdrawal</h2>
      <p class="muted text-sm">The gross amount is reserved from your wallet immediately. Operations will review the masked payout destination before transfer.</p>

      <?php if ($identity_required && !$identity_verified): ?>
        <div class="alert alert-warning mt-4">A verified identity check is required before withdrawal. <a href="<?=site_url('dashboard/identity')?>">Complete verification →</a></div>
      <?php else: ?>
      <?=form_open('dashboard/withdrawals/create', array('class'=>'mt-4 stack','autocomplete'=>'off'))?>
        <input type="hidden" name="form_token" value="<?=htmlspecialchars($form_token)?>">
        <label class="field">
          <span class="label">Gross amount (<?=htmlspecialchars($currency)?>)</span>
          <input id="withdrawal-amount" class="input" name="amount" type="number"
                 min="<?=htmlspecialchars($minimum)?>" max="<?=htmlspecialchars($maximum)?>"
                 step="0.01" inputmode="decimal" required>
          <span class="hint">Between <?=windels_money($minimum, $currency)?> and <?=windels_money($maximum, $currency)?>.</span>
        </label>
        <div class="grid grid-2" style="gap:1rem">
          <label class="field"><span class="label">Bank or payout provider</span>
            <input class="input" name="bank_name" maxlength="80" required autocomplete="off" placeholder="Bank name"></label>
          <label class="field"><span class="label">Bank code <span class="muted">(optional)</span></span>
            <input class="input" name="bank_code" maxlength="20" autocomplete="off" placeholder="e.g. 044"></label>
        </div>
        <div class="grid grid-2" style="gap:1rem">
          <label class="field"><span class="label">Account number</span>
            <input class="input" name="account_number" inputmode="numeric" pattern="[0-9]{6,20}"
                   minlength="6" maxlength="20" required autocomplete="off"></label>
          <label class="field"><span class="label">Account holder</span>
            <input class="input" name="account_name" minlength="2" maxlength="120" required autocomplete="off"></label>
        </div>
        <p class="hint">Payout details are encrypted. After submission, only the provider and last four digits appear in ordinary screens.</p>
        <button class="btn btn-primary" type="submit" <?=$identity_required && !$identity_verified ? 'disabled' : ''?>>Reserve funds &amp; request payout</button>
      <?=form_close()?>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="card-title">Withdrawal history</h2>
      <?php if (empty($withdrawals)): ?><p class="muted">No withdrawals yet.</p>
      <?php else: ?><div class="overflow-x-auto"><table class="table">
        <thead><tr><th>Request</th><th>Destination</th><th class="text-right">Reserved</th><th class="text-right">Payout</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($withdrawals as $row): ?><tr>
          <td><a class="mono text-xs" href="<?=site_url('dashboard/withdrawals/'.$row->public_id)?>"><?=htmlspecialchars($row->public_id)?></a></td>
          <td><?=htmlspecialchars($row->destination_label)?></td>
          <td class="text-right mono"><?=windels_money($row->amount, $row->currency)?></td>
          <td class="text-right mono"><?=windels_money($row->payout_amount, $row->currency)?></td>
          <td><span class="<?=$badge($row->status)?>"><?=htmlspecialchars($row->status)?></span></td>
          <td class="text-xs muted"><?=htmlspecialchars($row->created_at)?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
      <?php if ($total_pages > 1): ?><nav class="row justify-between mt-4" aria-label="Pagination">
        <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>" href="<?=site_url('dashboard/withdrawals?page='.max(1,$page-1))?>">← Previous</a>
        <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
        <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>" href="<?=site_url('dashboard/withdrawals?page='.min($total_pages,$page+1))?>">Next →</a>
      </nav><?php endif; ?>
    </div>
  </div>

  <aside class="space-y-6">
    <div class="card">
      <h3 class="card-title">Wallet &amp; fees</h3>
      <dl class="mt-3 stack" style="gap:.6rem">
        <div class="row justify-between"><span class="muted">Available balance</span><strong><?=windels_money($wallet->balance ?? 0, $currency)?></strong></div>
        <div class="row justify-between"><span class="muted">Percentage fee</span><strong><?=rtrim(rtrim(number_format((float)$fee_percent,4),'0'),'.')?>%</strong></div>
        <div class="row justify-between"><span class="muted">Fixed fee</span><strong><?=windels_money($fixed_fee, $currency)?></strong></div>
        <div class="row justify-between border-t pt-2" style="border-color:var(--slate-200)"><span>Estimated payout</span><strong id="withdrawal-payout">—</strong></div>
      </dl>
      <p class="hint mt-3">The final fee and payout are frozen when the request is submitted.</p>
    </div>
  </aside>
</div>
<script <?=csp_nonce_attr()?>>
(function(){
  var amount=document.getElementById('withdrawal-amount'),out=document.getElementById('withdrawal-payout');
  if(!amount||!out)return;
  var pct=<?=json_encode((float)$fee_percent)?>,fixed=<?=json_encode((float)$fixed_fee)?>;
  var currency=<?=json_encode($currency)?>;
  function recalc(){var gross=parseFloat(amount.value)||0;var payout=Math.max(0,gross-(gross*pct/100)-fixed);out.textContent=new Intl.NumberFormat('en-NG',{style:'currency',currency:currency}).format(payout);}
  amount.addEventListener('input',recalc);recalc();
})();
</script>
