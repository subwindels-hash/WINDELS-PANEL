<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Two currencies are in play on this page and confusing them is the whole
// class of bug this layout exists to prevent:
//
//   $base — the accounting currency. Wallets, orders and limits are stored in
//           it, and it is what the wallet is ultimately credited with.
//   $pay  — the default display currency. It is what the customer sees, what
//           they type an amount in, and what the payment gateway is charged
//           in. On the shipped NGN panel the two are the same and every
//           conversion line below collapses to nothing.
//
// $rate is units of $pay per 1 unit of $base, pinned by the controller for
// this render. The deposit is created at this rate (PaymentService pins it on
// the row), so what is printed here is what is charged — not an estimate.
$base = $base_currency ?? marvy_base_currency();
$pay  = $pay_currency  ?? $base;
$rate = (float)($fx_rate ?? 1);
if ($rate <= 0) $rate = 1.0;
$converts = strtoupper((string)$pay) !== strtoupper((string)$base);

// Deposit bounds come from settings and are stored in the base currency, so
// they are converted into what the customer is typing in.
$min_base = (float)($min_deposit ?? 500);
$max_base = (float)($max_deposit ?? 5000000);
$min = $min_base * $rate;
$max = $max_base * $rate;

// A round figure in the currency being typed, kept inside the bounds.
$suggested = min($max, max($min, 5000 * $rate));

$wallet_cur = strtoupper((string)($wallet->currency ?? $base));
$balance    = (string)($wallet->balance ?? '0');

// Units of the WALLET's currency per 1 unit of base — what the credit has to
// be multiplied by to land in the balance the customer is looking at. A base
// wallet is 1:1; LedgerService applies exactly this conversion when it credits.
$wallet_rate = $wallet_cur === strtoupper((string)$base)
    ? 1.0 : (float)marvy_display_rate($wallet_cur);
if ($wallet_rate <= 0) $wallet_rate = 1.0;
?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
<?php if ($converts): ?>
    <div class="card" style="border-left:3px solid var(--primary-500)">
      <div class="row justify-between" style="align-items:flex-start;gap:1rem">
        <div>
          <div class="card-meta">Today's rate</div>
          <div class="mt-1 text-xl font-bold" style="font-family:var(--font-display)">
            <?=html_escape(marvy_rate_line($rate, $pay, $base))?>
          </div>
        </div>
        <span class="badge badge-success">Pay in <?=html_escape($pay)?></span>
      </div>
      <p class="muted text-sm mt-2 mb-0">
        You pay in <strong><?=html_escape($pay)?></strong> and your wallet is credited in
        <strong><?=html_escape($base)?></strong> at this rate. Deposit
        <?=marvy_money($rate, $pay)?> and <?=marvy_money(1, $base)?> lands in your wallet.
        The rate is locked onto your deposit the moment you press Continue, so a later
        rate change cannot alter what you are charged.
      </p>
    </div>
<?php endif; ?>
<?php if (!empty($can_choose_currency) && !empty($currency_choices)): ?>
    <div class="card">
      <h2 class="card-title">Wallet currency</h2>
      <p class="muted text-sm mb-3">
        Choose the currency this wallet holds. It can only be set while the wallet is empty and unused —
        after the first deposit the currency is fixed, because changing it would re-price every balance
        and movement already on the account. Purchases are always priced in
        <?=html_escape($base)?> and charged at the current exchange rate.
      </p>
      <?=form_open('dashboard/wallet/currency', array('class'=>'row'))?>
        <label class="field" style="flex:1">
          <span class="label">Hold my wallet in</span>
          <select class="select" name="currency" required>
            <?php foreach ($currency_choices as $c): ?>
              <option value="<?=htmlspecialchars($c->code)?>" <?=strtoupper((string)$c->code)===$wallet_cur?'selected':''?>>
                <?=htmlspecialchars($c->code)?> — <?=htmlspecialchars($c->name)?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Set currency</button>
      <?=form_close()?>
    </div>
<?php endif; ?>
    <div class="card">
      <h2 class="card-title">Add funds to your wallet</h2>
      <p class="muted">
        Wallet balance is held in <strong><?=html_escape($wallet_cur)?></strong> and used to pay for orders.
        Deposits are charged in <strong><?=html_escape($pay)?></strong>, between
        <?=marvy_money($min, $pay)?> and <?=marvy_money($max, $pay)?>.
      </p>
      <?php if ($wallet_cur !== strtoupper((string)$base)): ?>
        <p class="muted text-sm mt-2">Your <?=html_escape($wallet_cur)?> wallet is credited with the <?=html_escape($base)?> value of the deposit at the current exchange rate, and every purchase is charged in <?=html_escape($base)?> converted at the rate pinned at that moment.</p>
      <?php else: ?>
        <p class="muted text-sm mt-2">Your MARVYSOCIALS wallet is a platform spending balance: it pays for services, orders and other supported purchases inside MarvySocials. Wallet funds are for spending within the platform and stay inside it.</p>
      <?php endif; ?>

      <?=form_open('dashboard/wallet/deposit', array('class'=>'mt-4 stack'))?>
        <?php // One token per rendered form: a double-submit or a retry of THIS
              // form resolves to the same deposit instead of opening a second
              // checkout at the payment provider. ?>
        <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('dep', true))?>">
        <label class="field">
          <span class="label">Amount you pay (<?=html_escape($pay)?>)</span>
          <input id="ws-amount" class="input" name="amount" type="number" min="<?=number_format($min, 2, '.', '')?>" max="<?=number_format($max, 2, '.', '')?>" step="0.01" value="<?=number_format($suggested, 2, '.', '')?>" inputmode="decimal" required>
          <span class="hint">
            Minimum <?=marvy_money($min, $pay)?>, maximum <?=marvy_money($max, $pay)?>.
            <?php if ($converts): ?>
              Rate: <?=html_escape(marvy_rate_line($rate, $pay, $base))?>.
            <?php endif; ?>
          </span>
        </label>

        <?php if ($converts): ?>
          <?php // The whole point of the request: type naira, see dollars.
                // Updated live by the script below at the same pinned rate. ?>
          <div class="alert alert-info" style="margin:0">
            <span class="muted text-sm">Your wallet will be credited</span>
            <div class="text-2xl font-bold mt-1" style="font-family:var(--font-display)" id="ws-credit">
              <?=marvy_money($suggested / $rate, $base)?>
            </div>
            <span class="text-xs muted" id="ws-rate-line">
              <?=html_escape(marvy_rate_line($rate, $pay, $base))?> · you pay
              <span id="ws-pay"><?=marvy_money($suggested, $pay)?></span>
            </span>
          </div>
        <?php endif; ?>

        <div>
          <span class="label">Payment method</span>
          <p class="hint" style="margin:.25rem 0 0">
            Every method below charges in <strong><?=html_escape($pay)?></strong><?=$converts ? ' at the rate shown above' : ''?>.
          </p>
          <div class="grid grid-3 mt-2" style="gap:1rem">
            <?php if (empty($methods)): ?>
              <p class="muted">No payment methods are enabled yet.</p>
            <?php else: foreach ($methods as $m): ?>
              <?php // What this method's provider actually collects. An
                    // NGN-only bank rail on a non-NGN panel converts the typed
                    // amount at today's rate — label it honestly. ?>
              <?php $collects = strtoupper((string)(($method_collects[$m->code] ?? '') ?: $pay)); ?>
              <label class="ws-payopt card">
                <input type="radio" name="payment_method" value="<?=htmlspecialchars($m->code)?>" required <?=!empty($m->is_active)?'':'disabled'?>>
                <span class="font-medium"><?=htmlspecialchars($m->name)?></span>
                <?php if ($collects !== strtoupper((string)$pay)): ?>
                  <span class="badge badge-default" title="You type the amount in <?=html_escape($pay)?>; the bank transfer is made in <?=html_escape($collects)?> at today's rate"><?=html_escape($pay)?> → <?=html_escape($collects)?></span>
                <?php else: ?>
                  <span class="badge badge-default"><?=html_escape($pay)?></span>
                <?php endif; ?>
                <?php if ((float)$m->bonus_percent > 0): ?>
                  <span class="badge badge-warning">+<?=rtrim(rtrim(number_format($m->bonus_percent,2),'0'),'.')?>% bonus</span>
                <?php endif; ?>
              </label>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <button class="btn btn-primary" type="submit" id="ws-submit" data-loading-text="Processing…">Continue →</button>
      <?=form_close()?>
    </div>

    <div class="card">
      <div class="row justify-between">
        <h2 class="card-title mb-0">Recent deposits</h2>
        <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/wallet/deposits')?>">View all →</a>
      </div>
      <?php // id tie-break: created_at has second granularity (model's rule). ?>
      <?php $recent = $this->db->where('user_id',$current_user->id)->order_by('created_at','DESC')->order_by('id','DESC')->limit(5)->get('payment_transactions')->result(); ?>
      <?php if (empty($recent)): ?>
        <p class="muted mt-3">No deposits yet.</p>
      <?php else: ?>
      <div class="overflow-x-auto mt-3">
        <table class="table">
          <thead><tr><th>Reference</th><th>Method</th><th>Paid</th><th>Credited</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $d):
            // A deposit taken before the charge/settlement split has no base
            // leg; its charge currency WAS the accounting currency.
            $d_base_cur = strtoupper((string)($d->base_currency ?? $d->currency));
            $d_credited = $d->credited_base_amount ?? $d->credited_amount;
          ?>
            <tr>
              <td class="mono text-xs"><?=htmlspecialchars(substr($d->public_id,0,12))?>…</td>
              <td><?=htmlspecialchars($d->payment_method_id)?></td>
              <td class="mono"><?=marvy_money($d->amount, $d->currency)?></td>
              <td class="mono">
                <?=$d_credited !== null ? marvy_money($d_credited, $d_base_cur) : '—'?>
                <?php if (!empty($d->fx_rate) && strtoupper((string)$d->currency) !== $d_base_cur): ?>
                  <div class="text-xs muted font-normal"><?=html_escape(marvy_rate_line($d->fx_rate, $d->currency, $d_base_cur))?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge <?=$d->status==='SUCCESS'?'badge-success':($d->status==='FAILED'?'badge-danger':'badge-warning')?>"><?=htmlspecialchars($d->status)?></span></td>
              <td class="text-xs muted"><?=date('M j, H:i', strtotime($d->created_at))?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <aside class="space-y-6">
    <?php if (!empty($va_available)): ?>
    <div class="card">
      <h3 class="card-title">Your bank account</h3>
      <?php if (!empty($virtual_account)): ?>
        <p class="muted text-sm mt-2">
          Pay into your dedicated account any time — your wallet is credited
          automatically once the bank confirms. No deposit needs to be opened first.
        </p>
        <dl class="stack mt-3" style="gap:.5rem">
          <div><span class="muted text-xs">Bank</span><br><strong><?=htmlspecialchars($virtual_account->bank_name)?></strong></div>
          <div><span class="muted text-xs">Account number</span><br><strong class="mono" style="font-size:1.1rem"><?=htmlspecialchars($virtual_account->account_number)?></strong></div>
          <div><span class="muted text-xs">Account name</span><br><strong><?=htmlspecialchars($virtual_account->account_name)?></strong></div>
        </dl>
      <?php else: ?>
        <p class="muted text-sm mt-2">
          Generate a dedicated bank account and pay into it any time — your
          wallet is credited automatically once the bank confirms the payment.
        </p>
        <?=form_open('dashboard/wallet/virtual-account', array('class'=>'stack mt-3'))?>
          <button class="btn btn-secondary" type="submit">Generate my account number</button>
        <?=form_close()?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card">
      <h3 class="card-title">Summary</h3>
      <dl class="mt-3 stack" style="gap:.5rem">
        <div class="row justify-between"><span class="muted">Base currency</span><strong><?=html_escape($base)?></strong></div>
        <div class="row justify-between"><span class="muted">You pay in</span><strong><?=html_escape($pay)?></strong></div>
        <?php if ($converts): ?>
        <div class="row justify-between"><span class="muted">Rate</span><strong class="mono"><?=html_escape(marvy_rate_line($rate, $pay, $base))?></strong></div>
        <?php endif; ?>
        <div class="row justify-between">
          <span class="muted">Current balance</span>
          <strong><?=marvy_money($balance, $wallet_cur)?></strong>
        </div>
        <?php // Balance in BOTH currencies: the wallet's own, and the one the
              // customer is about to pay in, so "have I got enough" and "what
              // is this worth" are answerable without leaving the page. ?>
        <?php if ($wallet_cur !== strtoupper((string)$pay)):
          // Value the balance in the currency being paid in. The wallet's own
          // rate takes it to base; the pinned rate takes base to pay.
          $in_pay = bcmul(
              bcdiv($balance, number_format($wallet_rate, 8, '.', ''), 8),
              number_format($rate, 8, '.', ''), 8);
        ?>
        <div class="row justify-between">
          <span class="muted text-xs">Worth in <?=html_escape($pay)?></span>
          <span class="text-xs muted mono"><?=marvy_money($in_pay, $pay)?></span>
        </div>
        <?php endif; ?>
        <div class="row justify-between"><span class="muted">Deposit</span><strong id="ws-deposit"><?=marvy_money($suggested, $pay)?></strong></div>
        <div class="row justify-between border-t pt-2" style="border-color:var(--slate-200)">
          <span>New balance</span>
          <strong id="ws-newbal"><?=marvy_money($balance + ($suggested / $rate) * $wallet_rate, $wallet_cur)?></strong>
        </div>
      </dl>
      <p class="hint mt-3">Funds are credited after confirmation. No card data touches this server for hosted-gateway methods.</p>
    </div>
  </aside>
</div>

<style>
.ws-payopt{display:flex;align-items:center;gap:.6rem;cursor:pointer;padding:1rem}
.ws-payopt input{margin:0}
</style>
<script <?=csp_nonce_attr()?>>
(function(){
  var amt=document.getElementById('ws-amount'),
      dep=document.getElementById('ws-deposit'),
      nb=document.getElementById('ws-newbal'),
      credit=document.getElementById('ws-credit'),
      payOut=document.getElementById('ws-pay');
  if(!amt) return;
  // Everything the running total needs comes from the server so the figures
  // this script prints are the same figures the deposit is created with: the
  // same pinned rate, the same symbols, the same rounding.
  var rate=parseFloat(<?=json_encode((float)$rate)?>)||1,
      walletRate=parseFloat(<?=json_encode((float)$wallet_rate)?>)||1,
      bal=parseFloat(<?=json_encode((float)$balance)?>)||0;
  var paySym=<?=json_encode(marvy_currency_symbol($pay))?>,
      baseSym=<?=json_encode(marvy_currency_symbol($base))?>,
      walletSym=<?=json_encode(marvy_currency_symbol($wallet_cur))?>;
  function fmt(sym,v){return sym+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function recalc(){
    var paid=parseFloat(amt.value)||0;
    // The customer types the PAY amount; the wallet gets paid ÷ rate.
    var inBase=paid/rate;
    if(dep) dep.textContent=fmt(paySym,paid);
    if(payOut) payOut.textContent=fmt(paySym,paid);
    if(credit) credit.textContent=fmt(baseSym,inBase);
    // The wallet may hold a third currency; LedgerService converts base into
    // it on credit, so the projected balance has to do the same.
    if(nb) nb.textContent=fmt(walletSym,bal+inBase*walletRate);
  }
  amt.addEventListener('input',recalc);recalc();
})();
</script>
