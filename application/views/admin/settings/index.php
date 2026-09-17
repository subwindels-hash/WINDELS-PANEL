<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

$category_titles = array(
    'contact'  => 'Contact page',
    'legal'    => 'Legal and company details',
    'general'   => 'General',
    'homepage'  => 'Homepage content',
    'security'  => 'Registration and security',
    'payments'  => 'Deposits',
    'affiliate' => 'Referrals',
    'identity'  => 'Identity verification',
    'giftcards' => 'Gift cards',
    'crypto'    => 'Bitcoin and crypto deposits',
    'fundsvera' => 'Bank transfers (Fundsvera)',
    'gateways'  => 'Card and wallet gateways',
    'email'     => 'Outgoing email',
    'referrals' => 'Referrals, earnings and payouts',
    'marketplace' => 'Marketplace',
    'orders'    => 'Orders',
    'api'       => 'Reseller API',
    'currency'  => 'Currency',
    'branding'  => 'Branding',
);

/** Render one control from its schema declaration. */
$field = function ($key, $def, $value) {
    list($type, , $label, $help) = $def;
    $id = 'set-'.$key;
    echo '<div class="field" style="margin-bottom:1rem">';

    if ($type === 'bool') {
        $on = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        // A checkbox that is off sends nothing, so declare it was rendered —
        // otherwise switching one off would be indistinguishable from a form
        // that never showed it.
        echo '<input type="hidden" name="__rendered_'.htmlspecialchars($key).'" value="1">';
        echo '<label class="row" style="gap:.5rem;align-items:center">';
        echo '<input type="checkbox" id="'.htmlspecialchars($id).'" name="'.htmlspecialchars($key).'" value="1"'
            .($on ? ' checked' : '').'>';
        echo '<span class="label" style="margin:0">'.htmlspecialchars($label).'</span>';
        echo '</label>';
    } elseif (strpos($type, 'choice:') === 0) {
        $opts = explode('|', substr($type, 7));
        echo '<label class="label" for="'.htmlspecialchars($id).'">'.htmlspecialchars($label).'</label>';
        echo '<select class="select" id="'.htmlspecialchars($id).'" name="'.htmlspecialchars($key).'">';
        foreach ($opts as $o) {
            echo '<option value="'.htmlspecialchars($o).'"'
                .((string)$value === $o ? ' selected' : '').'>'.htmlspecialchars($o).'</option>';
        }
        echo '</select>';
    } elseif ($type === 'secret') {
        // Never echo a stored secret back to the browser. A configured value
        // shows as a placeholder that save() interprets as "leave unchanged";
        // clearing the box stores an empty value and disables the feature.
        $configured = ($value !== null && $value !== '' && $value !== SettingsService::SECRET_PLACEHOLDER);
        echo '<label class="label" for="'.htmlspecialchars($id).'">'.htmlspecialchars($label).'</label>';
        echo '<input class="input mono" type="password" autocomplete="new-password" spellcheck="false"'
            .' id="'.htmlspecialchars($id).'"'
            .' name="'.htmlspecialchars($key).'"'
            .' value="'.($configured ? htmlspecialchars(SettingsService::SECRET_PLACEHOLDER) : '').'"'
            .' placeholder="'.($configured ? 'Configured — type a new value to replace it' : 'Not configured').'">';
        if ($configured) {
            echo '<p class="muted text-xs" style="margin:.25rem 0 0">'
                .'A value is stored. Leave the field untouched to keep it, or clear it to remove it.</p>';
        }
    } elseif ($type === 'color') {
        // A native colour picker plus the hex, because operators paste brand
        // hexes far more often than they pick from a wheel.
        $hex = (string)$value !== '' ? (string)$value : '#000000';
        echo '<label class="label" for="'.htmlspecialchars($id).'">'.htmlspecialchars($label).'</label>';
        echo '<div class="row" style="gap:.5rem;align-items:center">';
        echo '<input type="color" id="'.htmlspecialchars($id).'-picker" value="'.htmlspecialchars($hex).'"'
            .' data-color-for="'.htmlspecialchars($id).'"'
            .' style="width:3rem;height:2.5rem;padding:2px;border:1px solid var(--color-border);border-radius:.5rem;background:var(--color-surface)">';
        echo '<input class="input mono" type="text" id="'.htmlspecialchars($id).'"'
            .' name="'.htmlspecialchars($key).'" value="'.htmlspecialchars((string)$value).'"'
            .' placeholder="#0b1b3a" spellcheck="false" style="max-width:12rem">';
        echo '</div>';
    } elseif ($type === 'longtext') {
        echo '<label class="label" for="'.htmlspecialchars($id).'">'.htmlspecialchars($label).'</label>';
        echo '<textarea class="textarea" id="'.htmlspecialchars($id).'" name="'.htmlspecialchars($key).'" rows="4">'
            .htmlspecialchars((string)$value).'</textarea>';
    } else {
        $input_type = in_array($type, array('int','money','percent'), true) ? 'number'
                    : ($type === 'email' ? 'email' : ($type === 'url' ? 'url' : 'text'));
        $step = $type === 'money' ? '0.01' : ($type === 'percent' ? '0.01' : '1');
        echo '<label class="label" for="'.htmlspecialchars($id).'">'.htmlspecialchars($label).'</label>';
        echo '<input class="input'.($input_type === 'number' ? ' mono' : '').'"'
            .' type="'.$input_type.'"'
            .($input_type === 'number' ? ' step="'.$step.'" min="0"' : '')
            .($type === 'percent' ? ' max="100"' : '')
            .' id="'.htmlspecialchars($id).'"'
            .' name="'.htmlspecialchars($key).'"'
            .' value="'.htmlspecialchars((string)$value).'">';
    }

    if ($help) echo '<p class="muted text-xs" style="margin:.25rem 0 0">'.htmlspecialchars($help).'</p>';
    echo '</div>';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Settings</h2>
    <p class="muted text-sm">Panel-wide configuration. Every change is recorded in the audit log.</p>
  </div>
</div>

<form method="post" action="<?=site_url('admin/settings/save')?>">
  <?=$csrf()?>

  <?php foreach ($grouped as $category => $fields): ?>
    <div class="card mb-4">
      <h3 style="font-size:1rem;font-weight:600" class="mb-3">
        <?=htmlspecialchars($category_titles[$category] ?? ucfirst($category))?>
      </h3>

      <?php foreach ($fields as $key => $def): ?>
        <?php $field($key, $def, $values[$key] ?? $def[4]); ?>
      <?php endforeach; ?>

      <?php if ($category === 'general'): ?>
        <?php /* Always rendered. It used to be hidden whenever a base_currency
                 value existed, which on a seeded panel is always — so the only
                 way to reach the control was for it to be doing nothing. */ ?>
        <div class="field" style="margin-bottom:1rem">
          <label class="label" for="set-base_currency">Base currency</label>
          <?php /* Do not put data-confirm on this select. The global behaviour
                 handler treats data-confirm as a click guard, so the browser
                 used to show a blocking warning every time an operator merely
                 opened the dropdown. Selecting a value is harmless; the
                 transaction only runs when Save settings is submitted. */ ?>
          <select class="select" id="set-base_currency" name="base_currency">
            <?php foreach (($base_currency_choices ?? array()) as $code => $label): ?>
              <option value="<?=htmlspecialchars($code)?>" <?=$base_currency === $code ? 'selected' : ''?>>
                <?=htmlspecialchars($label)?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="muted text-xs" style="margin:.25rem 0 0">
            The accounting currency every wallet, order and ledger entry is denominated in.
            Changing it <strong>converts every stored amount</strong> at the current exchange rate
            in a single transaction — a <?=htmlspecialchars(marvy_money(100))?> wallet keeps its real
            value rather than becoming 100 of the new currency. Set the target currency's rate under
            <a href="<?=site_url('admin/currencies')?>">Admin → Currencies</a> first. Select the new
            currency here, then click <strong>Save settings</strong> to apply and save the change.
          </p>
        </div>
        <?php endif; ?>
      <?php if ($category === 'payments'): ?>
        <div class="alert alert-info">
          Deposit limits and flat fees are configured in the base currency,
          <?=htmlspecialchars($base_currency)?>. Customers using Manual / Bank Transfer or Fundsvera pay in the
          default currency selected under <a href="<?=site_url('admin/currencies')?>">Currencies</a>; the panel
          converts the confirmed payment into <?=htmlspecialchars($base_currency)?> at the rate locked on the deposit.
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="row justify-between" style="align-items:center;flex-wrap:wrap;gap:.5rem">
    <span class="muted text-xs">Changes take effect immediately.</span>
    <button class="btn btn-primary" type="submit">Save settings</button>
  </div>
</form>

<div class="card mt-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Currency settings</h3>
  <p class="muted text-xs mb-3">
    The base currency is changed in the General section above. Per-currency exchange rates,
    including the manual NGN rate box, live under
    <a href="<?=site_url('admin/currencies')?>">Admin → Currencies</a>.
  </p>
  <table class="table">
    <tbody>
      <tr>
        <td class="font-medium">Base currency</td>
        <td class="mono"><?=htmlspecialchars($base_currency)?></td>
        <td class="text-xs muted">Every stored amount is denominated in this.</td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card mt-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Seeded but not yet honoured</h3>
  <?php if (empty($unwired)): ?>
    <p class="muted text-xs mb-3">There are no unwired settings in this build. All seeded keys are honoured by code.</p>
  <?php else: ?>
    <p class="muted text-xs mb-3">
      These keys exist in the database from the initial seed, but no code reads them yet, so
      they are not shown as controls — a switch that saves and changes nothing is worse than
      no switch. Each line is what it would take to make it real.
    </p>
  <?php endif; ?>
  <table class="table">
    <tbody>
    <?php foreach ($unwired as $key => $why): ?>
      <tr>
        <td class="mono text-xs" style="white-space:nowrap"><?=htmlspecialchars($key)?></td>
        <td class="text-xs muted"><?=htmlspecialchars($why)?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
