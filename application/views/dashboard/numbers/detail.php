<?php defined('BASEPATH') OR exit('No direct script access allowed');
$badge = array(
  'SUCCESSFUL' => 'badge-success', 'PROCESSING' => 'badge-warning',
  'PENDING' => 'badge-warning', 'FAILED' => 'badge-error',
  'REFUNDED' => 'badge-muted', 'CANCELLED' => 'badge-muted',
);
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};

// Mirror NumberService: a reservation the vendor no longer holds offers
// nothing, and a number that already received a code cannot be cancelled or
// reported — the service was rendered. Offering a button the service will
// refuse is worse than not offering it.
$live      = $number && in_array($number->status, array('RESERVED','RECEIVED'), true);
$has_code  = $number && (int)$number->sms_count > 0;
$expires   = $number && $number->expires_at ? strtotime($number->expires_at.' UTC') : null;
$remaining = $expires ? $expires - time() : null;
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <div>
      <h2 class="card-title mb-0">
        <?=$number ? htmlspecialchars($number->msisdn) : 'Reservation'?>
      </h2>
      <p class="muted text-sm mt-1">
        <?=$service ? htmlspecialchars($service->name) : ''?>
        <?=$country ? '· '.htmlspecialchars($country->name) : ''?>
        · <span class="mono text-xs"><?=htmlspecialchars($tx->public_id)?></span>
      </p>
    </div>
    <span class="badge <?=$badge[$tx->status] ?? 'badge-muted'?>"><?=htmlspecialchars($tx->status)?></span>
  </div>

  <?php $this->load->view('dashboard/numbers/_flash'); ?>

  <?php if ($live && $remaining !== null): ?>
    <?php if ($remaining > 0): ?>
      <div class="alert alert-info mt-4">
        This number is held until <strong><?=htmlspecialchars($number->expires_at)?> UTC</strong>
        — about <?=max(1, (int)round($remaining / 60))?> minute<?=$remaining >= 120 ? 's' : ''?> from now.
        Send your verification request to it, then check for the code.
      </div>
    <?php else: ?>
      <div class="alert alert-warning mt-4">
        The hold on this number has run out. Check once more — if no code
        arrived, the charge is refunded.
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="overflow-x-auto mt-4">
    <table class="table">
      <tbody>
        <tr><th>Number</th><td class="mono"><strong><?=$number ? htmlspecialchars($number->msisdn) : '—'?></strong></td></tr>
        <tr><th>Reservation</th><td>
          <span class="badge <?=($number && $number->status === 'RECEIVED') ? 'badge-success' : 'badge-muted'?>">
            <?=$number ? htmlspecialchars($number->status) : '—'?></span>
        </td></tr>
        <tr><th>Paid</th><td><?=marvy_money($tx->amount)?></td></tr>
        <?php if (bccomp((string)$tx->refunded_amount, '0', 8) > 0): ?>
          <tr><th>Refunded</th><td><?=marvy_money($tx->refunded_amount)?></td></tr>
        <?php endif; ?>
        <?php if ($number && $number->operator): ?>
          <tr><th>Operator</th><td><?=htmlspecialchars($number->operator)?></td></tr>
        <?php endif; ?>
        <tr><th>Rented</th><td><?=htmlspecialchars($tx->created_at)?> UTC</td></tr>
        <?php if (!empty($tx->failure_reason)): ?>
          <tr><th>Outcome</th><td><?=htmlspecialchars($tx->failure_reason)?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-4">
  <h3 class="card-title">Codes received</h3>
  <?php if (empty($messages)): ?>
    <p class="muted mt-2">
      Nothing yet. Request your verification code from
      <?=$service ? htmlspecialchars($service->name) : 'the service'?>, then press
      “Check for code”.
    </p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead><tr><th>Code</th><th>From</th><th>Message</th><th>Received</th></tr></thead>
      <tbody>
      <?php foreach ($messages as $m): ?>
        <tr>
          <td class="mono"><strong><?=htmlspecialchars((string)($m->code ?? '—'))?></strong></td>
          <td class="text-sm"><?=htmlspecialchars((string)$m->sender)?></td>
          <td class="text-sm"><?=htmlspecialchars((string)$m->body)?></td>
          <td class="text-sm muted"><?=htmlspecialchars((string)$m->received_at)?> UTC</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($live): ?>
<div class="card mt-4">
  <h3 class="card-title">Actions</h3>

  <form method="post" action="<?=site_url('dashboard/numbers/'.$tx->public_id.'/check')?>" class="mt-4">
    <?=$csrf()?>
    <button class="btn btn-primary" type="submit">Check for code</button>
  </form>

  <?php if ($has_code): ?>
    <form method="post" action="<?=site_url('dashboard/numbers/'.$tx->public_id.'/release')?>" class="mt-4">
      <?=$csrf()?>
      <p class="hint mb-2">Finished with this number? Releasing it frees the
        reservation immediately. No money moves — your code is already yours.</p>
      <button class="btn btn-secondary btn-sm" type="submit">I'm done with it</button>
    </form>
  <?php else: ?>
    <form method="post" action="<?=site_url('dashboard/numbers/'.$tx->public_id.'/cancel')?>" class="mt-4"
          onsubmit="return confirm('Cancel this reservation and get your money back?')">
      <?=$csrf()?>
      <p class="hint mb-2">No code coming? Cancel and
        <?=marvy_money($tx->amount)?> goes straight back to your wallet.</p>
      <button class="btn btn-secondary btn-sm" type="submit">Cancel and refund</button>
    </form>

    <form method="post" action="<?=site_url('dashboard/numbers/'.$tx->public_id.'/report')?>" class="mt-4"
          onsubmit="return confirm('Report this number as already registered?')">
      <?=$csrf()?>
      <p class="hint mb-2">If the service says this number is already
        registered, report it — you are refunded and we stop reselling it.</p>
      <button class="btn btn-ghost btn-sm" type="submit">Already registered</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row mt-4" style="gap:.5rem">
  <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/numbers/history')?>">History</a>
  <a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/numbers')?>">Rent another</a>
</div>
