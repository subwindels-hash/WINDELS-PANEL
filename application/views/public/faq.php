<?php defined('BASEPATH') OR exit('No direct script access allowed');
$faqs = $faqs ?? array();
$cats = $categories ?? array();
$by_cat = array();
foreach ($faqs as $f) { $by_cat[$f->category ?: 'General'][] = $f; }
?>
<section class="py-12">
  <div class="container" style="max-width:900px">
    <header class="text-center mb-8">
      <h1>Frequently asked questions</h1>
      <p class="muted">Everything about ordering, payments, refills and the API.</p>
    </header>

    <?php if (empty($faqs)): ?>
      <div class="card muted text-center">No FAQs published yet.</div>
    <?php else: foreach ($by_cat as $cat => $items): ?>
      <h2 class="h3" style="font-size:1.15rem;font-weight:600;margin:1.5rem 0 .75rem"><?=htmlspecialchars($cat)?></h2>
      <div class="stack" style="gap:.5rem">
        <?php foreach ($items as $f): ?>
        <details class="ws-faq">
          <summary><?=htmlspecialchars($f->question)?></summary>
          <div><?=nl2br(htmlspecialchars($f->answer))?></div>
        </details>
        <?php endforeach; ?>
      </div>
    <?php endforeach; endif; ?>

    <div class="card text-center mt-8">
      <p class="muted">Still have a question?</p>
      <?php if ($this->auth && $this->auth->check()): ?>
        <a class="btn btn-primary mt-2" href="<?=site_url('dashboard/tickets')?>">Open a ticket</a>
      <?php else: ?>
        <a class="btn btn-primary mt-2" href="<?=site_url('login')?>">Log in to contact support</a>
      <?php endif; ?>
    </div>
  </div>
</section>
<style>
.ws-faq{background:#fff;border:1px solid var(--slate-200,#e2e8f0);border-radius:.75rem;padding:1rem 1.25rem}
.ws-faq summary{cursor:pointer;font-weight:600;list-style:none}
.ws-faq summary::-webkit-details-marker{display:none}
.ws-faq summary::after{content:'+';float:right;color:var(--brand-600,#4f46e5);font-weight:700}
.ws-faq[open] summary::after{content:'−'}
.ws-faq>div{margin-top:.75rem;color:var(--slate-600,#475569)}
</style>
