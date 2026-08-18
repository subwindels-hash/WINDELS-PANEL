<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between" style="margin-bottom:1rem;align-items:flex-start">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Providers</h2>
    <p class="muted text-sm">Upstream SMM panels. API keys are encrypted at rest and never displayed after creation.</p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('ws-new-provider').showModal()">+ Add provider</button>
</div>

<div class="card">
<?php if (empty($providers)): ?>
  <p class="muted">No providers configured yet.</p>
<?php else: ?>
<div class="overflow-x-auto">
  <table class="table">
    <thead><tr>
      <th>Name</th><th>Type</th><th>Status</th><th>Health</th><th>Balance</th>
      <th>Services</th><th>Last sync</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($providers as $p): ?>
      <tr>
        <td>
          <a class="font-medium text-slate-900" href="<?=site_url('admin/providers/detail/'.$p->public_id)?>"><?=htmlspecialchars($p->name)?></a>
        </td>
        <td class="mono text-xs"><?=htmlspecialchars($p->api_type)?></td>
        <td><span class="badge <?=$p->status==='ACTIVE'?'badge-success':'badge-default'?>"><?=htmlspecialchars($p->status)?></span></td>
        <td>
          <?php
            $h = strtoupper((string)$p->health_status);
            $hcls = $h==='ONLINE' ? 'badge-success' : ($h==='OFFLINE' ? 'badge-danger' : 'badge-default');
          ?>
          <span class="badge <?=$hcls?> badge-dot"><?=htmlspecialchars($h ?: 'UNKNOWN')?></span>
        </td>
        <td class="mono"><?=$p->balance!==null ? htmlspecialchars($p->balance).' '.htmlspecialchars($p->currency) : '—'?></td>
        <td><?=number_format($counts[(int)$p->id] ?? 0)?></td>
        <td class="text-xs muted whitespace-nowrap"><?=$p->last_successful_sync_at ? date('M j, H:i', strtotime($p->last_successful_sync_at)) : 'never'?></td>
        <td><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/providers/detail/'.$p->public_id)?>">Manage →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</div>

<!-- Create provider dialog -->
<dialog id="ws-new-provider" class="ws-dialog" onclick="if(event.target===this)this.close()">
  <form method="post" action="<?=site_url('admin/providers/create')?>" class="stack">
    <h3 class="card-title mb-0">Add provider</h3>
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
    <label class="field"><span class="label">Name</span>
      <input class="input" name="name" required maxlength="128" placeholder="Acme SMM">
    </label>
    <label class="field"><span class="label">API URL</span>
      <input class="input" name="api_url" type="url" required placeholder="https://provider.example/api/v2">
    </label>
    <label class="field"><span class="label">API key</span>
      <input class="input" name="api_key" required autocomplete="off" placeholder="Stored encrypted">
    </label>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Type</span>
        <select class="select" name="api_type" id="ws-api-type">
          <?php foreach (($api_types ?? array('STANDARD_SMM'=>'SMM','MOCK'=>'SMM')) as $type => $family): ?>
            <option value="<?=htmlspecialchars($type)?>"><?=htmlspecialchars($type)?><?=$type==='MOCK'?' (offline test)':' · '.htmlspecialchars($family)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field" style="flex:1"><span class="label">Status</span>
        <select class="select" name="status"><option>ACTIVE</option><option>INACTIVE</option></select>
      </label>
    </div>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Timeout (ms)</span>
        <input class="input" type="number" name="timeout_ms" value="15000" min="1000" max="60000">
      </label>
      <label class="field" style="flex:1"><span class="label">Sync interval (min)</span>
        <input class="input" type="number" name="sync_interval_minutes" value="60" min="1">
      </label>
    </div>
    <details class="text-sm muted">
      <summary>VTpass keys (VTPASS only)</summary>
      <p class="text-xs muted" style="margin-top:.5rem">
        VTpass authenticates GET and POST with different keys, so both are required.
        Sandbox API URL: <span class="mono">https://sandbox.vtpass.com/api</span> ·
        live: <span class="mono">https://vtpass.com/api</span>. All three values are
        encrypted together at rest.
      </p>
      <div class="row" style="gap:.75rem;margin-top:.5rem">
        <label class="field" style="flex:1"><span class="label">Public key (reads)</span>
          <input class="input" name="public_key" autocomplete="off" placeholder="PK_...">
        </label>
        <label class="field" style="flex:1"><span class="label">Secret key (purchases)</span>
          <input class="input" name="secret_key" autocomplete="off" placeholder="SK_...">
        </label>
      </div>
    </details>
    <details class="text-sm muted">
      <summary>Dojah AppId (DOJAH only)</summary>
      <p class="text-xs muted" style="margin-top:.5rem">
        Dojah sends the secret key in <span class="mono">Authorization</span> with no
        <span class="mono">Bearer</span> prefix, plus an <span class="mono">AppId</span> header —
        both are required or every call returns 401. Sandbox API URL:
        <span class="mono">https://sandbox.dojah.io</span> · live:
        <span class="mono">https://api.dojah.io</span>. Sandbox keys do not work
        against the live URL.
      </p>
      <label class="field" style="margin-top:.5rem"><span class="label">AppId</span>
        <input class="input" name="app_id" autocomplete="off" placeholder="From the Dojah dashboard">
      </label>
    </details>
    <details class="text-sm muted">
      <summary>Advanced</summary>
      <div class="row" style="gap:.75rem;margin-top:.5rem">
        <label class="field" style="flex:1"><span class="label">Rate multiplier</span>
          <input class="input" type="number" step="0.01" name="rate_multiplier" value="1.00" min="0">
        </label>
        <label class="field" style="flex:1"><span class="label">Markup</span>
          <input class="input" type="number" step="0.01" name="markup" value="0.00" min="0">
        </label>
      </div>
    </details>
    <div class="row" style="justify-content:flex-end">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('ws-new-provider').close()">Cancel</button>
      <button type="submit" class="btn btn-primary">Create provider</button>
    </div>
  </form>
</dialog>

<style>
.ws-dialog{border:0;border-radius:1rem;padding:0;width:min(560px,92vw);box-shadow:0 30px 80px -20px rgba(0,0,0,.4)}
.ws-dialog::backdrop{background:rgba(15,23,42,.55)}
.ws-dialog form{padding:1.5rem}
</style>
