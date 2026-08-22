<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$faqs = $faqs ?? array();
$by_cat = array();
foreach ($faqs as $f) {
    $cat = is_object($f) ? ($f->category ?: 'General') : ($f['category'] ?: 'General');
    $q = is_object($f) ? $f->question : $f['q'];
    $a = is_object($f) ? $f->answer : $f['a'];
    $by_cat[$cat][] = array('q' => $q, 'a' => $a);
}
?>
<section class="ws-page-hero">
  <div class="container" style="max-width:1100px">
    <div class="ws-hero-split">
      <div>
    <p class="ws-kicker">Help</p>
    <h1>Frequently asked questions</h1>
    <p class="ws-lede">Answers about the actual panel: accounts, wallet billing, SMM and VTU, security, the API and the on-site assistant.</p>
    <div class="ws-searchwrap mt-4" style="position:relative;max-width:28rem">
      <label class="sr-only" for="ws-faq-search">Search questions</label>
      <input class="input" id="ws-faq-search" type="search" placeholder="Search questions…">
    </div>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container" style="max-width:800px">
    <?php if (empty($faqs)): ?>
      <div class="empty-state card">
        <h2>No FAQs published yet</h2>
        <p>Staff can add questions from the admin content tools. Until then, use the contact form.</p>
        <a class="btn btn-primary" href="<?=site_url('contact')?>">Contact</a>
      </div>
    <?php else: ?>
      <div id="ws-faq-empty" class="empty-state card" hidden>
        <h2>No matching questions</h2>
        <p>Try a different phrase, or contact support.</p>
      </div>
      <?php foreach ($by_cat as $cat => $items): ?>
        <section data-faq-category class="mb-6">
          <h2 class="h3" style="font-size:1.15rem"><?=htmlspecialchars($cat)?></h2>
          <div class="stack" style="gap:.5rem">
            <?php foreach ($items as $item): ?>
            <details class="accordion-item" data-faq-item
                     data-faq-text="<?=htmlspecialchars(strtolower($item['q'].' '.$item['a'].' '.$cat))?>">
              <summary><?=htmlspecialchars($item['q'])?></summary>
              <div class="accordion-body"><?=nl2br(htmlspecialchars($item['a']))?></div>
            </details>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="card text-center mt-8">
      <p class="muted">Still have a question?</p>
      <?php if (!empty($current_user)): ?>
        <a class="btn btn-primary mt-2" href="<?=site_url('dashboard/tickets')?>">Open a ticket</a>
      <?php else: ?>
        <a class="btn btn-primary mt-2" href="<?=site_url('contact')?>">Contact support</a>
        <p class="hint">Signed-in customers can also open a ticket from the dashboard.</p>
      <?php endif; ?>
    </div>
  </div>
</section>
