<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="max-w-6xl mx-auto space-y-6">
  <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
    <section class="card p-5 md:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">Place multiple orders</h2>
          <p class="mt-1 text-sm text-slate-500">One instruction per line. Valid rows continue even when another row fails.</p>
        </div>
        <span class="badge badge-info">Maximum 100 rows</span>
      </div>

      <?=form_open('dashboard/mass-order/create', array('method'=>'post', 'class'=>'space-y-4'))?>
        <input type="hidden" name="batch_token" value="<?=htmlspecialchars($batch_token, ENT_QUOTES, 'UTF-8')?>">
        <div>
          <label for="mass-orders" class="label">Order instructions</label>
          <textarea id="mass-orders" name="orders" rows="13" maxlength="65536" required
                    class="input font-mono text-sm leading-6"
                    placeholder="instagram-followers | https://instagram.com/example | 1000"><?=htmlspecialchars($mass_input, ENT_QUOTES, 'UTF-8')?></textarea>
          <div class="mt-2 flex flex-wrap justify-between gap-2 text-xs text-slate-500">
            <span>Format: <code class="font-mono text-slate-700">service | https://target.example | quantity</code> (pipes or tabs)</span>
            <span>64 KiB request limit</span>
          </div>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          Rows are charged independently. Provider submission failures are automatically refunded by the normal order engine.
        </div>
        <button type="submit" class="btn btn-primary">
          <?php $this->load->view('partials/icon', array('name'=>'shopping-bag','class'=>'w-4 h-4')); ?>
          Process mass order
        </button>
      <?=form_close()?>
    </section>

    <aside class="space-y-4">
      <div class="card p-5">
        <div class="text-sm text-slate-500">Available balance</div>
        <div class="mt-1 text-2xl font-semibold text-slate-900">
          <?=htmlspecialchars($wallet->currency ?? marvy_base_currency())?>
          <?=number_format((float)($wallet->balance ?? 0), 2)?>
        </div>
        <a href="<?=site_url('dashboard/add-funds')?>" class="mt-3 inline-flex text-sm font-medium text-brand-700 hover:underline">Add funds</a>
      </div>
      <div class="card p-5 text-sm text-slate-600">
        <h3 class="font-semibold text-slate-900">Service key</h3>
        <p class="mt-2">Use the service slug or public ID shown below. Numeric panel service IDs are accepted too.</p>
        <p class="mt-2">Links must begin with <strong>http://</strong> or <strong>https://</strong>.</p>
      </div>
    </aside>
  </div>

  <?php if (is_array($mass_result)): ?>
    <section class="card overflow-hidden">
      <div class="p-5 border-b flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="font-semibold text-slate-900">Batch result</h2>
          <p class="text-sm text-slate-500 mt-1">
            <?=number_format((int)($mass_result['successful_count'] ?? 0))?> successful,
            <?=number_format((int)($mass_result['failed_count'] ?? 0))?> failed.
            <?php if (!empty($mass_result['replayed'])): ?>This is the saved result of an earlier identical submission.<?php endif; ?>
          </p>
        </div>
        <?php if (empty($mass_result['failed'])): ?>
          <span class="badge badge-success">All rows processed</span>
        <?php elseif (empty($mass_result['successful'])): ?>
          <span class="badge badge-danger">No rows succeeded</span>
        <?php else: ?>
          <span class="badge badge-warning">Partially processed</span>
        <?php endif; ?>
      </div>

      <?php if (!empty($mass_result['successful'])): ?>
        <div class="p-5 border-b">
          <h3 class="text-sm font-semibold text-emerald-800 mb-3">Successful rows</h3>
          <div class="overflow-x-auto">
            <table class="table">
              <thead><tr><th>Row</th><th>Order</th><th>Status</th><th class="text-right">Charge</th></tr></thead>
              <tbody>
              <?php foreach ($mass_result['successful'] as $row): ?>
                <tr>
                  <td><?=number_format((int)$row['row'])?></td>
                  <td><a class="font-medium text-brand-700 hover:underline" href="<?=site_url('dashboard/orders/'.rawurlencode($row['order']))?>">#<?=htmlspecialchars($row['order'])?></a></td>
                  <td><span class="badge"><?=htmlspecialchars($row['status'])?></span></td>
                  <td class="text-right"><?=htmlspecialchars($row['currency'])?> <?=number_format((float)$row['charge'], 2)?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($mass_result['failed'])): ?>
        <div class="p-5">
          <h3 class="text-sm font-semibold text-rose-800 mb-3">Failed rows</h3>
          <div class="overflow-x-auto">
            <table class="table">
              <thead><tr><th>Row</th><th>Code</th><th>Reason</th><th>Order</th></tr></thead>
              <tbody>
              <?php foreach ($mass_result['failed'] as $row): ?>
                <tr>
                  <td><?=number_format((int)$row['row'])?></td>
                  <td><code class="text-xs"><?=htmlspecialchars($row['code'])?></code></td>
                  <td><?=htmlspecialchars($row['error'])?></td>
                  <td>
                    <?php if (!empty($row['order'])): ?>
                      <a class="font-medium text-brand-700 hover:underline" href="<?=site_url('dashboard/orders/'.rawurlencode($row['order']))?>">#<?=htmlspecialchars($row['order'])?></a>
                    <?php else: ?>&mdash;<?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <details class="card overflow-hidden">
    <summary class="cursor-pointer p-5 font-semibold text-slate-900">Active service reference (<?=number_format(count($services))?>)</summary>
    <div class="overflow-x-auto border-t">
      <table class="table">
        <thead><tr><th>Service</th><th>Slug / public ID</th><th>Range</th><th class="text-right">Rate / 1,000</th></tr></thead>
        <tbody>
        <?php foreach ($services as $service): ?>
          <tr>
            <td>
              <div class="font-medium text-slate-900"><?=htmlspecialchars($service->name)?></div>
              <?php if (!empty($service->category_name)): ?><div class="text-xs text-slate-500"><?=htmlspecialchars($service->category_name)?></div><?php endif; ?>
            </td>
            <td>
              <code class="block text-xs text-slate-800"><?=htmlspecialchars($service->slug)?></code>
              <code class="block mt-1 text-[11px] text-slate-500"><?=htmlspecialchars($service->public_id)?></code>
            </td>
            <td><?=number_format((int)$service->min_quantity)?> &ndash; <?=number_format((int)$service->max_quantity)?></td>
            <td class="text-right"><?=htmlspecialchars($wallet->currency ?? marvy_base_currency())?> <?=number_format((float)($service_rates[(int)$service->id] ?? $service->rate), 2)?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$services): ?><tr><td colspan="4" class="text-center text-slate-500 py-8">No active services are available.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </details>
</div>
