<?php defined('BASEPATH') OR exit('No direct script access allowed');
// One logo partial used by the public shell, auth shell and authenticated app
// shell. Every variant resolves to the MarvySocials mark by default, but an
// administrator can override the horizontal/wordmark logo via Admin →
// Appearance (brand_logo_url). The icon mark stays bundled so the favicon and
// compact logo never lose their shape.
$variant = isset($variant) ? $variant : 'horizontal';
$site = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
// Raster first, vector as the fallback: the brand ships real PNG artwork so
// the logo is an image everywhere (including mail clients and hosts that
// refuse image/svg+xml), and the SVGs stay as a fallback for any install that
// has not synced the new assets yet.
$files = array(
    'icon'       => array('logo-icon.png', 'logo-icon.svg'),
    'dark'       => array('logo-dark.png', 'logo-dark.svg'),
    'horizontal' => array('logo-horizontal.png', 'logo-horizontal.svg'),
    'full'       => array('logo.png', 'logo.svg'),
);
$candidates = $files[$variant] ?? $files['horizontal'];
$file = null;
foreach ($candidates as $candidate) {
    if (is_file(FCPATH.'assets/brand/'.$candidate)) { $file = $candidate; break; }
}
if ($file === null) $file = $files['horizontal'][1];

$custom = function_exists('marvy_brand_setting') ? marvy_brand_setting('brand_logo_url') : null;
$src = (!empty($custom) && $variant !== 'icon') ? $custom : base_url('assets/brand/'.$file);
$h = isset($height) ? (int)$height : ($variant === 'icon' ? 32 : 36);
$alt = ($variant === 'icon') ? '' : $site;
$class = isset($class) ? $class : 'ws-logo';
// Bundled SVGs have a known aspect ratio; an admin-uploaded logo does not, so
// fix only its height and let the width be intrinsic.
// Ratios of the shipped artwork (972x192 for the wordmarks), so the
// browser reserves the right box and the page does not jump on load.
$ratios = array('icon' => 1, 'horizontal' => 5.0625, 'dark' => 5.0625, 'full' => 5.0625);
$width_attr = (!empty($custom) && $variant !== 'icon')
    ? ''
    : 'width="'.(int)round($h * ($ratios[$variant] ?? $ratios['horizontal'])).'"';
?>
<img class="<?=htmlspecialchars($class)?>" src="<?=htmlspecialchars($src)?>" alt="<?=htmlspecialchars($alt)?>"
     height="<?=$h?>" <?=$width_attr?> decoding="async">
