<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
?>
<div class="row justify-between mb-4">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Referrals and campaigns</h2>
    <p class="muted text-sm">Attribution, fraud flags and advertising performance.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/payouts')?>">Payouts</a>
</div>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">New advertising campaign</h3>
  <p class="muted text-xs mb-3">
    A campaign code is a referral code with no owner: nobody earns commission, but every click,
    sign-up and qualification is attributed to it so you can tell which advert actually works.
  </p>
  <form method="post" action="<?=site_url('admin/campaigns')?>" class="row"
        style="gap:.5rem;flex-wrap:wrap;align-items:flex-end">
    <?=$csrf()?>
    <label class="field"><span class="label">Name</span>
      <input class="input" type="text" name="name" required maxlength="160" placeholder="Facebook Q1"></label>
    <label class="field"><span class="label">Code</span>
      <input class="input mono" type="text" name="code" required maxlength="32"
             pattern="[A-Za-z0-9_-]{3,32}" placeholder="FACEBOOK2026"></label>
    <label class="field"><span class="label">Source</span>
      <input class="input" type="text" name="source" maxlength="64" placeholder="facebook"></label>
    <label class="field"><span class="label">Reward</span>
      <input class="input mono" type="number" name="reward_amount" step="0.01" min="0" value="0"></label>
    <label class="field"><span class="label">Qualify on</span>
      <select class="select" name="qualify_event">
        <option value="FIRST_ORDER">First order</option>
        <option value="FIRST_DEPOSIT">First deposit</option>
        <option value="EMAIL_VERIFIED">Email verified</option>
        <option value="REGISTERED">Registration</option>
      </select></label>
    <label class="field"><span class="label">Hold (hrs)</span>
      <input class="input mono" type="number" name="hold_hours" min="0" value="72" style="max-width:6rem"></label>
    <label class="field"><span class="label">Budget</span>
      <input class="input mono" type="number" name="budget" step="0.01" min="0" placeholder="none"></label>
    <label class="field"><span class="label">Ad cost</span>
      <input class="input mono" type="number" name="cost" step="0.01" min="0" placeholder="optional"></label>
    <label class="field"><span class="label">Countries</span>
      <input class="input mono" type="text" name="geo_allow" maxlength="255"
             placeholder="NG,GH,KE" style="max-width:10rem"></label>
    <button class="btn btn-primary" type="submit">Create</button>
  </form>
  <p class="muted text-xs mt-2">
    <strong>Countries</strong> is an optional comma-separated ISO-2 allow-list; blank means anywhere.
    <?php if (!empty($geo_detected)): ?>
      Your own request resolves to <strong><?=htmlspecialchars($geo_detected)?></strong>, so restrictions
      will be applied.
    <?php else: ?>
      <span style="color:var(--color-warning,#b45309)">
        No country header is reaching the panel, so a restriction here would match nobody and is
        therefore ignored rather than blocking everyone. Put a geo-aware proxy (for example
        Cloudflare, which sends <code>CF-IPCountry</code>) in front of the site to make it effective.
      </span>
    <?php endif; ?>
  </p>
</div>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-2">Campaign performance</h3>
  <?php if (!$campaigns): ?>
    <p class="muted text-sm">No campaigns yet.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Campaign</th><th>Code</th><th>Source</th><th>Clicks</th><th>Sign-ups</th>
               <th>Qualified</th><th>Conversion</th><th>Spent</th><th>Cost/signup</th><th>Geo</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($campaigns as $c): ?>
      <tr>
        <td><?=htmlspecialchars($c->name)?></td>
        <td class="mono text-xs"><?=htmlspecialchars($c->code)?></td>
        <td class="text-xs"><?=htmlspecialchars((string)$c->source)?></td>
        <td><?=number_format((int)$c->total_visits)?></td>
        <td><?=number_format((int)$c->total_signups)?></td>
        <td><?=number_format((int)$c->total_qualified)?></td>
        <td><?=htmlspecialchars((string)$c->conversion_rate)?>%</td>
        <td class="mono text-xs"><?=marvy_money($c->spent)?></td>
        <td class="mono text-xs"><?=empty($c->geo_allow) ? 'any' : htmlspecialchars($c->geo_allow)?></td>
        <td class="mono text-xs"><?=$c->cost_per_signup === null ? '—' : marvy_money($c->cost_per_signup)?></td>
        <td>
          <span class="badge badge-default"><?=htmlspecialchars($c->status)?></span>
          <form method="post" style="display:inline"
                action="<?=site_url('admin/campaigns/'.$c->public_id.'/status')?>">
            <?=$csrf()?>
            <input type="hidden" name="status" value="<?=$c->status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE'?>">
            <button class="btn btn-ghost btn-sm" type="submit">
              <?=$c->status === 'ACTIVE' ? 'Pause' : 'Resume'?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="font-size:1rem;font-weight:600" class="mb-2">Referred sign-ups</h3>
  <?php if (!$signups): ?>
    <p class="muted text-sm">No referrals yet.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Reference</th><th>Code</th><th>Referrer</th><th>Referred</th>
               <th>Status</th><th>Flags</th><th>Created</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($signups as $s): ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars($s->public_id)?></td>
        <td class="mono text-xs"><?=htmlspecialchars($s->referral_code)?></td>
        <td class="text-xs"><?=$s->referrer_user_id ? '#'.(int)$s->referrer_user_id : '—'?></td>
        <td class="text-xs">#<?=(int)$s->referred_user_id?></td>
        <td><span class="badge badge-default"><?=htmlspecialchars($s->status)?></span></td>
        <td class="text-xs" style="color:var(--color-danger,#dc2626)"><?=htmlspecialchars((string)$s->fraud_flags)?></td>
        <td class="text-xs"><?=htmlspecialchars(date('j M H:i', strtotime($s->created_at)))?></td>
        <td class="text-right">
          <?php if ($s->status === 'FRAUD_REVIEW'): ?>
          <form method="post" style="display:inline" action="<?=site_url('admin/referrals/'.$s->public_id.'/review')?>">
            <?=$csrf()?><input type="hidden" name="decision" value="APPROVE">
            <button class="btn btn-primary btn-sm" type="submit">Approve</button>
          </form>
          <form method="post" style="display:inline" action="<?=site_url('admin/referrals/'.$s->public_id.'/review')?>">
            <?=$csrf()?><input type="hidden" name="decision" value="REJECT">
            <button class="btn btn-secondary btn-sm" type="submit">Reject</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted text-xs mt-2"><?=number_format($total)?> referral(s).</p>
  <?php endif; ?>
</div>
