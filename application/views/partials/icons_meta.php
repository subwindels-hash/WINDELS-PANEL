<?php defined('BASEPATH') OR exit('No direct script access allowed');
$icon_site = function_exists('windels_site_name') ? windels_site_name() : 'WINDELS PANEL';
$icon_short = strtok($icon_site, ' ');
?>
<link rel="icon" href="<?=base_url('assets/brand/favicon.svg')?>" type="image/svg+xml">
<link rel="icon" href="<?=base_url('assets/brand/favicon.ico')?>" sizes="any">
<link rel="icon" href="<?=base_url('assets/brand/favicon-32.png')?>" sizes="32x32" type="image/png">
<link rel="icon" href="<?=base_url('assets/brand/favicon-16.png')?>" sizes="16x16" type="image/png">
<link rel="apple-touch-icon" href="<?=base_url('assets/brand/apple-touch-icon.png')?>">
<link rel="manifest" href="<?=base_url('assets/brand/site.webmanifest')?>">
<meta name="theme-color" content="#4F46E5">
<meta name="application-name" content="<?=htmlspecialchars($icon_site)?>">
<meta name="apple-mobile-web-app-title" content="<?=htmlspecialchars($icon_short)?>">
