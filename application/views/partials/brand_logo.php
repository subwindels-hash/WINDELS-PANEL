<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$variant = isset($variant) ? $variant : 'horizontal';
$map = array(
    'icon'       => 'assets/brand/logo-icon.svg',
    'dark'       => 'assets/brand/logo-dark.svg',
    'horizontal' => 'assets/brand/logo-horizontal.svg',
    'full'       => 'assets/brand/logo.svg',
);
$src = base_url($map[$variant] ?? $map['horizontal']);
$h = isset($height) ? (int)$height : ($variant === 'icon' ? 32 : 36);
$alt = ($variant === 'icon') ? 'WINDELS PANEL' : '';
$class = isset($class) ? $class : 'ws-logo';
?>
<img class="<?=htmlspecialchars($class)?>" src="<?=htmlspecialchars($src)?>" alt="<?=htmlspecialchars($alt)?>"
     height="<?=$h?>" width="<?=$variant === 'icon' ? $h : (int)round($h * ($variant === 'horizontal' ? 7.5 : 4.4))?>"
     decoding="async">
