<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('purpose' => $filters['purpose'] ?? null, 'q' => $filters['search'] ?? null, 'page' => $page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};
$human = function ($bytes) {
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes.' B';
    if ($bytes < 1048576) return round($bytes / 1024).' KB';
    return round($bytes / 1048576, 1).' MB';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Media library</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> file<?=$total == 1 ? '' : 's'?></p>
  </div>
  <form method="get" action="<?=site_url('admin/media')?>" class="row" style="gap:.35rem">
    <select class="input" name="purpose" aria-label="Filter by purpose">
      <option value="">All purposes</option>
      <?php foreach ($purposes as $p): ?>
        <option value="<?=htmlspecialchars($p)?>" <?=($filters['purpose'] ?? '') === $p ? 'selected' : ''?>>
          <?=htmlspecialchars($p)?>
        </option>
      <?php endforeach; ?>
    </select>
    <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
           placeholder="File name" aria-label="Search media" style="min-width:11rem">
    <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
  </form>
</div>

<?php $this->load->view('admin/system/_tabs', array('tabs'=>$tabs,'area'=>$area)); ?>

<div class="card mb-4">
  <h3 style="font-size:1rem;font-weight:600" class="mb-3">Upload</h3>
  <form method="post" action="<?=site_url('admin/media/upload')?>" enctype="multipart/form-data"
        class="row" style="gap:.5rem;flex-wrap:wrap;align-items:flex-end">
    <?=$csrf()?>
    <div class="field" style="flex:1;min-width:15rem">
      <label class="label" for="m-file">File</label>
      <input class="input" type="file" id="m-file" name="file" required
             accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
    </div>
    <div class="field" style="flex:1;min-width:10rem">
      <label class="label" for="m-purpose">Purpose</label>
      <select class="select" id="m-purpose" name="purpose">
        <?php foreach ($purposes as $p): ?>
          <option value="<?=htmlspecialchars($p)?>"><?=htmlspecialchars($p)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Upload</button>
  </form>
  <p class="muted text-xs" style="margin:.5rem 0 0">
    JPEG, PNG, GIF, WebP and PDF, up to <?=round($max_bytes / 1048576, 1)?> MB.
    Files are stored under a name the panel generates and served as data, never executed.
  </p>
</div>

<div class="card">
  <?php if (empty($files)): ?>
    <p class="muted">Nothing uploaded yet.</p>
  <?php else: ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.75rem">
      <?php foreach ($files as $f): ?>
        <div class="card" style="padding:.5rem">
          <?php if ($f->mime_type !== 'application/pdf'): ?>
            <img src="<?=htmlspecialchars($f->url)?>" alt="<?=htmlspecialchars($f->file_name)?>"
                 loading="lazy"
                 style="width:100%;height:7rem;object-fit:cover;border-radius:.35rem;background:#f1f5f9">
          <?php else: ?>
            <div style="width:100%;height:7rem;display:flex;align-items:center;justify-content:center;
                        background:#f1f5f9;border-radius:.35rem" class="muted text-xs">PDF</div>
          <?php endif; ?>

          <div class="text-xs mt-2" style="word-break:break-all">
            <?=htmlspecialchars(mb_strimwidth($f->file_name, 0, 28, '…'))?>
          </div>
          <div class="muted text-xs">
            <?=htmlspecialchars((string)$f->purpose)?> · <?=$human($f->size)?>
          </div>

          <div class="row mt-2" style="gap:.25rem;justify-content:space-between;align-items:center">
            <a class="btn btn-ghost btn-sm" href="<?=htmlspecialchars($f->url)?>"
               target="_blank" rel="noopener noreferrer">Open</a>
            <form method="post" action="<?=site_url('admin/media/'.$f->public_id.'/delete')?>"
                  style="display:inline">
              <?=$csrf()?>
              <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="row justify-between mt-4" style="align-items:center">
  <span class="muted text-sm">Page <?=number_format($page)?> of <?=number_format($total_pages)?></span>
  <div class="row" style="gap:.35rem">
    <?php if ($page > 1): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/media'.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/media'.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
