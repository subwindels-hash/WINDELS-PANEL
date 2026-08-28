<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
$badge = function ($status) {
    switch ($status) {
        case 'SENT':    return 'badge badge-success badge-dot';
        case 'FAILED':  return 'badge badge-danger';
        case 'SENDING': return 'badge badge-info badge-dot';
        default:        return 'badge badge-default';
    }
};
$tab = function ($key, $label, $count) use ($status) {
    $active = $status === $key;
    $href = site_url('admin/mail-queue'.($key === '' ? '' : '?status='.$key));
    echo '<a class="btn btn-sm '.($active ? 'btn-primary' : 'btn-ghost').'" href="'.$href.'">'
        .htmlspecialchars($label).($count === null ? '' : ' ('.number_format($count).')').'</a>';
};
?>
<div class="card">
  <div class="row justify-between" style="gap:1rem;flex-wrap:wrap;align-items:flex-start">
    <div>
      <h2 class="card-title">Mail queue</h2>
      <p class="muted mb-0">
        Delivery happens on the <code class="mono">email_queue</code> cron job, so a message that never
        arrived leaves its reason here. Current transport:
        <strong class="mono"><?=htmlspecialchars($transport)?></strong><?php if ($transport === 'log'): ?>
          — nothing is actually being emailed; set <code class="mono">mail_transport</code> to
          <code class="mono">smtp</code> or <code class="mono">mail</code> in Settings when you are ready.
        <?php endif; ?>
      </p>
    </div>

    <form method="post" action="<?=site_url('admin/mail-queue/test')?>" class="row" style="gap:.5rem;align-items:flex-end">
      <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
      <label class="field mb-0">
        <span class="label">Send a test message to</span>
        <input class="input" type="email" name="to" placeholder="you@example.com" required>
      </label>
      <button class="btn btn-secondary" type="submit">Send test</button>
    </form>
  </div>

  <div class="row mt-4" style="gap:.4rem;flex-wrap:wrap">
    <?php
      $tab('', 'All', null);
      $tab('QUEUED', 'Queued', $counts['QUEUED']);
      $tab('SENDING', 'Sending', $counts['SENDING']);
      $tab('SENT', 'Sent', $counts['SENT']);
      $tab('FAILED', 'Failed', $counts['FAILED']);
    ?>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty-state mt-4">
      <h3>Nothing here</h3>
      <p class="muted mb-0">No messages match this filter.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto mt-4">
      <table class="table">
        <thead>
          <tr><th>To</th><th>Subject</th><th>Template</th><th>Status</th><th>Tries</th><th>When</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="text-sm"><?=htmlspecialchars($r->to_email)?></td>
              <td class="text-sm"><?=htmlspecialchars($r->subject)?></td>
              <td class="mono text-xs muted"><?=htmlspecialchars((string)$r->template_key)?></td>
              <td>
                <span class="<?=$badge($r->status)?>"><?=htmlspecialchars($r->status)?></span>
                <?php if (!empty($r->last_error)): ?>
                  <div class="text-xs" style="color:var(--rose-600,#e11d48);max-width:28rem">
                    <?=htmlspecialchars(mb_substr((string)$r->last_error, 0, 300))?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="mono text-sm"><?=(int)$r->attempts?></td>
              <td class="text-xs muted whitespace-nowrap">
                <?=htmlspecialchars((string)($r->sent_at ?: $r->scheduled_at ?: $r->created_at))?>
              </td>
              <td>
                <?php if ($r->status !== 'SENT'): ?>
                  <form method="post" action="<?=site_url('admin/mail-queue/'.(int)$r->id.'/retry')?>">
                    <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
                    <button class="btn btn-ghost btn-sm" type="submit">Retry</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
