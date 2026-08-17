<?php defined('BASEPATH') OR exit('No direct script access allowed');
/** PULSE — bright marketplace: prominent search, category chips, rose accent. */
?>
<section class="py-10">
  <div class="container" style="max-width:900px;text-align:center">
    <span class="badge badge-danger">Marketplace</span>
    <h1 class="mt-3">Find the right service.<br>Place your order.<br><span class="gradient-text">Track everything.</span></h1>
    <form method="get" action="<?=site_url('services')?>" class="ws-pulse-search" role="search">
      <label class="sr-only" for="ws-q">Search services</label>
      <input id="ws-q" name="q" type="search" placeholder="Try “Instagram followers”…"
             class="input" style="padding:.9rem 1rem;font-size:1rem;border-radius:9999px 0 0 9999px">
      <button class="btn btn-danger btn-lg" style="border-radius:0 9999px 9999px 0">Search</button>
    </form>
    <div class="row" style="justify-content:center;margin-top:1rem">
      <?php foreach (array('Instagram','TikTok','YouTube','Twitter','Telegram','Facebook') as $chip): ?>
        <a class="badge badge-default" style="padding:.4rem .8rem;font-size:.8rem" href="<?=site_url('services?platform='.urlencode($chip))?>"><?=htmlspecialchars($chip)?></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-8">
  <div class="container" style="max-width:1080px">
    <div class="grid grid-4">
      <?php foreach (array(
        array('2,000+','Services'),
        array('0–5 min','Avg start'),
        array('$5','Min deposit'),
        array('24/7','Support'),
      ) as $s): ?>
      <div class="card text-center">
        <div style="font-family:var(--font-display);font-size:1.6rem;color:var(--danger-600)"><?=htmlspecialchars($s[0])?></div>
        <div class="muted"><?=htmlspecialchars($s[1])?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.ws-pulse-search{display:flex;max-width:640px;margin:1.5rem auto 0;border:1px solid var(--slate-300);
  border-radius:9999px;box-shadow:var(--shadow-card);background:#fff;overflow:hidden}
.ws-pulse-search .input{border:0;box-shadow:none}
.ws-pulse-search .input:focus{box-shadow:none}
@media(max-width:560px){
  .ws-pulse-search{flex-direction:column;border-radius:var(--radius-lg);overflow:hidden}
  .ws-pulse-search .input,.ws-pulse-search .btn{border-radius:0!important;width:100%}
}
</style>
