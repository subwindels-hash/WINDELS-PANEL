<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Admin → Website content → Pages → edit one page.
 *
 * The body is HTML. It is sanitised server-side on save (tag allowlist, event
 * handlers stripped, javascript:/data: URLs rejected), so what an operator
 * types here cannot introduce script into the public site.
 */
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};

$title = $override->title ?? $page_label;
$body  = $override->body_html ?? '';
$meta  = $override->meta_description ?? '';
$published = $override ? (int)$override->is_published === 1 : true;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-xs muted" href="<?=site_url('admin/pages')?>">← Website pages</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600"><?=htmlspecialchars($page_label)?></h2>
    <p class="muted text-sm">
      <?php if ($override): ?>
        You are editing a custom version of this page.
      <?php else: ?>
        This page currently shows the text bundled with the panel. Saving here replaces it.
      <?php endif; ?>
    </p>
  </div>
</div>

<form method="post" action="<?=site_url('admin/pages/'.$page_key.'/save')?>" class="card">
  <?=$csrf()?>

  <label class="field mb-3">
    <span class="label">Page title</span>
    <input class="input" type="text" name="title" required maxlength="160"
           value="<?=htmlspecialchars($title)?>">
  </label>

  <label class="field mb-3">
    <span class="label">Meta description <span class="muted">(optional)</span></span>
    <input class="input" type="text" name="meta_description" maxlength="320"
           value="<?=htmlspecialchars($meta)?>"
           placeholder="Shown by search engines under the page title">
  </label>

  <label class="field mb-3">
    <span class="label">Page content</span>
    <textarea class="input mono" name="body_html" rows="24"
              spellcheck="false" style="line-height:1.6"><?=htmlspecialchars($body)?></textarea>
    <span class="hint">
      HTML is allowed: headings, paragraphs, lists, links, tables, bold and italic. Scripts, iframes,
      inline event handlers and <code>javascript:</code> links are removed automatically when you save.
    </span>
  </label>

  <label class="row mb-4" style="gap:.5rem;align-items:center">
    <input type="checkbox" name="is_published" value="1" <?=$published ? 'checked' : ''?>>
    <span>Publish this version <span class="muted">(unchecked keeps the bundled text live)</span></span>
  </label>

  <div class="row" style="gap:.5rem;flex-wrap:wrap">
    <button class="btn btn-primary" type="submit">Save changes</button>
    <a class="btn btn-secondary" href="<?=site_url($page_key === 'about' ? 'about' : $page_key)?>"
       target="_blank" rel="noopener">View page</a>
  </div>
</form>

<?php if ($override): ?>
<form method="post" action="<?=site_url('admin/pages/'.$page_key.'/reset')?>" class="card mt-4"
      onsubmit="return confirm('Remove your custom version and restore the text that ships with the panel?')">
  <?=$csrf()?>
  <h3 style="font-size:1rem;font-weight:600" class="mb-1">Reset to default</h3>
  <p class="muted text-xs mb-3">
    Deletes your custom version. The page keeps working — it goes back to the text bundled with the
    panel rather than going blank.
  </p>
  <button class="btn btn-secondary btn-sm" type="submit">Reset to default</button>
</form>
<?php endif; ?>
