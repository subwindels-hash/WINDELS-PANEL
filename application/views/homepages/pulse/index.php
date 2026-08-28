<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * PULSE homepage — the search-first, mobile-first variant.
 *
 * Same live data as AURORA and NEXUS. The argument here is speed: find a
 * service, see what it costs, order. The quick-order estimator is pure
 * client-side arithmetic over rates the server already rendered — it never
 * calls an endpoint, and the wallet is still charged at the price the server
 * calculates on submit.
 */
$showcase       = isset($data['showcase']) && is_array($data['showcase']) ? $data['showcase'] : array();
$categories     = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : array();
$catalogue_size = isset($data['catalogue_size']) ? (int)$data['catalogue_size'] : 0;
$faqs           = isset($data['faqs']) && is_array($data['faqs']) ? $data['faqs'] : array();
$cu             = $current_user ?? null;

$hero_kicker = $data['hero_kicker'] ?: 'Find the right service';
$hero_title  = $data['hero_title'] ?: 'Search it. Price it. Order it.';
$hero_lede   = $data['hero_lede'] ?: 'Type what you want to grow. The catalogue, the rate and the exact charge are one screen away — on a phone as much as a desktop.';
$cta_primary = $data['cta_primary'] ?? 'Get started';

$symbol = trim(str_replace(array('0', '.', ','), '', marvy_money(0)));
$quick  = array();
foreach (array_slice($showcase, 0, 8) as $s) {
    $quick[] = array(
        'name' => $s->name,
        'rate' => (string)$s->rate,
        'slug' => $s->slug,
        'min'  => (int)($s->min_quantity ?? 1),
    );
}
?>
<style>
/* PULSE identity: bright, compact, search bar as the hero. */
.ws-pulse{--pulse-accent:#e11d48}
.ws-pulse .pulse-hero{padding:3.5rem 0 2.5rem;background:
  radial-gradient(700px 260px at 50% -20%,rgba(225,29,72,.14),transparent 60%)}
.ws-pulse-search{display:flex;gap:.5rem;align-items:center;max-width:38rem;margin:1.25rem auto 0;
  border:2px solid rgba(148,163,184,.4);border-radius:999px;padding:.4rem .4rem .4rem 1rem;background:var(--surface,#fff)}
.ws-pulse-search input{flex:1;border:0;outline:0;background:transparent;font-size:1rem;min-width:0}
.ws-pulse .pulse-chips{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin-top:1rem}
.ws-pulse .pulse-chip{border:1px solid rgba(148,163,184,.45);border-radius:999px;padding:.3rem .8rem;font-size:.85rem;text-decoration:none}
.ws-pulse .pulse-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))}
.ws-pulse .pulse-quick{display:grid;gap:.75rem;max-width:34rem}
@media(max-width:560px){
  .ws-pulse .pulse-hero{padding:2.25rem 0 1.5rem}
  .ws-pulse-search{flex-wrap:wrap;border-radius:1rem;padding:.6rem}
  .ws-pulse-search input{width:100%}
  .ws-pulse .pulse-grid{grid-template-columns:1fr}
}
</style>

<div class="ws-pulse">

<section class="pulse-hero" aria-label="Search the catalogue">
  <div class="container text-center">
    <span class="ms-eyebrow"><?=htmlspecialchars($hero_kicker)?></span>
    <h1 style="margin:.5rem 0"><?=htmlspecialchars($hero_title)?></h1>
    <p class="lede" style="max-width:34rem;margin:0 auto"><?=htmlspecialchars($hero_lede)?>
      <?php if ($catalogue_size > 0): ?>
        <?=number_format($catalogue_size)?> live service<?=$catalogue_size === 1 ? '' : 's'?>.
      <?php endif; ?>
    </p>

    <form class="ws-pulse-search" role="search" method="get" action="<?=site_url('services')?>">
      <span aria-hidden="true">🔍</span>
      <label class="sr-only" for="pulse-q">Search services</label>
      <input id="pulse-q" type="search" name="q" placeholder="Instagram followers, TikTok views, airtime…" aria-label="Search services">
      <button class="btn btn-danger" type="submit">Search</button>
    </form>

    <img src="<?=base_url('assets/images/home/hero-pulse.jpg')?>" width="1280" height="720" fetchpriority="high"
         alt="Searching the catalogue on a phone and confirming an order in two taps."
         style="width:100%;max-width:44rem;margin:1.5rem auto 0;border-radius:1rem;display:block">

    <?php if ($categories): ?>
      <div class="pulse-chips">
        <?php foreach (array_slice($categories, 0, 8) as $c): ?>
          <a class="pulse-chip" href="<?=site_url('services?category='.rawurlencode($c->slug))?>"><?=htmlspecialchars($c->name)?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="ws-section" id="trending">
  <div class="container">
    <h2 class="ws-section-title">Trending now</h2>
    <?php if ($showcase): ?>
      <div class="pulse-grid" style="margin-top:1.25rem">
        <?php foreach (array_slice($showcase, 0, 6) as $s): ?>
          <article class="card">
            <?php if (!empty($s->category_name)): ?><span class="badge badge-default"><?=htmlspecialchars($s->category_name)?></span><?php endif; ?>
            <h3 class="card-title mt-2"><?=htmlspecialchars($s->name)?></h3>
            <p class="ws-landing-rate"><?=marvy_money($s->rate)?> <span class="muted">/ 1,000</span></p>
            <a class="btn btn-primary btn-sm" href="<?=site_url('services/'.$s->slug)?>">View service</a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state card">
        <h3>The catalogue is being prepared</h3>
        <p>No services are published yet. Create an account and the live list will be waiting.</p>
        <a class="btn btn-primary btn-sm" href="<?=site_url('register')?>">Create your account</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($quick): ?>
<section class="ws-section ws-landing-muted" id="quick-order">
  <div class="container">
    <h2 class="ws-section-title">Quick order</h2>
    <p class="ws-section-lead">An estimate, instantly. The wallet is charged at the amount the server calculates when you submit.</p>
    <div class="pulse-quick" style="margin-top:1rem">
      <label class="field mb-0">
        <span class="label">Service</span>
        <select class="select" id="pulse-service">
          <?php foreach ($quick as $i => $q): ?>
            <option value="<?=$i?>" data-rate="<?=htmlspecialchars($q['rate'])?>" data-slug="<?=htmlspecialchars($q['slug'])?>">
              <?=htmlspecialchars($q['name'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field mb-0">
        <span class="label">Quantity</span>
        <input class="input" id="pulse-qty" type="number" min="1" step="1" value="1000">
      </label>
      <p class="mb-0">Estimated total: <strong id="pulse-total">—</strong></p>
      <a class="btn btn-danger" id="pulse-go" href="<?=site_url($cu ? 'dashboard/new-order' : 'register')?>"><?=$cu ? 'Order this' : htmlspecialchars($cta_primary)?></a>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="ws-section" id="faq">
  <div class="container" style="max-width:46rem">
    <h2 class="ws-section-title text-center">Questions?</h2>
    <div class="stack ws-faq mt-6">
      <?php foreach (array_slice($faqs, 0, 6) as $f): ?>
        <details class="accordion-item">
          <summary><?=htmlspecialchars($f->question)?></summary>
          <div class="accordion-body"><?=nl2br(htmlspecialchars($f->answer))?></div>
        </details>
      <?php endforeach; ?>
    </div>
    <p class="text-center muted mt-4">More answers on the <a href="<?=site_url('faq')?>">FAQ page</a>.</p>
  </div>
</section>

<section class="ws-section ms-cta" aria-label="Get started">
  <div class="container">
    <div class="ws-landing-cta text-center">
      <h2>Start ordering in minutes</h2>
      <p>Create an account, add funds and place your first order from the same screen.</p>
      <a class="btn btn-lg btn-danger" href="<?=site_url('register')?>">Create free account</a>
    </div>
  </div>
</section>

</div>

<script <?=csp_nonce_attr()?>>
(function(){
  var sel = document.getElementById('pulse-service');
  var qty = document.getElementById('pulse-qty');
  var out = document.getElementById('pulse-total');
  var go  = document.getElementById('pulse-go');
  if (!sel || !qty || !out) return;
  var sym = <?=json_encode($symbol)?>;
  var base = <?=json_encode(site_url($cu ? 'dashboard/new-order' : 'register'), JSON_UNESCAPED_SLASHES)?>;
  function recalc(){
    var opt = sel.options[sel.selectedIndex];
    var rate = parseFloat((opt && opt.dataset.rate) || '0');
    var q = parseInt(qty.value || '0', 10) || 0;
    out.textContent = sym + ((rate / 1000) * q).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (go && opt && opt.dataset.slug) go.href = base;
  }
  sel.addEventListener('change', recalc);
  qty.addEventListener('input', recalc);
  recalc();
})();
</script>
