<?php defined('BASEPATH') OR exit('No direct script access allowed');
$expired = empty($key->revoked_at) && !empty($key->expires_at) && strtotime($key->expires_at) <= time();
$status = !empty($key->revoked_at) ? 'REVOKED' : ($expired ? 'EXPIRED' : 'ACTIVE');
$badge = array('ACTIVE'=>'badge-success','EXPIRED'=>'badge-warning','REVOKED'=>'badge-danger');
$ips = empty($key->ip_whitelist) ? array() : json_decode($key->ip_whitelist, true);
if (!is_array($ips)) $ips = array();
$selected_scopes = $key->scopes === null ? null : json_decode($key->scopes, true);
if ($key->scopes !== null && !is_array($selected_scopes)) $selected_scopes = array();
$expiry_value = $key->expires_at ? gmdate('Y-m-d\TH:i', strtotime($key->expires_at)) : '';
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/api-keys')?>">← Reseller API keys</a>
    <h2 class="mb-1" style="font-size:1.35rem;font-weight:600"><?=htmlspecialchars((string)$key->name)?></h2>
    <span class="badge <?=$badge[$status]?>"><?=$status?></span>
  </div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/api-keys?user='.rawurlencode($key->user_public_id))?>">All keys for this customer</a>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="card-title">Key identity</h3>
    <div class="alert alert-info mb-3">Only the non-secret prefix is available. The full credential was shown once to the customer and cannot be recovered by administrators.</div>
    <table class="table"><tbody>
      <tr><th>Prefix</th><td class="mono"><?=htmlspecialchars($key->prefix)?>••••••••</td></tr>
      <tr><th>Customer</th><td><a href="<?=site_url('admin/users/'.$key->user_public_id)?>"><?=htmlspecialchars((string)$key->username)?></a><div class="text-xs muted"><?=htmlspecialchars((string)$key->email)?> · <?=htmlspecialchars((string)$key->user_status)?></div></td></tr>
      <tr><th>Created</th><td class="text-xs"><?=htmlspecialchars((string)$key->created_at)?></td></tr>
      <tr><th>Last used</th><td class="text-xs"><?=htmlspecialchars((string)($key->last_used_at ?: 'Never'))?></td></tr>
      <tr><th>Last IP</th><td class="mono text-xs"><?=htmlspecialchars((string)($key->last_used_ip ?: '—'))?></td></tr>
      <tr><th>Expires</th><td class="text-xs"><?=htmlspecialchars((string)($key->expires_at ?: 'Never'))?> UTC</td></tr>
      <?php if ($key->revoked_at): ?><tr><th>Revoked</th><td class="text-xs"><?=htmlspecialchars((string)$key->revoked_at)?> UTC</td></tr><?php endif; ?>
    </tbody></table>
  </div>

  <div class="card">
    <h3 class="card-title">Policy</h3>
    <?php if ($key->revoked_at): ?>
      <div class="alert alert-warning">Revocation is permanent. This policy is retained as evidence and cannot be edited.</div>
    <?php else: ?>
    <?=form_open('admin/api-keys/'.$key->public_id.'/policy')?>
      <label class="field"><span class="label">Label</span><input class="input" name="name" maxlength="64" required value="<?=htmlspecialchars((string)$key->name)?>"></label>
      <div class="grid grid-2" style="gap:.75rem">
        <label class="field"><span class="label">Requests per minute</span><input class="input" type="number" name="rate_limit_per_minute" min="1" max="10000" required value="<?=(int)$key->rate_limit_per_minute?>"></label>
        <label class="field"><span class="label">Expiry (UTC)</span><input class="input" type="datetime-local" name="expires_at" value="<?=htmlspecialchars($expiry_value)?>"><span class="hint">Blank means no expiry.</span></label>
      </div>
      <label class="field"><span class="label">Exact IP allowlist</span><textarea class="input" name="ip_whitelist" rows="3" placeholder="203.0.113.10&#10;2001:db8::10"><?=htmlspecialchars(implode("\n", $ips))?></textarea><span class="hint">One exact IPv4 or IPv6 address per line. Blank permits all source IPs; CIDR ranges are not accepted.</span></label>

      <fieldset class="field" style="border:0;padding:0"><legend class="label">Endpoint access</legend>
        <label class="row mb-2" style="gap:.5rem;align-items:flex-start"><input type="radio" name="access_mode" value="full" <?=$selected_scopes===null?'checked':''?>><span><strong>Full access</strong><span class="hint" style="display:block">Legacy-compatible access to all present and future reseller endpoints.</span></span></label>
        <label class="row mb-2" style="gap:.5rem;align-items:flex-start"><input type="radio" name="access_mode" value="scoped" <?=$selected_scopes!==null?'checked':''?>><span><strong>Explicit scopes</strong><span class="hint" style="display:block">Only checked capabilities are allowed. No selections blocks every endpoint.</span></span></label>
        <div class="card" style="padding:.75rem">
        <?php foreach ($scope_catalogue as $scope=>$description): ?>
          <label class="row mb-2" style="gap:.5rem;align-items:flex-start"><input type="checkbox" name="scopes[]" value="<?=htmlspecialchars($scope)?>" <?=$selected_scopes!==null&&in_array($scope,$selected_scopes,true)?'checked':''?>><span><span class="mono text-xs"><?=htmlspecialchars($scope)?></span><span class="hint" style="display:block"><?=htmlspecialchars($description)?></span></span></label>
        <?php endforeach; ?>
        </div>
      </fieldset>
      <button class="btn btn-primary btn-sm" type="submit">Save key policy</button>
    <?=form_close()?>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-4 mt-4" style="gap:1rem">
  <div class="card"><div class="muted text-sm">All requests</div><div class="text-2xl font-bold"><?=number_format($usage_summary['total'])?></div></div>
  <div class="card"><div class="muted text-sm">Successful</div><div class="text-2xl font-bold"><?=number_format($usage_summary['successful'])?></div></div>
  <div class="card"><div class="muted text-sm">Failed</div><div class="text-2xl font-bold"><?=number_format($usage_summary['failed'])?></div></div>
  <div class="card"><div class="muted text-sm">Requests · 24 hours</div><div class="text-2xl font-bold"><?=number_format($usage_summary['requests_24h'])?></div></div>
</div>

<div class="grid grid-2 mt-4" style="gap:1rem;align-items:start">
  <div class="card"><h3 class="card-title">Recent API calls</h3>
  <?php if (empty($usage)): ?><p class="muted">No request evidence has been recorded for this key yet.</p>
  <?php else: ?><div class="overflow-x-auto"><table class="table"><thead><tr><th>When</th><th>Request</th><th>IP</th><th>Status</th><th>Duration</th></tr></thead><tbody>
  <?php foreach ($usage as $call): ?><tr><td class="text-xs muted"><?=htmlspecialchars((string)$call->created_at)?></td><td><span class="badge badge-default"><?=htmlspecialchars((string)$call->method)?></span> <span class="mono text-xs"><?=htmlspecialchars((string)$call->endpoint)?></span></td><td class="mono text-xs"><?=htmlspecialchars((string)$call->ip)?></td><td><span class="badge <?=((int)$call->status<400)?'badge-success':'badge-danger'?>"><?=(int)$call->status?></span></td><td class="text-xs"><?=number_format((int)$call->duration_ms)?> ms</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <div class="card"><h3 class="card-title">Requests by endpoint</h3>
  <?php if (empty($endpoint_usage)): ?><p class="muted">No endpoint totals are available.</p>
  <?php else: ?><table class="table"><thead><tr><th>Endpoint</th><th>Method</th><th class="text-right">Requests</th></tr></thead><tbody><?php foreach ($endpoint_usage as $route): ?><tr><td class="mono text-xs"><?=htmlspecialchars((string)$route->endpoint)?></td><td><?=htmlspecialchars((string)$route->method)?></td><td class="text-right"><?=number_format((int)$route->requests)?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  </div>
</div>

<div class="card mt-4" style="border-color:var(--danger, #dc2626)"><h3 class="card-title">Permanent revocation</h3>
<?php if ($key->revoked_at): ?><p class="muted">This credential can no longer authenticate. The customer must create a new key if access is needed again.</p>
<?php else: ?><p class="text-sm muted">Use this for a compromised or retired credential. Revocation cannot be undone and the key cannot be resurrected by changing its policy.</p>
<?=form_open('admin/api-keys/'.$key->public_id.'/revoke', array('onsubmit'=>"return confirm('Permanently revoke this API key? This cannot be undone.');"))?><button class="btn btn-danger btn-sm" type="submit">Revoke API key permanently</button><?=form_close()?><?php endif; ?>
</div>
