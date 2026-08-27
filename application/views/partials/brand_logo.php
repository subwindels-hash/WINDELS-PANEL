<?php defined('BASEPATH') OR exit('No direct script access allowed');
// One logo partial used by the public shell, auth shell and authenticated app
// shell. Every variant resolves to the MarvySocials mark by default, but an
// administrator can override the horizontal/wordmark logo via Admin →
// Appearance (brand_logo_url). The icon mark stays bundled so the favicon and
// compact logo never lose their shape.
$variant = isset($variant) ? $variant : 'horizontal';
$site = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
$files = array(
    'icon'       => 'logo-icon.svg',
    'dark'       => 'logo-dark.svg',
    'horizontal' => 'logo-horizontal.svg',
    'full'       => 'logo.svg',
);
$file = $files[$variant] ?? $files['horizontal'];
if (!is_file(FCPATH.'assets/brand/'.$file)) {
    $file = $files['horizontal'];
}

$custom = function_exists('marvy_brand_setting') ? marvy_brand_setting('brand_logo_url') : null;
$src = (!empty($custom) && $variant !== 'icon') ? $custom : base_url('assets/brand/'.$file);
$h = isset($height) ? (int)$height : ($variant === 'icon' ? 32 : 36);
$alt = ($variant === 'icon') ? '' : $site;
$class = isset($class) ? $class : 'ws-logo';
// Bundled SVGs have a known aspect ratio; an admin-uploaded logo does not, so
// fix only its height and let the width be intrinsic.
$width_attr = (!empty($custom) && $variant !== 'icon')
    ? ''
    : 'width="'.($variant === 'icon' ? $h : (int)round($h * ($variant === 'horizontal' ? 7.5 : 5.3))).'"';
?>
<img class="<?=htmlspecialchars($class)?>" src="<?=htmlspecialchars($src)?>" alt="<?=htmlspecialchars($alt)?>"
     height="<?=$h?>" <?=$width_attr?> decoding="async">
