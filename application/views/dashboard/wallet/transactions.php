<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-4">
  <div class="lg:col-span-1">
    <div class="card">
      <div class="card-meta">Current balance</div>
      <div class="mt-1 text-3xl font-bold" style="font-family:var(--font-display)"><?=windels_money($wallet->balance ?? '0', $wallet->currency ?? windels_base_currency())?></div>
      <a class="btn btn-primary btn-block btn-sm mt-3" href="<?=site_url('dashboard/add-funds')?>">Add funds</a>
    </div>
  </div>

  <div class="lg:col-span-3 card">
    <h2 class="card-title">Transactions</h2>
    <p class="muted text-sm mb-4">Deposits, order charges, refunds, bonuses and adjustments — your wallet balance is spent here, inside the platform.</p>
    <?php if (empty($transactions)): ?>
      <?php $this->load->view('partials/empty_state', array(
          'icon'  => 'wallet',
          'title' => 'No transactions yet',
          'body'  => 'Deposits, order charges and credits are recorded here on a double-entry ledger.',
          'action_href'  => site_url('dashboard/add-funds'),
          'action_label' => 'Add funds',
      )); ?>
    <?php else: ?>
    <div class="overflow-x-auto mt-3">
      <table class="table">
        <thead><tr><th>Date</th><th>Description</th><th>Type</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
          <tr>
            <td class="text-xs muted whitespace-nowrap"><?=date('M j, Y H:i', strtotime($tx->created_at))?> UTC</td>
            <td><?=htmlspecialchars(DashboardStats::transaction_label($tx))?></td>
            <td><span class="badge badge-default"><?=htmlspecialchars(str_replace('_',' ', $tx->type))?></span></td>
            <td class="text-right mono font-semibold" style="color: $tx->direction==='CREDIT' ? 'var(--success-700)' : 'var(--slate-800)'">
              <?=$tx->direction==='CREDIT'?'+':'−'?><?=windels_money($tx->amount, $tx->currency)?>
            </td>
            <td class="text-right mono muted"><?=windels_money($tx->balance_after, $tx->currency)?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="row justify-between mt-4">
      <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>"
         href="<?=site_url('dashboard/transactions?page='.max(1,$page-1))?>">← Previous</a>
      <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
      <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>"
         href="<?=site_url('dashboard/transactions?page='.min($total_pages,$page+1))?>">Next →</a>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
