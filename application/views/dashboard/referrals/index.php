<?php defined('BASEPATH') OR exit('No direct script access allowed');
$percent  = rtrim(rtrim(number_format((float)$stats['percent'], 4, '.', ''), '0'), '.');
$currency = windels_base_currency();
?>
<?php if (empty($stats['enabled'])): ?>
  <div class="alert alert-warning">The referral program is currently paused. Existing commissions are unaffected.</div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3 max-w-6xl">
  <div class="lg:col-span-2 stack" style="gap:1.5rem">

    <div class="card">
      <h2 class="card-title">Refer &amp; earn</h2>
      <p class="muted">Share your link. You earn <strong><?=htmlspecialchars($percent)?>%</strong> of every
        qualifying order placed by the people you refer<?=$stats['scope'] === 'FIRST_ORDER' ? ' (first order only)' : ', for life'?>.</p>

      <label class="field mt-4">
        <span class="label">Your referral link</span>
        <div class="row" style="gap:.5rem">
          <input class="input" id="ws-ref" value="<?=htmlspecialchars($link)?>" readonly onclick="this.select()">
          <button class="btn btn-secondary" type="button"
                  onclick="navigator.clipboard?.writeText(document.getElementById('ws-ref').value);this.textContent='Copied'">Copy</button>
        </div>
        <span class="hint">Referral code <span class="mono"><?=htmlspecialchars($code)?></span></span>
      </label>

      <div class="grid grid-3 mt-6">
        <div class="card">
          <div class="muted text-sm">Referred users</div>
          <div class="text-2xl font-bold"><?=number_format((int)$stats['referred'])?></div>
        </div>
        <div class="card">
          <div class="muted text-sm">Pending</div>
          <div class="text-2xl font-bold"><?=windels_money($stats['pending'], $currency)?></div>
          <div class="hint">Clears after <?=(int)$stats['hold_hours']?>h</div>
        </div>
        <div class="card">
          <div class="muted text-sm">Paid to wallet</div>
          <div class="text-2xl font-bold"><?=windels_money($stats['paid'], $currency)?></div>
        </div>
      </div>
      <p class="hint mt-3">Lifetime earned: <strong><?=windels_money($stats['earned'], $currency)?></strong></p>
    </div>

    <div class="card">
      <div class="row justify-between" style="align-items:flex-start">
        <h3 class="card-title mb-0">Recent commissions</h3>
        <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/referrals/commissions')?>">View all →</a>
      </div>
      <?php if (empty($stats['commissions'])): ?>
        <p class="muted mt-3">No commissions yet. They appear here once a referred customer's order completes.</p>
      <?php else: ?>
      <div class="overflow-x-auto mt-3">
        <table class="table">
          <thead><tr><th>Date</th><th>Referred</th><th>Order</th><th>Status</th><th class="text-right">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($stats['commissions'] as $c): ?>
            <tr>
              <td class="text-xs muted whitespace-nowrap"><?=date('M j, Y', strtotime($c->created_at))?></td>
              <td><?=htmlspecialchars($c->referred_username ?? '—')?></td>
              <td class="mono text-xs"><?=$c->order_public_id ? htmlspecialchars(substr($c->order_public_id, 0, 10)).'…' : '—'?></td>
              <td><span class="badge <?=$c->status === 'PAID' ? 'badge-success' : ($c->status === 'PENDING' ? 'badge-warning' : 'badge-default')?>"><?=htmlspecialchars($c->status)?></span></td>
              <td class="text-right mono font-semibold"><?=windels_money($c->amount, $c->currency)?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 class="card-title">Your referrals</h3>
      <?php if (empty($stats['referrals'])): ?>
        <p class="muted mt-3">Nobody has signed up with your link yet.</p>
      <?php else: ?>
      <div class="overflow-x-auto mt-3">
        <table class="table">
          <thead><tr><th>User</th><th>Joined</th><th class="text-right">Pending</th><th class="text-right">Earned</th></tr></thead>
          <tbody>
          <?php foreach ($stats['referrals'] as $r): ?>
            <tr>
              <td><?=htmlspecialchars($r->username)?></td>
              <td class="text-xs muted"><?=date('M j, Y', strtotime($r->joined_at ?? $r->created_at))?></td>
              <td class="text-right mono muted"><?=windels_money($r->pending ?? '0', $currency)?></td>
              <td class="text-right mono font-semibold"><?=windels_money($r->earned ?? '0', $currency)?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <aside class="stack" style="gap:1.5rem">
    <div class="card">
      <h3 class="card-title">How it works</h3>
      <ol class="stack" style="gap:.75rem;padding-left:1.25rem">
        <li>Share your link with friends and clients.</li>
        <li>They register through it — attribution is permanent.</li>
        <li>Every qualifying order they complete earns you <?=htmlspecialchars($percent)?>%.</li>
        <li>After a <?=(int)$stats['hold_hours']?>-hour hold the commission is credited straight to your wallet.</li>
      </ol>
    </div>
    <div class="card">
      <h3 class="card-title">Good to know</h3>
      <ul class="stack muted text-sm" style="gap:.5rem;padding-left:1.1rem">
        <li>Commissions are calculated on the amount actually paid, after any partial refund.</li>
        <li>Canceled or refunded orders reverse an unpaid commission.</li>
        <li>Self-referrals and circular referrals are not counted.</li>
        <li>Credited commissions appear in your wallet as <span class="mono">REFERRAL_BONUS</span>.</li>
      </ul>
    </div>
  </aside>
</div>
