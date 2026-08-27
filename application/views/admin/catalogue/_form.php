<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Partial: the product fields, shared by the create dialog and the edit page.
 *
 * One field set for both so a column can never be editable in one place and
 * not the other — which is how a product ends up with a price you can set
 * when creating it and not when correcting it.
 *
 * Expects: $domain, $options, $product (NULL when creating).
 */
$p = isset($product) ? $product : null;
$v = function ($column, $default = '') use ($p) {
    if (!$p || !isset($p->$column) || $p->$column === null) return $default;
    return $p->$column;
};
// Money is stored with eight decimal places; showing 300.00000000 in an input
// invites an operator to "tidy" it and fat-finger a zero.
$money = function ($column) use ($p) {
    if (!$p || !isset($p->$column) || $p->$column === null) return '';
    return rtrim(rtrim(number_format((float)$p->$column, 8, '.', ''), '0'), '.');
};
$cur = marvy_base_currency();
$variable_vtu = $domain === 'vtu' && $p && in_array($p->service_type, array('AIRTIME','ELECTRICITY'), true);
?>

<?php if ($domain === 'vtu'): ?>
  <label class="field"><span class="label">Network</span>
    <select class="input" name="network_id" required>
      <option value="">Choose a network…</option>
      <?php foreach ($options['networks'] as $n): ?>
        <option value="<?=(int)$n->id?>" <?=(int)$v('network_id') === (int)$n->id ? 'selected' : ''?>>
          <?=htmlspecialchars($n->name)?> — <?=htmlspecialchars($n->service_type)?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="text-xs muted">The service type comes from the network: a product on an
      airtime network is an airtime product, and a mismatch is refused at checkout.</span>
  </label>
<?php elseif ($domain === 'numbers'): ?>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">Country</span>
      <select class="input" name="country_id" required>
        <option value="">Choose…</option>
        <?php foreach ($options['countries'] as $c): ?>
          <option value="<?=(int)$c->id?>" <?=(int)$v('country_id') === (int)$c->id ? 'selected' : ''?>>
            <?=htmlspecialchars($c->name)?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field" style="flex:1"><span class="label">Service</span>
      <select class="input" name="service_id" required>
        <option value="">Choose…</option>
        <?php foreach ($options['services'] as $s): ?>
          <option value="<?=(int)$s->id?>" <?=(int)$v('service_id') === (int)$s->id ? 'selected' : ''?>>
            <?=htmlspecialchars($s->name)?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
<?php elseif ($domain === 'giftcards'): ?>
  <label class="field"><span class="label">Brand</span>
    <select class="input" name="brand_id" required>
      <option value="">Choose a brand…</option>
      <?php foreach ($options['brands'] as $b): ?>
        <option value="<?=(int)$b->id?>" <?=(int)$v('brand_id') === (int)$b->id ? 'selected' : ''?>>
          <?=htmlspecialchars($b->name)?><?=$b->is_active ? '' : ' (brand off sale)'?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
<?php endif; ?>

<div class="row" style="gap:.75rem">
  <label class="field" style="flex:2"><span class="label">Name</span>
    <input class="input" name="name" required maxlength="160"
           value="<?=htmlspecialchars((string)$v('name'))?>"
           placeholder="<?=$domain === 'vtu' ? 'MTN 1GB (30 days)' : ($domain === 'giftcards' ? 'Amazon US $25' : 'What the customer sees')?>">
  </label>
  <label class="field" style="flex:1"><span class="label">Code</span>
    <input class="input mono" name="code" maxlength="96"
           value="<?=htmlspecialchars((string)$v('code'))?>" placeholder="auto">
    <span class="text-xs muted">Our stable id. Leave blank to generate one.</span>
  </label>
</div>

<?php if ($domain === 'identity'): ?>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">ID type</span>
      <select class="input" name="id_type" required>
        <?php foreach (array('NIN','BVN') as $t): ?>
          <option value="<?=$t?>" <?=$v('id_type') === $t ? 'selected' : ''?>><?=$t?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field" style="flex:1"><span class="label">Customer types</span>
      <select class="input" name="lookup_field">
        <option value="IDENTIFIER" <?=$v('lookup_field','IDENTIFIER') === 'IDENTIFIER' ? 'selected' : ''?>>The ID number</option>
        <option value="PHONE" <?=$v('lookup_field') === 'PHONE' ? 'selected' : ''?>>A phone number (NIN only)</option>
      </select>
    </label>
  </div>
<?php endif; ?>

<?php if ($domain === 'giftcards'): ?>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">Denomination</span>
      <select class="input" name="denomination_type">
        <option value="FIXED" <?=$v('denomination_type','FIXED') === 'FIXED' ? 'selected' : ''?>>Fixed face value</option>
        <option value="RANGE" <?=$v('denomination_type') === 'RANGE' ? 'selected' : ''?>>Custom amount</option>
      </select>
    </label>
    <label class="field" style="flex:1"><span class="label">Card currency</span>
      <input class="input mono" name="recipient_currency" maxlength="3" required
             value="<?=htmlspecialchars((string)$v('recipient_currency'))?>" placeholder="USD">
      <span class="text-xs muted">What the card itself is worth — never assumed.</span>
    </label>
    <label class="field" style="flex:1"><span class="label">Country</span>
      <input class="input mono" name="country_code" maxlength="2"
             value="<?=htmlspecialchars((string)$v('country_code','US'))?>" placeholder="US">
    </label>
  </div>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">Face value</span>
      <input class="input" type="number" step="0.01" min="0" name="face_value"
             value="<?=htmlspecialchars($money('face_value'))?>">
    </label>
    <label class="field" style="flex:1"><span class="label">Max per order</span>
      <input class="input" type="number" step="1" min="1" name="max_quantity"
             value="<?=htmlspecialchars((string)$v('max_quantity', 5))?>">
    </label>
  </div>
<?php endif; ?>

<?php if ($domain === 'vtu' && (!$p || !$variable_vtu)): ?>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">Face value</span>
      <input class="input" type="number" step="0.01" min="0" name="face_value"
             value="<?=htmlspecialchars($money('face_value'))?>">
      <span class="text-xs muted">What the bundle is nominally worth. Optional.</span>
    </label>
    <label class="field" style="flex:1"><span class="label">Validity</span>
      <input class="input" name="validity" maxlength="32"
             value="<?=htmlspecialchars((string)$v('validity'))?>" placeholder="30 days">
    </label>
    <label class="field" style="flex:1"><span class="label">Product type</span>
      <input class="input" name="product_type" maxlength="32"
             value="<?=htmlspecialchars((string)$v('product_type'))?>" placeholder="SME / GIFTING">
    </label>
  </div>
<?php endif; ?>

<?php if ($variable_vtu): ?>
  <div class="alert alert-info text-sm">
    This is a variable-amount product: the customer names the amount and pays it less
    the discount below, so it has no fixed price. Only one may be live per network —
    the buying path takes the first active row it finds.
  </div>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">Discount %</span>
      <input class="input" type="number" step="0.01" min="0" max="100" name="discount_percent"
             value="<?=htmlspecialchars(rtrim(rtrim((string)$v('discount_percent','0'), '0'), '.') ?: '0')?>">
      <span class="text-xs muted">₦1,000 airtime at 2% costs the customer ₦980.</span>
    </label>
    <label class="field" style="flex:1"><span class="label">Minimum (<?=htmlspecialchars($cur)?>)</span>
      <input class="input" type="number" step="0.01" min="0" name="min_amount"
             value="<?=htmlspecialchars($money('min_amount'))?>">
    </label>
    <label class="field" style="flex:1"><span class="label">Maximum (<?=htmlspecialchars($cur)?>)</span>
      <input class="input" type="number" step="0.01" min="0" name="max_amount"
             value="<?=htmlspecialchars($money('max_amount'))?>">
    </label>
  </div>
<?php else: ?>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">Price (<?=htmlspecialchars($cur)?>)</span>
      <input class="input" type="number" step="0.01" min="0" name="price"
             value="<?=htmlspecialchars($money('price'))?>">
      <span class="text-xs muted">What the customer pays. Required before it can go on sale.</span>
    </label>
    <label class="field" style="flex:1"><span class="label">Vendor cost (<?=htmlspecialchars($cur)?>)</span>
      <input class="input" type="number" step="0.01" min="0" name="provider_cost"
             value="<?=htmlspecialchars($money('provider_cost'))?>">
      <span class="text-xs muted">Only when the vendor bills in <?=htmlspecialchars($cur)?>.</span>
    </label>
  </div>
<?php endif; ?>

<?php if ($domain === 'numbers'): ?>
  <div class="row" style="gap:.75rem">
    <label class="field" style="flex:1"><span class="label">Stock</span>
      <input class="input" type="number" step="1" min="0" name="stock"
             value="<?=htmlspecialchars($v('stock') === '' ? '' : (string)$v('stock'))?>" placeholder="unknown">
      <span class="text-xs muted">Advisory, refreshed by sync. Zero blocks reservations.</span>
    </label>
    <label class="field" style="flex:1"><span class="label">Hold (minutes)</span>
      <input class="input" type="number" step="1" min="1" max="1440" name="ttl_minutes"
             value="<?=htmlspecialchars((string)$v('ttl_minutes', 15))?>">
    </label>
  </div>
<?php endif; ?>

<details class="text-sm muted">
  <summary>Vendor mapping</summary>
  <p class="text-xs muted" style="margin-top:.5rem">
    How this product is named upstream. A row with no provider falls back to the first
    active provider that can serve this domain, which is usually what you want with one
    vendor and never what you want with two.
  </p>
  <label class="field" style="margin-top:.5rem"><span class="label">Provider</span>
    <select class="input" name="provider_id">
      <option value="">Any provider for this domain</option>
      <?php foreach ($options['providers'] as $pr): ?>
        <option value="<?=(int)$pr->id?>" <?=(int)$v('provider_id') === (int)$pr->id ? 'selected' : ''?>>
          <?=htmlspecialchars($pr->name)?> (<?=htmlspecialchars($pr->api_type)?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($domain === 'vtu'): ?>
    <label class="field" style="margin-top:.5rem"><span class="label">Vendor variation code</span>
      <input class="input mono" name="provider_code" maxlength="64"
             value="<?=htmlspecialchars((string)$v('provider_code'))?>" placeholder="mtn-10gb-1000">
    </label>
  <?php elseif ($domain === 'numbers'): ?>
    <div class="row" style="gap:.75rem;margin-top:.5rem">
      <label class="field" style="flex:1"><span class="label">Vendor country</span>
        <input class="input mono" name="provider_country" maxlength="48"
               value="<?=htmlspecialchars((string)$v('provider_country'))?>" placeholder="nigeria">
      </label>
      <label class="field" style="flex:1"><span class="label">Vendor product</span>
        <input class="input mono" name="provider_product" maxlength="48"
               value="<?=htmlspecialchars((string)$v('provider_product'))?>" placeholder="whatsapp">
      </label>
      <label class="field" style="flex:1"><span class="label">Operator</span>
        <input class="input mono" name="provider_operator" maxlength="48"
               value="<?=htmlspecialchars((string)$v('provider_operator','any'))?>" placeholder="any">
      </label>
    </div>
  <?php elseif ($domain === 'identity'): ?>
    <label class="field" style="margin-top:.5rem"><span class="label">Vendor endpoint</span>
      <input class="input mono" name="provider_code" maxlength="64"
             value="<?=htmlspecialchars((string)$v('provider_code'))?>" placeholder="kyc/nin">
    </label>
  <?php else: ?>
    <label class="field" style="margin-top:.5rem"><span class="label">Vendor product id</span>
      <input class="input mono" name="provider_product_id" maxlength="48"
             value="<?=htmlspecialchars((string)$v('provider_product_id'))?>" placeholder="11">
    </label>
  <?php endif; ?>
</details>

<?php if ($domain === 'vtu' || $domain === 'identity'): ?>
  <label class="field"><span class="label">Description</span>
    <input class="input" name="description" maxlength="255"
           value="<?=htmlspecialchars((string)$v('description'))?>">
  </label>
<?php endif; ?>

<div class="row" style="gap:.75rem;align-items:flex-end">
  <label class="field" style="flex:1"><span class="label">Sort order</span>
    <input class="input" type="number" step="1" name="sorting" value="<?=htmlspecialchars((string)$v('sorting', 0))?>">
  </label>
  <label class="field" style="flex:2">
    <span class="label">On sale</span>
    <label class="text-sm">
      <input type="checkbox" name="is_active" value="1" <?=$v('is_active') ? 'checked' : ''?>>
      Show this to customers
    </label>
    <span class="text-xs muted">Refused without a price — an unpriced product fails at checkout.</span>
  </label>
</div>
