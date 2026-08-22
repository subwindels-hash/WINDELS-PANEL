<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$title = $data['title'] ?? 'WINDELS PANEL';
$desc  = $data['meta_description'] ?? 'WINDELS PANEL — prepaid reseller platform for SMM, VTU, virtual numbers, identity checks, gift cards and a platform marketplace.';
$canonical_path = $data['canonical'] ?? (isset($this->uri) ? $this->uri->uri_string() : '');
$canonical = site_url($canonical_path);
$og_type = $data['og_type'] ?? 'website';
$robots = $data['meta_robots'] ?? 'index,follow';
if (!class_exists('SiteOperatorKnowledge', false)) {
    $knowledge = APPPATH.'libraries/SiteOperatorKnowledge.php';
    if (is_file($knowledge)) require_once $knowledge;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($title)?> · WINDELS PANEL</title>
<meta name="description" content="<?=htmlspecialchars($desc)?>">
<meta name="robots" content="<?=htmlspecialchars($robots)?>">
<link rel="canonical" href="<?=htmlspecialchars($canonical)?>">
<meta property="og:type" content="<?=htmlspecialchars($og_type)?>">
<meta property="og:title" content="<?=htmlspecialchars($title)?>">
<meta property="og:description" content="<?=htmlspecialchars($desc)?>">
<meta property="og:url" content="<?=htmlspecialchars($canonical)?>">
<meta property="og:site_name" content="WINDELS PANEL">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?=htmlspecialchars($title)?>">
<meta name="twitter:description" content="<?=htmlspecialchars($desc)?>">
<meta property="og:image" content="<?=htmlspecialchars($data['og_image'] ?? base_url('assets/images/home/hero.jpg'))?>">
<?php $this->load->view('partials/icons_meta'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap">
<?php // CSRF token for JavaScript: assets/js/app.js reads these and attaches the
      // token to every same-origin fetch/XHR, so a page that posts more than
      // once (reply box, chat widget, retry) never sends a retired token. ?>
<meta name="csrf-name" content="<?=htmlspecialchars($this->security->get_csrf_token_name())?>">
<meta name="csrf-token" content="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
<meta name="csrf-endpoint" content="<?=htmlspecialchars(site_url('csrf'))?>">
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
<link rel="stylesheet" href="<?=base_url('assets/css/design-system.css')?>">
</head>
<body class="min-h-screen bg-white text-gray-900 antialiased">
<?php $this->load->view('partials/announcement_bar'); ?>
<?php $this->load->view('partials/public_nav'); ?>
<main id="main">
<?php if (!empty($content_view)) $this->load->view($content_view, $data ?? array()); ?>
</main>
<?php $this->load->view('partials/footer'); ?>
<?php $this->load->view('partials/site_operator'); ?>
<script src="<?=base_url('assets/js/app.js')?>"></script>
</body>
</html>
