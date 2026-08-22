<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<section class="py-12">
  <div class="container" style="max-width:1100px">
    <header class="text-center mb-8">
      <h1>Blog</h1>
      <p class="muted">Guides, product updates, and reseller tips.</p>
    </header>

    <?php if (!empty($categories)): ?>
    <nav class="row justify-center mb-8" style="gap:.5rem;flex-wrap:wrap">
      <a class="badge <?=$active_category?'badge-default':'badge-success'?>" href="<?=site_url('blog')?>">All</a>
      <?php foreach ($categories as $c): if (!(int)$c->post_count) continue; ?>
        <a class="badge <?=$active_category===$c->slug?'badge-success':'badge-default'?>"
           href="<?=site_url('blog?category='.urlencode($c->slug))?>"><?=htmlspecialchars($c->name)?> (<?=(int)$c->post_count?>)</a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
      <div class="empty-state card">
        <h2>No posts published yet</h2>
        <p>Staff publish guides and product notes from the admin content tools. In the meantime the FAQ covers how the panel works.</p>
        <a class="btn btn-secondary" href="<?=site_url('faq')?>">Read the FAQ</a>
      </div>
    <?php else: ?>
    <div class="grid grid-3" style="gap:1.25rem">
      <?php foreach ($posts as $p): ?>
      <article class="card card-hover">
        <?php if (!empty($p->featured_image)): ?>
          <img src="<?=htmlspecialchars($p->featured_image)?>" alt="" loading="lazy" style="width:100%;height:180px;object-fit:cover;border-radius:.75rem .75rem 0 0;margin:-1.25rem -1.25rem 1rem">
        <?php endif; ?>
        <h2 style="font-size:1.15rem;margin:0 0 .5rem">
          <a class="text-slate-900 hover:text-brand-700" href="<?=site_url('blog/'.$p->slug)?>"><?=htmlspecialchars($p->title)?></a>
        </h2>
        <p class="muted text-sm"><?=htmlspecialchars($p->excerpt ?: mb_strimwidth(strip_tags($p->content),0,120,'…'))?></p>
        <div class="row justify-between mt-3 text-xs muted">
          <span><?=date('M j, Y', strtotime($p->published_at))?></span>
          <span><?=(int)$p->views?> views</span>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav class="row justify-between mt-8">
      <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="<?=site_url('blog?'.http_build_query(array_filter(array('category'=>$active_category,'page'=>max(1,$page-1)))))?>">← Newer</a>
      <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
      <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>" href="<?=site_url('blog?'.http_build_query(array_filter(array('category'=>$active_category,'page'=>min($total_pages,$page+1)))))?>">Older →</a>
    </nav>
    <?php endif; endif; ?>
  </div>
</section>
