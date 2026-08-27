<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-3 max-w-5xl">
  <div class="lg:col-span-2 space-y-6">
    <?php if (!empty($new_key)): ?>
      <div class="alert alert-success">
        <strong>Copy your API key now.</strong> It will not be shown again.
        <div class="row mt-2" style="gap:.5rem">
          <code id="ws-key" class="flex-1 block rounded p-2" style="background:#fff;border:1px solid var(--slate-200);word-break:break-all"><?=htmlspecialchars($new_key['raw'])?></code>
          <button class="btn btn-secondary btn-sm" type="button" data-copy="#ws-key" data-copied-label="Copied">Copy</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="card-title">Your API keys</h2>
      <?php if (empty($keys)): ?>
        <?php $this->load->view('partials/empty_state', array(
            'icon'  => 'key',
            'title' => 'No API keys yet',
            'body'  => 'Create a key to place and manage orders programmatically against the /api/v1 endpoints.',
        )); ?>
      <?php else: ?>
      <div class="overflow-x-auto mt-3">
        <table class="table">
          <thead><tr><th>Name</th><th>Key prefix</th><th>Rate limit</th><th>Created</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($keys as $k): ?>
            <tr>
              <td><?=htmlspecialchars((string)$k->name)?></td>
              <td class="mono text-xs"><?=htmlspecialchars($k->prefix)?>…</td>
              <td><?=(int)$k->rate_limit_per_minute?> /min</td>
              <td class="text-xs muted"><?=date('M j, Y', strtotime($k->created_at))?></td>
              <td><?php if ($k->revoked_at): ?><span class="badge badge-default">revoked</span><?php elseif (!empty($k->expires_at) && strtotime($k->expires_at) <= time()): ?><span class="badge badge-warning">expired</span><?php else: ?><span class="badge badge-success badge-dot">active</span><?php endif; ?></td>
              <td>
                <?php if (!$k->revoked_at): ?>
                <form method="post" action="<?=site_url('dashboard/api/revoke/'.$k->public_id)?>" data-confirm="Revoke this key? Applications using it will stop working immediately." >
                  <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
                  <button class="btn btn-ghost btn-sm" type="submit">Revoke</button>
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

    <div class="card">
      <h2 class="card-title">Create a new key</h2>
      <?=form_open('dashboard/api', array('class'=>'mt-4 stack'))?>
        <label class="field">
          <span class="label">Name</span>
          <input class="input" name="name" required maxlength="64" placeholder="e.g. Production server">
        </label>
        <label class="field">
          <span class="label">IP whitelist (optional, comma-separated)</span>
          <input class="input" name="ip_whitelist" placeholder="203.0.113.10, 203.0.113.11">
        </label>
        <div><button class="btn btn-primary" type="submit">Generate key</button></div>
      <?=form_close()?>
    </div>
  </div>

  <aside class="card">
    <h3 class="card-title">Using the API</h3>
    <p class="text-sm muted">Send your key in the <code>X-Api-Key</code> header:</p>
<pre class="rounded p-3 text-xs" style="background:var(--slate-900);color:#e2e8f0;overflow:auto">curl https://panel/api/v1/balance \
  -H "X-Api-Key: wind_..."</pre>
    <a class="btn btn-secondary btn-sm btn-block mt-3" href="<?=site_url('api/docs')?>">Read API docs →</a>
  </aside>
</div>
