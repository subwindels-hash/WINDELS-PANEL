<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Global public layout. Every public page (home, services, pricing, faq, legal,
// auth-free pages, 404) renders through this shell so the announcement bar,
// header, nav, container, footer and assistant are byte-for-byte identical.
$content = $content_view ?? '';
$page_data = $data ?? array();
$layout_site = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
$page_title = !empty($page_title) ? $page_title : (!empty($page_data['title']) ? $page_data['title'] : $layout_site);
$page_desc = !empty($page_desc) ? $page_desc : (!empty($page_data['meta_description']) ? $page_data['meta_description'] : (function_exists('marvy_site_tagline') ? marvy_site_tagline() : $layout_site));
$page_robots = !empty($page_robots) ? $page_robots : (!empty($page_data['meta_robots']) ? $page_data['meta_robots'] : 'index,follow');
$page_canonical = !empty($page_canonical) ? $page_canonical : (!empty($page_data['canonical']) ? $page_data['canonical'] : '');
if (!class_exists('SiteOperatorKnowledge', false)) {
    $knowledge = APPPATH.'libraries/SiteOperatorKnowledge.php';
    if (is_file($knowledge)) require_once $knowledge;
}
?>
<!doctype html>
<html lang="en">
<head>
<?php $this->load->view('partials/head', array_merge($page_data, array(
    'page_title'      => $page_title,
    'page_desc'       => $page_desc,
    'page_robots'     => $page_robots,
    'page_canonical'  => $page_canonical,
    'page_og_type'    => $page_data['og_type'] ?? 'website',
    'page_og_image'   => $page_data['og_image'] ?? base_url('assets/images/home/hero.jpg'),
))); ?>
</head>
<body class="min-h-screen bg-surface text-slate-900 antialiased ws-public-shell">
<a class="ws-skip" href="#main">Skip to content</a>
<?php $this->load->view('partials/header'); ?>
<main id="main" class="ws-main" tabindex="-1">
  <?php if ($content !== ''): ?>
    <?php $this->load->view($content, $page_data); ?>
  <?php else: ?>
    <div class="container ws-section-sm"><div class="empty-state card"><h2>Nothing to show</h2><p>This page has no content yet.</p></div></div>
  <?php endif; ?>
</main>
<?php $this->load->view('partials/footer'); ?>
<?php $this->load->view('partials/chatbot'); ?>
<?php $this->load->view('partials/scripts'); ?>
</body>
</html>
