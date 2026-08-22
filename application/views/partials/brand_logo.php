<?php defined('BASEPATH') OR exit('No direct script access allowed');
// One logo partial used by the public shell, auth shell and authenticated app
// shell. The public brand favour the "Averion Commerce" asset when present and
// fall back to the legacy WINDELS asset so an upgrade can't white-screen.
$variant = isset($variant) ? $variant : 'horizontal';
$site = function_exists('windels_site_name') ? windels_site_name() : 'Averion Commerce';
$public = array(
    'icon'       => 'logo-averion-commerce-icon.svg',
    'dark'       => 'logo-averion-commerce-dark.svg',
    'horizontal' => 'logo-averion-commerce.svg',
    'full'       => 'logo-averion-commerce.svg',
);
$legacy = array(
    'icon'       => 'logo-icon.svg',
    'dark'       => 'logo-dark.svg',
    'horizontal' => 'logo-horizontal.svg',
    'full'       => 'logo.svg',
);
$file = (isset($force_legacy) && $force_legacy)
    ? ($legacy[$variant] ?? $legacy['horizontal'])
    : ($public[$variant] ?? $public['horizontal']);
if (!is_file(FCPATH.'assets/brand/'.$file)) {
    $file = $legacy[$variant] ?? $legacy['horizontal'];
}
$src = base_url('assets/brand/'.$file);
$h = isset($height) ? (int)$height : ($variant === 'icon' ? 32 : 36);
$alt = ($variant === 'icon') ? '' : $site;
$class = isset($class) ? $class : 'ws-logo';
?>
<img class="<?=htmlspecialchars($class)?>" src="<?=htmlspecialchars($src)?>" alt="<?=htmlspecialchars($alt)?>"
     height="<?=$h?>" width="<?=$variant === 'icon' ? $h : (int)round($h * ($variant === 'horizontal' ? 7.5 : 5.3))?>"
     decoding="async">
