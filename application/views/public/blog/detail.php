<?php defined('BASEPATH') OR exit('No direct script access allowed');
$p = $post;
?>
<article class="py-12">
  <div class="container" style="max-width:760px">
    <a class="text-sm muted" href="<?=site_url('blog')?>">← Back to blog</a>
    <h1 class="mt-3"><?=htmlspecialchars($p->title)?></h1>
    <p class="muted"><?=date('F j, Y', strtotime($p->published_at))?> · <?=(int)$p->views?> views</p>
    <?php if (!empty($p->featured_image)): ?>
      <img src="<?=htmlspecialchars($p->featured_image)?>" alt="" style="width:100%;border-radius:1rem;margin:1.5rem 0">
    <?php endif; ?>
    <?php if (!empty($p->excerpt)): ?>
      <p class="ws-lede"><?=htmlspecialchars($p->excerpt)?></p>
    <?php endif; ?>
    <div class="ws-prose">
      <?= $p->content /* stored as trusted HTML by staff */ ?>
    </div>
  </div>
</article>

<?php if (!empty($related)): ?>
<section class="pb-12">
  <div class="container" style="max-width:1100px">
    <h2 style="font-size:1.25rem;font-weight:600">Related posts</h2>
    <div class="grid grid-3 mt-4" style="gap:1rem">
      <?php foreach (array_slice($related,0,3) as $r): ?>
      <a class="card card-hover" href="<?=site_url('blog/'.$r->slug)?>">
        <strong><?=htmlspecialchars($r->title)?></strong>
        <p class="muted text-sm mt-1"><?=htmlspecialchars($r->excerpt ?: '')?></p>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
