<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Global <head> for the public shell. The layout passes $page_* values; this
// partial owns metadata, CSRF tags and the two stylesheets so no page can
// accidentally load a third CSS file or a different font stack.
$site_name  = function_exists('windels_site_name') ? windels_site_name() : 'WINDELS PANEL';
$site_tag   = function_exists('windels_site_tagline') ? windels_site_tagline() : 'Prepaid commerce for digital goods';
$title      = !empty($page_title) ? $page_title : (!empty($title) ? $title : $site_name);
$desc       = !empty($page_desc) ? $page_desc : (!empty($meta_description) ? $meta_description : $site_tag);
$canonical  = !empty($page_canonical) ? $page_canonical : (!empty($canonical) ? $canonical : (isset($this->uri) ? trim((string)$this->uri->uri_string(), '/') : ''));
$robots     = !empty($page_robots) ? $page_robots : (!empty($meta_robots) ? $meta_robots : 'index,follow');
$og_type    = !empty($page_og_type) ? $page_og_type : 'website';
$og_image   = isset($page_og_image) ? $page_og_image : base_url('assets/images/home/hero.jpg');
$canonical_url = site_url($canonical);
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($title)?> · <?=htmlspecialchars($site_name)?></title>
<meta name="description" content="<?=htmlspecialchars($desc)?>">
<meta name="robots" content="<?=htmlspecialchars($robots)?>">
<link rel="canonical" href="<?=htmlspecialchars($canonical_url)?>">
<meta property="og:type" content="<?=htmlspecialchars($og_type)?>">
<meta property="og:title" content="<?=htmlspecialchars($title)?>">
<meta property="og:description" content="<?=htmlspecialchars($desc)?>">
<meta property="og:url" content="<?=htmlspecialchars($canonical_url)?>">
<meta property="og:site_name" content="<?=htmlspecialchars($site_name)?>">
<meta property="og:image" content="<?=htmlspecialchars($og_image)?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?=htmlspecialchars($title)?>">
<meta name="twitter:description" content="<?=htmlspecialchars($desc)?>">
<?php $this->load->view('partials/icons_meta'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap">
<meta name="csrf-name" content="<?=htmlspecialchars($this->security->get_csrf_token_name())?>">
<meta name="csrf-token" content="<?=htmlspecialchars($this->security->get_csrf_hash())?>">
<meta name="csrf-endpoint" content="<?=htmlspecialchars(site_url('csrf'))?>">
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
<link rel="stylesheet" href="<?=base_url('assets/css/design-system.css')?>">
