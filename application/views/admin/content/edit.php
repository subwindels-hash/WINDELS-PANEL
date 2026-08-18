<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

$is_new = empty($row);
$handle = $is_new ? null : ($domain === 'faq' ? $row->id : $row->public_id);
$action = $is_new ? site_url('admin/'.$domain.'/create')
                  : site_url('admin/'.$domain.'/'.$handle.'/update');
$v = function ($field, $default = '') use ($row) {
    return htmlspecialchars((string)($row->$field ?? $default));
};
// Datetime-local wants "Y-m-dTH:i"; the column stores a UTC datetime.
$dt = function ($field) use ($row) {
    $raw = $row->$field ?? null;
    return $raw ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($raw))) : '';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-xs muted" href="<?=site_url('admin/'.$domain)?>">← <?=htmlspecialchars(ContentService::label($domain))?></a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      <?=$is_new ? 'New' : 'Edit'?> <?=htmlspecialchars(strtolower(rtrim(ContentService::label($domain), 's')))?>
    </h2>
  </div>
</div>

<form method="post" action="<?=$action?>">
  <?=$csrf()?>

  <div class="card mb-4">
    <?php if ($domain === 'blog'): ?>
      <div class="field" style="margin-bottom:1rem">
        <label class="label" for="c-title">Title</label>
        <input class="input" id="c-title" name="title" required maxlength="255" value="<?=$v('title')?>">
      </div>
      <div class="row" style="gap:.75rem;flex-wrap:wrap">
        <div class="field" style="flex:1;min-width:16rem">
          <label class="label" for="c-slug">Slug</label>
          <input class="input mono" id="c-slug" name="slug" value="<?=$v('slug')?>"
                 placeholder="left blank, derived from the title">
          <p class="muted text-xs" style="margin:.25rem 0 0">The public URL: /blog/&lt;slug&gt;</p>
        </div>
        <div class="field" style="flex:1;min-width:12rem">
          <label class="label" for="c-cat">Category</label>
          <select class="select" id="c-cat" name="category_id">
            <option value="">Uncategorised</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?=(int)$c->id?>" <?=(int)($row->category_id ?? 0) === (int)$c->id ? 'selected' : ''?>>
                <?=htmlspecialchars($c->name)?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field" style="margin-bottom:1rem">
        <label class="label" for="c-excerpt">Excerpt</label>
        <textarea class="input" id="c-excerpt" name="excerpt" rows="2" maxlength="500"><?=$v('excerpt')?></textarea>
        <p class="muted text-xs" style="margin:.25rem 0 0">Shown on the listing page. Plain text.</p>
      </div>
      <div class="field" style="margin-bottom:1rem">
        <label class="label" for="c-content">Body</label>
        <textarea class="input mono" id="c-content" name="content" rows="16" required><?=$v('content')?></textarea>
        <p class="muted text-xs" style="margin:.25rem 0 0">
          Basic HTML is allowed. Scripts, iframes, embedded objects and event handlers are removed on save.
        </p>
      </div>
      <div class="row" style="gap:.75rem;flex-wrap:wrap">
        <div class="field" style="flex:1;min-width:10rem">
          <label class="label" for="c-status">Status</label>
          <select class="select" id="c-status" name="status">
            <?php foreach (ContentService::BLOG_STATUSES as $s): ?>
              <option value="<?=htmlspecialchars($s)?>" <?=($row->status ?? 'DRAFT') === $s ? 'selected' : ''?>>
                <?=htmlspecialchars($s)?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1;min-width:12rem">
          <label class="label" for="c-pub">Publish at</label>
          <input class="input" type="datetime-local" id="c-pub" name="published_at" value="<?=$dt('published_at')?>">
          <p class="muted text-xs" style="margin:.25rem 0 0">UTC. Leave blank to publish now.</p>
        </div>
        <div class="field" style="flex:1;min-width:14rem">
          <label class="label" for="c-img">Featured image URL</label>
          <input class="input" id="c-img" name="featured_image" maxlength="512" value="<?=$v('featured_image')?>">
        </div>
      </div>

    <?php elseif ($domain === 'faq'): ?>
      <div class="field" style="margin-bottom:1rem">
        <label class="label" for="c-q">Question</label>
        <input class="input" id="c-q" name="question" required maxlength="1000" value="<?=$v('question')?>">
      </div>
      <div class="field" style="margin-bottom:1rem">
        <label class="label" for="c-a">Answer</label>
        <textarea class="input" id="c-a" name="answer" rows="8" required><?=$v('answer')?></textarea>
        <p class="muted text-xs" style="margin:.25rem 0 0">Basic HTML is allowed; scripts are removed on save.</p>
      </div>
      <div class="row" style="gap:.75rem;flex-wrap:wrap">
        <div class="field" style="flex:1;min-width:12rem">
          <label class="label" for="c-faqcat">Category</label>
          <input class="input" id="c-faqcat" name="category" maxlength="64" value="<?=$v('category')?>"
                 placeholder="e.g. Payments" list="faq-cats">
          <datalist id="faq-cats">
            <?php foreach ($categories as $c): ?>
              <option value="<?=htmlspecialchars($c)?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="field" style="flex:1;min-width:8rem">
          <label class="label" for="c-sort">Sort order</label>
          <input class="input mono" type="number" id="c-sort" name="sorting" value="<?=(int)($row->sorting ?? 0)?>">
        </div>
        <div class="field" style="flex:1;min-width:10rem;align-self:flex-end">
          <label class="row" style="gap:.5rem;align-items:center">
            <input type="checkbox" name="is_active" value="1" <?=($is_new || !empty($row->is_active)) ? 'checked' : ''?>>
            <span class="label" style="margin:0">Visible to customers</span>
          </label>
        </div>
      </div>

    <?php else: ?>
      <div class="field" style="margin-bottom:1rem">
        <label class="label" for="c-title">Title</label>
        <input class="input" id="c-title" name="title" required maxlength="255" value="<?=$v('title')?>">
      </div>
      <div class="field" style="margin-bottom:1rem">
        <label class="label" for="c-content">Message</label>
        <textarea class="input" id="c-content" name="content" rows="5" required><?=$v('content')?></textarea>
      </div>
      <div class="row" style="gap:.75rem;flex-wrap:wrap">
        <div class="field" style="flex:1;min-width:10rem">
          <label class="label" for="c-sev">Severity</label>
          <select class="select" id="c-sev" name="severity">
            <?php foreach (ContentService::SEVERITIES as $s): ?>
              <option value="<?=htmlspecialchars($s)?>" <?=($row->severity ?? 'INFO') === $s ? 'selected' : ''?>>
                <?=htmlspecialchars($s)?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1;min-width:10rem">
          <label class="label" for="c-aud">Audience</label>
          <select class="select" id="c-aud" name="audience">
            <?php foreach (ContentService::AUDIENCES as $a): ?>
              <option value="<?=htmlspecialchars($a)?>" <?=($row->audience ?? 'all') === $a ? 'selected' : ''?>>
                <?=htmlspecialchars($a)?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1;min-width:12rem">
          <label class="label" for="c-start">Starts at</label>
          <input class="input" type="datetime-local" id="c-start" name="starts_at" value="<?=$dt('starts_at')?>">
        </div>
        <div class="field" style="flex:1;min-width:12rem">
          <label class="label" for="c-end">Ends at</label>
          <input class="input" type="datetime-local" id="c-end" name="ends_at" value="<?=$dt('ends_at')?>">
        </div>
        <div class="field" style="flex:1;min-width:10rem;align-self:flex-end">
          <label class="row" style="gap:.5rem;align-items:center">
            <input type="checkbox" name="is_active" value="1" <?=($is_new || !empty($row->is_active)) ? 'checked' : ''?>>
            <span class="label" style="margin:0">Active</span>
          </label>
        </div>
      </div>
      <p class="muted text-xs" style="margin:.5rem 0 0">
        Times are UTC. Leave both blank to show the banner from now until you switch it off.
      </p>
    <?php endif; ?>
  </div>

  <div class="row justify-between" style="align-items:center;flex-wrap:wrap;gap:.5rem">
    <div>
      <?php if (!$is_new): ?>
        <button class="btn btn-ghost btn-sm" type="button"
                onclick="document.getElementById('ws-del').showModal()">Delete</button>
      <?php endif; ?>
    </div>
    <button class="btn btn-primary" type="submit"><?=$is_new ? 'Create' : 'Save changes'?></button>
  </div>
</form>

<?php if (!$is_new): ?>
<dialog id="ws-del" class="ws-dialog">
  <form method="post" action="<?=site_url('admin/'.$domain.'/'.$handle.'/delete')?>">
    <?=$csrf()?>
    <h3 style="font-size:1rem;font-weight:600" class="mb-2">Delete this item?</h3>
    <p class="muted text-sm mb-3">
      This cannot be undone.
      <?php if ($domain === 'blog'): ?>
        A published post is archived instead, so links pointing at it keep working.
      <?php endif; ?>
    </p>
    <div class="row" style="gap:.5rem;justify-content:flex-end">
      <button class="btn btn-ghost btn-sm" type="button"
              onclick="document.getElementById('ws-del').close()">Cancel</button>
      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
