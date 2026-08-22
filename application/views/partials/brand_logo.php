<?php defined('BASEPATH') OR exit('No direct script access allowed');
// One logo partial used by the public shell, auth shell and authenticated app
// shell. Every variant resolves to the WINDELS PANEL mark so the logo can
// never drift between routes.
$variant = isset($variant) ? $variant : 'horizontal';
$site = function_exists('windels_site_name') ? windels_site_name() : 'WINDELS PANEL';
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
$src = base_url('assets/brand/'.$file);
$h = isset($height) ? (int)$height : ($variant === 'icon' ? 32 : 36);
$alt = ($variant === 'icon') ? '' : $site;
$class = isset($class) ? $class : 'ws-logo';
?>
<img class="<?=htmlspecialchars($class)?>" src="<?=htmlspecialchars($src)?>" alt="<?=htmlspecialchars($alt)?>"
     height="<?=$h?>" width="<?=$variant === 'icon' ? $h : (int)round($h * ($variant === 'horizontal' ? 7.5 : 5.3))?>"
     decoding="async">
