<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($data['title'] ?? 'WINDELS PANEL')?></title>
<meta name="description" content="<?=htmlspecialchars($data['meta_description'] ?? 'WINDELS PANEL — Enterprise SMM Reseller Platform')?>">
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
<?php
// Active, time-bounded announcements (public + all audiences).
// Inside a view $this is CI_Loader, which has no __get and no $auth, so the
// controller instance must be fetched explicitly.
if (!isset($announcements)) {
    $CI =& get_instance();
    $CI->load->model('Announcement_model');
    $audience = 'all';
    if (isset($CI->auth) && $CI->auth->check()) {
        $audience = $CI->auth->has_role(array('SUPER_ADMIN','ADMIN','STAFF')) ? 'staff' : 'customers';
    }
    $announcements = $CI->Announcement_model->visible($audience);
}
?>
<?php if (!empty($announcements)): ?>
<div class="ws-announce">
  <?php foreach ($announcements as $a): ?>
    <div class="ws-announce-item ws-sev-<?=htmlspecialchars(strtolower($a->severity))?>"><?=htmlspecialchars($a->title)?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php $this->load->view('partials/public_nav'); ?>
<main>
<?php if (!empty($content_view)) $this->load->view($content_view, $data ?? array()); ?>
</main>
<?php $this->load->view('partials/footer'); ?>
<script src="<?=base_url('assets/js/app.js')?>"></script>
</body>
</html>
