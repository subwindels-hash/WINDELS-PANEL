<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * PULSE — bright marketplace homepage (Session 05).
 * Search-first, bold rose/amber accent, category pills, mobile-first tap targets.
 */
$chips = array('Instagram','TikTok','YouTube','X','Facebook','Telegram');
// Live categories and services from Home::index(). These sections used to be
// literal arrays: invented service names, invented star ratings, and prices
// printed with a "$" on a panel whose base currency is NGN.
$categories = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : array();
$trending = isset($data['showcase']) && is_array($data['showcase']) ? $data['showcase'] : array();
$reviews = array(
  array('Search first','Find a service, paste a public link, pay from the wallet. No fake review scores.'),
  array('Refill when marked','Services that support refill expose the action on the order — it is not a slogan.'),
  array('Human support','Tickets and the contact form reach staff. The assistant only explains the site.'),
);
$faqs = array(
  array('How do I place an order?','Search for a service, paste your link, choose a quantity, and pay from your wallet. Most orders start automatically.'),
  array('Can I get a refill?','Services marked “refill” include a refill window from the order detail page.'),
  array('What payment methods are supported?','Add funds through whichever methods the operator has enabled. Manual bank transfer ships on; card gateways stay off until credentials are configured. The default minimum deposit is ₦500.'),
);
?>
<section class="py-10 ws-pulse-hero">
  <div class="container" style="max-width:900px;text-align:center">
    <span class="badge badge-danger">⚡ Fast · Reliable · Refill-ready</span>
    <h1 class="mt-3">Find the right service.<br>Place your order.<br><span class="gradient-text">Track everything.</span></h1>
    <form method="get" action="<?=site_url('services')?>" class="ws-pulse-search" role="search">
      <label class="sr-only" for="ws-q">Search services</label>
      <span class="ws-search-icon" aria-hidden="true">🔍</span>
      <input id="ws-q" name="q" type="search" placeholder="Try “Instagram followers”…"
             class="input" autocomplete="off">
      <button class="btn btn-danger btn-lg" type="submit">Search</button>
    </form>
    <div class="row" style="justify-content:center;margin-top:1rem">
      <?php foreach ($chips as $chip): ?>
        <a class="ws-chip" href="<?=site_url('services?platform='.urlencode($chip))?>"><?=htmlspecialchars($chip)?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($trending): ?>
    <p class="muted mt-2">Popular:
      <?php foreach (array_slice($trending, 0, 2) as $i => $svc): ?>
        <?=$i ? ' · ' : ''?><a href="<?=site_url('services/'.$svc->slug)?>"><?=htmlspecialchars($svc->name)?></a>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>
  </div>
</section>

<?php if ($trending): ?>
<section class="ws-pulse-showcase" aria-hidden="true">
  <div class="container" style="max-width:1080px">
    <?php // Decorative supporting image. Sits between the search hero and the
          // category rail rather than behind the centred hero text, where a
          // photograph would fight the search field for contrast. ?>
    <img src="<?=base_url('assets/images/home/hero-pulse.jpg')?>" alt=""
         width="1200" height="675" loading="lazy" decoding="async">
  </div>
</section>
<?php endif; ?>

<section class="py-8">
  <div class="container" style="max-width:1080px">
    <div class="ws-cat-scroll" aria-label="Categories">
      <?php foreach ($categories as $c): ?>
      <a class="ws-cat-pill" href="<?=site_url('services?category='.rawurlencode($c->slug))?>">
        <?php if (!empty($c->icon)): ?>
          <span class="ws-cat-emoji" aria-hidden="true">
            <?php $this->load->view('partials/icon', array('name' => $c->icon, 'class' => 'w-5 h-5')); ?>
          </span>
        <?php endif; ?>
        <span class="ws-cat-name"><?=htmlspecialchars($c->name)?></span>
        <span class="ws-cat-count"><?=number_format((int)$c->service_count)?> service<?=(int)$c->service_count === 1 ? '' : 's'?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-8">
  <div class="container" style="max-width:1080px">
    <div class="row justify-between" style="align-items:end">
      <h2 class="mb-0">🔥 Trending now</h2>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('services')?>">See all →</a>
    </div>
    <?php if ($trending): ?>
    <div class="grid grid-4 mt-4">
      <?php foreach ($trending as $svc): ?>
      <article class="card card-hover">
        <div class="row justify-between">
          <?php if (!empty($svc->category_name)): ?>
            <span class="badge badge-default"><?=htmlspecialchars($svc->category_name)?></span>
          <?php endif; ?>
        </div>
        <h3 class="card-title mt-2"><?=htmlspecialchars($svc->name)?></h3>
        <?php if (!empty($svc->average_time)): ?>
          <p class="muted" style="font-size:.85rem">Average start: <?=htmlspecialchars($svc->average_time)?></p>
        <?php endif; ?>
        <div class="row justify-between mt-2">
          <strong style="color:var(--danger-600);font-size:1.15rem"><?=marvy_money($svc->rate)?>
            <span class="muted" style="font-weight:400;font-size:.8rem">/ 1k</span></strong>
          <a class="btn btn-danger btn-sm" href="<?=site_url('services/'.$svc->slug)?>">View</a>
        </div>
        <p class="hint"><?=number_format((int)$svc->min_quantity)?> – <?=number_format((int)$svc->max_quantity)?> units</p>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="muted mt-4">
      No services have been published yet. This row lists the live catalogue, so it stays empty rather
      than advertising services that cannot be ordered.
    </p>
    <?php endif; ?>
  </div>
</section>

<section class="py-10" style="background:linear-gradient(180deg,var(--danger-50),transparent)">
  <div class="container" style="max-width:900px">
    <div class="ws-fastorder card">
      <div class="text-center">
        <h2>Quick order</h2>
        <p class="muted">Pick a service and a quantity to see what it costs, before you sign up.</p>
      </div>
      <?php if ($trending): ?>
      <?php // A real estimator over real services. It deliberately does not
            // pretend to place the order: ordering needs an account and a
            // funded wallet, so the button goes to registration and the form
            // never posts anywhere that would fail. ?>
      <div class="grid ws-fast-grid">
        <label class="field"><span class="label">Service</span>
          <select class="select" id="ws-qo-service">
            <?php foreach ($trending as $svc): ?>
            <option value="<?=htmlspecialchars($svc->public_id)?>"
                    data-rate="<?=htmlspecialchars($svc->rate)?>"
                    data-min="<?=(int)$svc->min_quantity?>"
                    data-max="<?=(int)$svc->max_quantity?>"
                    data-slug="<?=htmlspecialchars($svc->slug)?>">
              <?=htmlspecialchars($svc->name)?> — <?=marvy_money($svc->rate)?>/1k
            </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span class="label">Quantity</span>
          <input id="ws-qty" class="input" type="number" inputmode="numeric"
                 min="<?=(int)$trending[0]->min_quantity?>"
                 max="<?=(int)$trending[0]->max_quantity?>"
                 value="<?=(int)$trending[0]->min_quantity?>">
          <span class="hint" id="ws-qo-range"></span>
        </label>
      </div>
      <div class="row justify-between ws-fast-total">
        <div><span class="muted">Estimated total</span> <strong id="ws-total"></strong></div>
        <a class="btn btn-danger btn-lg" id="ws-qo-cta" href="<?=site_url('register')?>">Get started →</a>
      </div>
      <p class="hint text-center">
        An estimate from the published rate. Sign in to place the order — the exact charge is shown and
        frozen before you confirm.
      </p>
      <?php else: ?>
      <p class="muted text-center">The catalogue has no published services yet.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="py-10">
  <div class="container" style="max-width:1080px">
    <div class="text-center">
      <h2 class="mb-0">Built for placing orders, not collecting stars</h2>
      <p class="muted">Capabilities of the panel — not fabricated ratings.</p>
    </div>
    <div class="grid grid-3 mt-6">
      <?php foreach ($reviews as $r): ?>
      <figure class="card">
        <figcaption class="muted"><?=htmlspecialchars($r[0])?></figcaption>
        <blockquote><?=htmlspecialchars($r[1])?></blockquote>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-10" style="background:var(--slate-50)">
  <div class="container" style="max-width:820px">
    <h2 class="text-center">Questions?</h2>
    <div class="mt-6 stack">
      <?php foreach ($faqs as $f): ?>
      <details class="ws-faq-light">
        <summary><?=htmlspecialchars($f[0])?></summary>
        <p><?=htmlspecialchars($f[1])?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:900px">
    <div class="ws-cta-band">
      <h2>Start ordering in minutes</h2>
      <p>No subscription. Add funds, order, track — all in one place.</p>
      <a class="btn btn-lg" style="background:#fff;color:var(--danger-700)" href="<?=site_url('register')?>">Create free account</a>
    </div>
  </div>
</section>

<style>
.ws-pulse-hero{background:
  radial-gradient(700px 260px at 50% 0,rgba(244,63,94,.10),transparent 60%),
  radial-gradient(500px 200px at 90% 10%,rgba(245,158,11,.10),transparent 60%)}
.ws-pulse-search{display:flex;align-items:center;max-width:640px;margin:1.5rem auto 0;
  border:2px solid var(--slate-200);border-radius:9999px;box-shadow:var(--shadow-card);background:#fff;overflow:hidden;transition:border-color .15s,box-shadow .15s}
.ws-pulse-search:focus-within{border-color:var(--danger-500);box-shadow:0 0 0 4px rgba(244,63,94,.15)}
.ws-pulse-search .input{border:0;box-shadow:none;border-radius:0;padding:.95rem 1rem .95rem 2.6rem}
.ws-pulse-search .input:focus{box-shadow:none}
.ws-search-icon{position:absolute;margin-left:1rem;font-size:1.1rem;pointer-events:none}
.ws-chip{padding:.45rem .9rem;border-radius:9999px;background:#fff;border:1px solid var(--slate-200);
  color:var(--slate-700);font-weight:500;font-size:.85rem;transition:.15s}
.ws-chip:hover{border-color:var(--danger-400);color:var(--danger-700);text-decoration:none;transform:translateY(-1px)}
.ws-cat-scroll{display:flex;gap:.75rem;overflow-x:auto;padding-bottom:.5rem;scroll-snap-type:x mandatory}
.ws-cat-pill{flex:0 0 auto;scroll-snap-align:start;display:flex;align-items:center;gap:.6rem;
  padding:.75rem 1rem;background:#fff;border:1px solid var(--slate-200);border-radius:1rem;min-width:180px;transition:.15s}
.ws-cat-pill:hover{border-color:var(--danger-400);box-shadow:var(--shadow-card);text-decoration:none}
.ws-cat-emoji{font-size:1.4rem}.ws-cat-name{font-weight:600;color:var(--slate-900)}
.ws-cat-count{display:block;font-size:.75rem;color:var(--slate-500)}
.ws-stars{color:#f59e0b;font-size:.85rem;font-weight:600}
.ws-fastorder{border:2px solid var(--danger-100);box-shadow:0 20px 50px -25px rgba(244,63,94,.5)}
.ws-fast-grid{grid-template-columns:repeat(2,1fr);gap:1rem;margin-top:1.5rem}
.ws-fast-total{margin-top:1.25rem;padding-top:1rem;border-top:1px dashed var(--slate-200)}
#ws-total{font-size:1.5rem;color:var(--danger-700);font-family:var(--font-display)}
.ws-faq-light{background:#fff;border:1px solid var(--slate-200);border-radius:var(--radius);padding:1rem 1.25rem}
.ws-faq-light summary{cursor:pointer;font-weight:600;list-style:none}
.ws-faq-light summary::-webkit-details-marker{display:none}
.ws-faq-light summary::after{content:'+';float:right;color:var(--danger-600);font-weight:700}
.ws-faq-light[open] summary::after{content:'−'}
.ws-faq-light p{margin:.75rem 0 0;color:var(--slate-600)}
.ws-cta-band{text-align:center;background:linear-gradient(135deg,var(--danger-600),#f97316);color:#fff;border-radius:var(--radius-xl);padding:3rem 2rem}
.ws-cta-band h2{color:#fff}.ws-cta-band p{color:rgba(255,255,255,.9);margin-bottom:1.25rem}
@media(max-width:560px){
  .ws-pulse-search{flex-direction:column;border-radius:var(--radius-lg)}
  .ws-pulse-search .input{border-radius:0;width:100%;padding-left:2.6rem}
  .ws-pulse-search .btn{border-radius:0;width:100%}
  .ws-fast-grid{grid-template-columns:1fr}
}
/* Supporting hero band. Capped in height so it never pushes the category rail
   and trending services below the fold on a laptop. */
.ws-pulse-showcase{padding:.5rem 0 1.5rem}
.ws-pulse-showcase img{width:100%;height:clamp(180px,26vw,320px);object-fit:cover;
  border-radius:var(--radius-xl);box-shadow:var(--shadow-card)}
@media(max-width:640px){.ws-pulse-showcase img{height:150px}}
</style>
<script <?=csp_nonce_attr()?>>
// Quick-order estimate over the real catalogue. Rates, minimums and maximums
// come from the selected service's own row, so the figure shown here is the
// same arithmetic the dashboard performs — no invented pricing.
(function(){
  var sel=document.getElementById('ws-qo-service'),
      qty=document.getElementById('ws-qty'),
      total=document.getElementById('ws-total'),
      range=document.getElementById('ws-qo-range'),
      cta=document.getElementById('ws-qo-cta');
  if(!sel||!qty||!total) return;

  var sym=<?=json_encode(trim(str_replace(array('0','.',','), '', marvy_money(0))))?>;
  function fmt(v){return sym+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}

  function recalc(){
    var opt=sel.options[sel.selectedIndex];
    if(!opt) return;
    var rate=parseFloat(opt.getAttribute('data-rate'))||0,
        min=parseInt(opt.getAttribute('data-min'),10)||1,
        max=parseInt(opt.getAttribute('data-max'),10)||0;

    qty.min=min; if(max) qty.max=max;
    var q=parseInt(qty.value||'0',10)||0;
    if(range) range.textContent='Min '+min.toLocaleString('en-US')+(max?' · Max '+max.toLocaleString('en-US'):'');

    // Clamp only for the estimate; leave what the visitor typed in the box.
    var effective=Math.min(Math.max(q,min),max||q);
    total.textContent=fmt(effective*rate/1000);
  }

  sel.addEventListener('change',function(){
    var opt=sel.options[sel.selectedIndex];
    if(opt){
      qty.value=opt.getAttribute('data-min')||qty.value;
      if(cta) cta.setAttribute('href', <?=json_encode(site_url('services/'))?>+opt.getAttribute('data-slug'));
    }
    recalc();
  });
  qty.addEventListener('input',recalc);
  sel.dispatchEvent(new Event('change'));
})();
</script>
