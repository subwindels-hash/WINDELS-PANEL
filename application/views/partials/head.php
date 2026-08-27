<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Global <head> for the public shell. The layout passes $page_* values; this
// partial owns metadata, CSRF tags and the two stylesheets so no page can
// accidentally load a third CSS file or a different font stack.
$site_name  = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
$site_tag   = function_exists('marvy_site_tagline') ? marvy_site_tagline() : 'Prepaid commerce for digital goods';
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
<?php // Theme init — runs before first paint so there is no flash of the wrong
      // theme. Reads the site default (default_theme: system|light|dark) and a
      // per-visitor localStorage override, then resolves 'system' against the
      // OS preference. The toggle lives in the nav; MARVYSOCIALS.setTheme() updates
      // both the class and localStorage. ?>
<script <?=csp_nonce_attr()?>>
(function(){
  var saved = null;
  try { saved = localStorage.getItem('ws-theme'); } catch(e) {}
  var def = <?=json_encode(function_exists('marvy_default_theme') ? marvy_default_theme() : 'system')?>;
  var t = (saved === 'light' || saved === 'dark') ? saved : def;
  var dark = false;
  if (t === 'dark') dark = true;
  else if (t !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) dark = true;
  document.documentElement.classList.toggle('dark', dark);
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
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
<?php
// Structured data — one Organization/WebSite block driven by the same brand
// config as the rest of the shell, so a rename never leaves stale JSON-LD.
$sd_org = array(
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => $site_name,
    'url'      => site_url(),
    'logo'     => base_url('assets/brand/logo.svg'),
    'description' => $site_tag,
);
$sd_web = array(
    '@context' => 'https://schema.org',
    '@type'    => 'WebSite',
    'name'     => $site_name,
    'url'      => site_url(),
    'publisher' => array('@type' => 'Organization', 'name' => $site_name, 'logo' => base_url('assets/brand/logo.svg')),
);
?>
<script type="application/ld+json" <?=csp_nonce_attr()?>><?=json_encode($sd_org, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
<script type="application/ld+json" <?=csp_nonce_attr()?>><?=json_encode($sd_web, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
