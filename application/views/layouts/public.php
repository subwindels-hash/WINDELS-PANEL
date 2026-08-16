<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($data['title'] ?? 'WINDELS PANEL')?></title>
<link rel="stylesheet" href="<?=base_url('assets/css/tailwind.css')?>">
</head>
<body class="min-h-screen bg-white text-gray-900 antialiased">
<?php $this->load->view('partials/public_nav'); ?>
<main>
<?php if (!empty($content_view)) $this->load->view($content_view, $data ?? array()); ?>
</main>
<?php $this->load->view('partials/footer'); ?>
<script src="<?=base_url('assets/js/app.js')?>"></script>
</body>
</html>
