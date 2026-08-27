<?php defined('BASEPATH') OR exit('No direct script access allowed');
$labels = array(
    'first_name'=>'First name', 'middle_name'=>'Middle name', 'last_name'=>'Last name',
    'date_of_birth'=>'Date of birth', 'gender'=>'Gender', 'phone_number'=>'Phone number',
    'nationality'=>'Nationality', 'state_of_origin'=>'State of origin',
    'lga_of_origin'=>'LGA of origin',
);
$status   = $check ? $check->status : 'PENDING';
$purged   = $check && !empty($check->purged_at);
$readable = $status === 'VERIFIED' && !$purged && !empty($check->result_encrypted);
$refunded = bccomp((string)$tx->refunded_amount, '0', 8) > 0;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('dashboard/identity/history')?>">← All checks</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=$check ? htmlspecialchars($check->id_type) : 'Identity'?> check
      <?php if ($check && $check->identifier_last4): ?>
        <span class="mono text-sm">•••• <?=htmlspecialchars($check->identifier_last4)?></span>
      <?php endif; ?>
    </h2>
    <p class="muted text-sm"><?=htmlspecialchars((string)$tx->created_at)?> UTC</p>
  </div>
</div>

<?php $this->load->view('dashboard/identity/_flash'); ?>

<?php if ($status === 'NOT_FOUND'): ?>
<div class="alert alert-warning mb-4">
  No record was found for this number in the national database. You have not
  been charged<?=$refunded ? ' — ' . marvy_money($tx->refunded_amount, $tx->currency) . ' was returned to your wallet' : ''?>.
  Check the digits and try again if you think it was a typo.
</div>
<?php elseif ($status === 'FAILED'): ?>
<div class="alert alert-error mb-4">
  This check could not be completed<?=$tx->failure_reason ? ': '.htmlspecialchars($tx->failure_reason) : '.'?>
  <?=$refunded ? ' You have been refunded '.marvy_money($tx->refunded_amount, $tx->currency).'.' : ''?>
</div>
<?php endif; ?>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">This check</h3>
    <table class="table">
      <tbody>
        <tr><th>Type</th><td><?=$product ? htmlspecialchars($product->name) : htmlspecialchars((string)$tx->service_type)?></td></tr>
        <tr><th>Number checked</th><td class="mono">
          <?=$check && $check->identifier_last4
              ? '•••••••'.htmlspecialchars($check->identifier_last4) : '—'?>
          <div class="muted text-xs">We only keep the last four digits.</div>
        </td></tr>
        <tr><th>Outcome</th><td>
          <span class="badge <?=$status === 'VERIFIED' ? 'badge-success' : ($status === 'NOT_FOUND' ? 'badge-warning' : ($status === 'FAILED' ? 'badge-error' : 'badge-muted'))?>">
            <?=htmlspecialchars($status)?></span>
        </td></tr>
        <tr><th>Paid</th><td class="mono"><?=marvy_money($tx->amount, $tx->currency)?>
          <?php if ($refunded): ?>
            <div class="muted text-xs">refunded <?=marvy_money($tx->refunded_amount, $tx->currency)?></div>
          <?php endif; ?>
        </td></tr>
        <tr><th>Reference</th><td class="mono text-xs"><?=htmlspecialchars($tx->public_id)?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Result</h3>

    <?php if ($entity): ?>
      <table class="table">
        <tbody>
        <?php foreach ($labels as $key => $label): if (empty($entity[$key])) continue; ?>
          <tr><th><?=htmlspecialchars($label)?></th>
              <td><?=htmlspecialchars((string)$entity[$key])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint mt-2">
        This record was opened just now, and that has been logged. Close this
        page when you are done — it is not kept on screen.
      </p>

    <?php elseif ($purged): ?>
      <p class="muted text-sm">
        This result has passed its <?=(int)$retention_days?>-day retention period and
        has been permanently deleted. The record of the check itself remains.
      </p>

    <?php elseif ($readable): ?>
      <p class="muted text-sm mb-4">
        The result is stored encrypted. Open it to view the details —
        we record each time it is opened.
      </p>
      <form method="post" action="<?=site_url('dashboard/identity/'.$tx->public_id.'/reveal')?>">
        <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>"
               value="<?=$this->security->get_csrf_hash()?>">
        <button class="btn btn-primary btn-sm" type="submit">Show result</button>
      </form>
      <?php if ((int)$check->reveal_count > 0): ?>
        <p class="hint mt-2">Opened <?=(int)$check->reveal_count?> time(s);
           last on <?=htmlspecialchars((string)$check->last_revealed_at)?> UTC.</p>
      <?php endif; ?>
      <p class="hint mt-2">
        Deleted automatically <?=(int)$retention_days?> days after the check.
      </p>

    <?php else: ?>
      <p class="muted text-sm">There is no result to show for this check.</p>
    <?php endif; ?>
  </div>
</div>
