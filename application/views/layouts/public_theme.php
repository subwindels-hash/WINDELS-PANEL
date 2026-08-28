<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Public shell — MarvySocials theme.
 *
 * Same contract as layouts/main.php: controllers pass `content_view` and
 * `data`; only the header/footer chrome differs. `partials/head` owns the
 * metadata, CSRF tags and stylesheets so this shell can never drift from the
 * rest of the site, and branding is resolved here rather than expected from
 * the caller (a controller that forgets `brand` must still render).
 */
$content   = $content_view ?? '';
$page_data = $data ?? array();

if (empty($brand) || !is_array($brand)) {
    $brand = array('brand_primary_color' => null, 'brand_logo_url' => null, 'brand_favicon_url' => null);
    try {
        $CI =& get_instance();
        $CI->load->model('Setting_model');
        foreach (array_keys($brand) as $__k) $brand[$__k] = $CI->Setting_model->get($__k);
    } catch (Throwable $e) { /* defaults stand */ }
}

$page_title = $page_title ?? ($title ?? ($page_data['title'] ?? null));
$page_desc  = $page_desc ?? ($page_description ?? ($page_data['meta_description'] ?? null));

$nav_links = array(
    array('services', 'Services'),
    array('shop', 'Shop'),
    array('pricing', 'Pricing'),
    array('api/docs', 'API'),
    array('faq', 'FAQ'),
);
// Public_Controller publishes `current_user` to every view (null for guests);
// the shell must not reach into the controller's protected $auth service.
$signed_in = !empty($current_user);
?>
<!doctype html>
<html lang="en">
<head>
<?php $this->load->view('partials/head', array_merge($page_data, array(
    'page_title'     => $page_title,
    'page_desc'      => $page_desc,
    'page_robots'    => $page_data['meta_robots'] ?? 'index,follow',
    'page_canonical' => $page_data['canonical'] ?? '',
    'page_og_type'   => $page_data['og_type'] ?? 'website',
    'page_og_image'  => $page_data['og_image'] ?? base_url('assets/images/home/hero.jpg'),
))); ?>
<link rel="stylesheet" href="<?=base_url('assets/css/marketing.css')?>">
<?php if (!empty($brand['brand_primary_color'])): ?>
<style><?=':root{--ws-primary:'.htmlspecialchars($brand['brand_primary_color']).'}'?></style>
<?php endif; ?>
</head>
<body class="min-h-screen bg-surface text-slate-900 antialiased ws-public-shell">
<a class="ws-skip" href="#main">Skip to content</a>
<?php $this->load->view('partials/announcement'); ?>

<?php
// One menu for the whole site. This shell used to carry its own hand-written
// header — a second navigation with a different set of links, a hard-coded
// brand name and no mobile menu — so half the public pages had one menu and
// half had another. partials/navbar is the only public navigation now, which
// is also why the navy treatment only has to be written once.
$this->load->view('partials/navbar');
?>

<main id="main" class="ws-main" tabindex="-1">
  <?php if ($content !== '' && is_file(VIEWPATH.$content.'.php')): ?>
    <?php
      // Pass the whole layout scope (incl. `$data`) so content views that read
      // `$data[...]` work, while still exposing the individual keys. A missing
      // or empty view falls through to the empty-state instead of 500-ing.
      $this->load->view($content, array_diff_key(get_defined_vars(), array_flip(array('content', 'page_data'))));
    ?>
  <?php else: ?>
    <div class="container ws-section-sm">
      <div class="empty-state card text-center py-12">
        <h2 class="text-2xl font-bold text-purple-400 mb-2">Nothing to show</h2>
        <p class="text-slate-400">This page has no content yet.</p>
      </div>
    </div>
  <?php endif; ?>
</main>

<?php $this->load->view('partials/footer'); ?>

<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>
