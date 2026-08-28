<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
$can_manage = in_array('*', $permissions ?? array(), true)
    || in_array('payments.manage', $permissions ?? array(), true);
?>
<div class="card">
  <h2 class="card-title">Deposit methods</h2>
  <p class="muted">
    Switching a method on shows it on Add funds. A gateway with no API credentials stays hidden even
    when it is on — the panel will not offer a payment it cannot complete. Credentials live in
    <a href="<?=site_url('admin/settings')?>#gateways">Settings → Card and wallet gateways</a>, and each
    provider's callback URL is <code class="mono"><?=htmlspecialchars(site_url('webhook/'))?>&lt;code&gt;</code>.
  </p>

  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead>
        <tr>
          <th>Method</th><th>Status</th><th>Fee</th><th>Bonus</th>
          <th>Min / max</th><th>Order</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($methods as $m): $s = $state[$m->code] ?? array('implemented'=>false,'configured'=>true); ?>
          <tr>
            <td colspan="7" style="padding:0">
              <form method="post" action="<?=site_url('admin/payments/methods/'.rawurlencode($m->code).'/save')?>">
                <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
                <div class="row" style="gap:1rem;align-items:flex-end;flex-wrap:wrap;padding:.85rem .25rem">

                  <div style="flex:2;min-width:14rem">
                    <label class="field mb-0">
                      <span class="label"><?=htmlspecialchars($m->name)?> <span class="mono text-xs muted">(<?=htmlspecialchars($m->code)?>)</span></span>
                      <input class="input" name="name" value="<?=htmlspecialchars($m->name)?>" maxlength="64" <?=$can_manage ? '' : 'disabled'?>>
                    </label>
                    <div class="row mt-2" style="gap:.4rem;flex-wrap:wrap">
                      <?php if ((int)$m->is_active === 1): ?>
                        <span class="badge badge-success badge-dot">On</span>
                      <?php else: ?>
                        <span class="badge badge-default">Off</span>
                      <?php endif; ?>
                      <?php if (empty($s['needs_credentials'])): ?>
                        <span class="badge badge-default" title="Reconciled by staff — no API credentials needed">Manual review</span>
                      <?php elseif ($s['configured']): ?>
                        <span class="badge badge-info">Credentials set</span>
                      <?php else: ?>
                        <span class="badge badge-warning" title="Add the API keys in Settings">Not configured</span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <label class="row mb-0" style="gap:.4rem;align-items:center;min-width:6rem">
                    <input type="checkbox" name="is_active" value="1" <?=(int)$m->is_active === 1 ? 'checked' : ''?> <?=$can_manage ? '' : 'disabled'?>>
                    <span class="text-sm">Enabled</span>
                  </label>

                  <label class="field mb-0" style="width:7rem">
                    <span class="label">Fee %</span>
                    <input class="input" type="number" step="0.0001" min="0" max="100" name="fee_percent"
                           value="<?=htmlspecialchars(number_format((float)$m->fee_percent, 4, '.', ''))?>" <?=$can_manage ? '' : 'disabled'?>>
                  </label>
                  <label class="field mb-0" style="width:8rem">
                    <span class="label">Fee flat</span>
                    <input class="input" type="number" step="0.01" min="0" name="fee_fixed"
                           value="<?=htmlspecialchars(number_format((float)$m->fee_fixed, 2, '.', ''))?>" <?=$can_manage ? '' : 'disabled'?>>
                  </label>
                  <label class="field mb-0" style="width:7rem">
                    <span class="label">Bonus %</span>
                    <input class="input" type="number" step="0.0001" min="0" max="100" name="bonus_percent"
                           value="<?=htmlspecialchars(number_format((float)$m->bonus_percent, 4, '.', ''))?>" <?=$can_manage ? '' : 'disabled'?>>
                  </label>
                  <label class="field mb-0" style="width:8rem">
                    <span class="label">Min</span>
                    <input class="input" type="number" step="0.01" min="0" name="min_amount"
                           value="<?=$m->min_amount === null ? '' : htmlspecialchars(number_format((float)$m->min_amount, 2, '.', ''))?>" <?=$can_manage ? '' : 'disabled'?>>
                  </label>
                  <label class="field mb-0" style="width:8rem">
                    <span class="label">Max</span>
                    <input class="input" type="number" step="0.01" min="0" name="max_amount"
                           value="<?=$m->max_amount === null ? '' : htmlspecialchars(number_format((float)$m->max_amount, 2, '.', ''))?>" <?=$can_manage ? '' : 'disabled'?>>
                  </label>
                  <label class="field mb-0" style="width:5.5rem">
                    <span class="label">Order</span>
                    <input class="input" type="number" step="1" name="sorting" value="<?=(int)$m->sorting?>" <?=$can_manage ? '' : 'disabled'?>>
                  </label>

                  <?php if ($can_manage): ?>
                    <button class="btn btn-primary" type="submit">Save</button>
                  <?php endif; ?>
                </div>

                <?php if (strtolower($m->code) === 'manual'): ?>
                  <div style="padding:0 .25rem .85rem">
                    <label class="field mb-0">
                      <span class="label">Transfer instructions shown to the customer</span>
                      <textarea class="textarea" name="instructions" rows="2" <?=$can_manage ? '' : 'disabled'?>><?=htmlspecialchars((string)$m->instructions)?></textarea>
                      <span class="hint">Bank name, account number and account name. The deposit reference is added automatically.</span>
                    </label>
                  </div>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
