<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$search = $search ?? '';
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Providers</h2>
    <p class="muted text-sm">Upstream SMM panels. API keys are encrypted at rest and never displayed after creation.</p>
  </div>
  <div class="row" style="gap:.5rem;flex-wrap:wrap;align-items:center">
    <form method="get" action="<?=site_url('admin/providers')?>" class="row" style="gap:.35rem" role="search"
          aria-label="Search providers">
      <?php if (!empty($status)): ?><input type="hidden" name="status" value="<?=htmlspecialchars($status)?>"><?php endif; ?>
      <div class="ws-searchwrap">
        <?php $this->load->view('partials/icon', array('name'=>'search','class'=>'w-4 h-4')); ?>
        <label class="sr-only" for="ws-provider-search">Search providers</label>
        <input class="input" id="ws-provider-search" name="q" value="<?=htmlspecialchars((string)$search)?>"
               placeholder="Search by name, type or ID" aria-label="Search providers" style="min-width:15rem">
      </div>
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
      <?php if ($search !== '' || !empty($status)): ?>
        <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/providers')?>">Clear</a>
      <?php endif; ?>
    </form>
    <?php if ($has('providers.manage')): ?>
      <button class="btn btn-primary" type="button"
              data-dialog-open="ws-new-provider" >+ Add provider</button>
    <?php endif; ?>
  </div>
</div>

<div class="card">
<?php if (empty($providers)): ?>
  <?php if ($search !== '' || !empty($status)): ?>
    <div class="text-center" style="padding:2.5rem 1rem">
      <p class="muted mb-2">No providers match "<?=htmlspecialchars((string)$search)?>"<?=!empty($status) ? ' with status '.htmlspecialchars($status) : ''?>.</p>
      <a class="btn btn-secondary btn-sm" href="<?=site_url('admin/providers')?>">Clear search</a>
    </div>
  <?php else: ?>
  <p class="muted">No providers configured yet.</p>
  <?php endif; ?>
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
          <a class="font-medium text-slate-900" href="<?=site_url('admin/providers/'.$p->public_id)?>"><?=htmlspecialchars($p->name)?></a>
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
        <td><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/providers/'.$p->public_id)?>">Manage →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if (($total_pages ?? 1) > 1):
  $qs = function (array $over = array()) use ($status, $search, $page) {
    $base = array('status' => $status, 'q' => $search, 'page' => $page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== ''; });
    return $merged ? '?'.http_build_query($merged) : '';
  };
?>
<nav class="row justify-between mt-4" aria-label="Pagination">
  <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
     href="<?=site_url('admin/providers'.$qs(array('page'=>max(1,$page-1))))?>">← Previous</a>
  <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?> · <?=number_format($total)?> providers</span>
  <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
     href="<?=site_url('admin/providers'.$qs(array('page'=>min($total_pages,$page+1))))?>">Next →</a>
</nav>
<?php endif; ?>
<?php endif; ?>
</div>

<!-- Create provider dialog -->
<?php if ($has('providers.manage')): ?>
<dialog id="ws-new-provider" class="ws-dialog" data-dialog-light-dismiss >
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
      <summary>5sim API key (FIVESIM only)</summary>
      <p class="text-xs muted" style="margin-top:.5rem">
        5sim speaks the <strong>current</strong> protocol: choose type
        <span class="mono">FIVESIM</span>, set the API URL to
        <span class="mono">https://5sim.net/v1</span> and paste the dashboard's
        <em>“API key for 5SIM protocol”</em> (a JWT) into the API key field above.
        Every call goes out as <span class="mono">GET https://5sim.net/v1/…</span>
        with <span class="mono">Authorization: Bearer …</span>.
      </p>
      <p class="text-xs muted">
        <span class="mono">api1.5sim.net</span> and
        <span class="mono">handler_api.php</span> are the <strong>deprecated API</strong> —
        they are refused. The “Deprecated API” dashboard key is a different
        credential and must never be used here.
      </p>
      <p class="text-xs muted">
        Production hosts can instead set
        <span class="mono">FIVESIM_API_KEY</span> in the environment (or
        <span class="mono">.env</span>); it wins over the stored key and is
        never rendered to the browser.
      </p>
    </details>
    <details class="text-sm muted">
      <summary>Reloadly client secret (RELOADLY only)</summary>
      <p class="text-xs muted" style="margin-top:.5rem">
        Reloadly is OAuth2, not an API key: put the <span class="mono">client id</span> in the
        API key field above and the secret here. They are exchanged for a bearer token that
        lasts about 60 days and is cached on the provider. Sandbox API URL:
        <span class="mono">https://giftcards-sandbox.reloadly.com</span> · live:
        <span class="mono">https://giftcards.reloadly.com</span> — the token audience follows
        the URL, so sandbox credentials cannot reach the live wallet.
      </p>
      <label class="field" style="margin-top:.5rem"><span class="label">Client secret</span>
        <input class="input" name="client_secret" autocomplete="off" placeholder="From the Reloadly dashboard">
      </label>
    </details>
    <details class="text-sm muted">
      <summary>Advanced</summary>
      <div class="row" style="gap:.75rem;margin-top:.5rem">
        <label class="field" style="flex:1"><span class="label">Percentage increase</span>
          <select class="select" name="markup_percent">
            <?php for ($i = 0; $i <= ProviderSyncService::MAX_MARKUP_PERCENT; $i++): ?>
              <option value="<?=$i?>"><?=$i?>%<?=$i === 0 ? ' — sell at cost' : ''?></option>
            <?php endfor; ?>
          </select>
          <span class="hint">Customers pay the vendor's rate plus this. Editable later on the provider page.</span>
        </label>
        <label class="field" style="flex:1"><span class="label">Flat add-on</span>
          <input class="input" type="number" step="0.01" name="markup" value="0.00" min="0">
        </label>
      </div>
    </details>
    <div class="row" style="justify-content:flex-end">
      <button type="button" class="btn btn-ghost" data-dialog-close="ws-new-provider" >Cancel</button>
      <button type="submit" class="btn btn-primary">Create provider</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<style>
.ws-dialog{border:0;border-radius:1rem;padding:0;width:min(560px,92vw);box-shadow:0 30px 80px -20px rgba(0,0,0,.4)}
.ws-dialog::backdrop{background:rgba(15,23,42,.55)}
.ws-dialog form{padding:1.5rem}
</style>
