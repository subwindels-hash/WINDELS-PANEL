<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Announcement component: a single-at-a-time rotating carousel (not an endless
// marquee). One message is visible at a time and it lingers long enough to be
// read; the previous ticker duplicated its content and scrolled it on a loop,
// which made each line hard to read.
$site_name = function_exists('windels_site_name') ? windels_site_name() : 'WINDELS PANEL';
if (!isset($announcements)) {
    $announcements = array();
    try {
        $CI =& get_instance();
        if (!function_exists('windels_load_database') || !windels_load_database()) {
            throw new RuntimeException('database unavailable');
        }
        $CI->load->model('Announcement_model');
        $audience = 'all';
        $cu = $current_user ?? null;
        if ($cu && !empty($cu->role)) {
            $audience = in_array($cu->role, array('SUPER_ADMIN','ADMIN','STAFF'), true)
                ? 'staff' : 'customers';
        }
        $announcements = $CI->Announcement_model->visible($audience);
    } catch (Throwable $e) { /* hidden until the table is available */ }
}

$items = array();
if (!empty($announcements)) {
    foreach ($announcements as $a) {
        $text = trim((string)($a->title ?? ''));
        if ($text !== '') $items[] = $text;
    }
}
if (empty($items)) {
    $items = array(
        $site_name.' is a prepaid platform — add funds to spend on services.',
        'New here? Create an account, then browse Services or read Pricing.',
        'Need help? Check the FAQ, send a message, or ask the on-site assistant.',
        'Staff sign in at Admin login. Customer passwords cannot open the back office.',
    );
}
?>
<div class="ws-announce" role="region" aria-label="Announcements" tabindex="0"
     data-announce data-announce-interval="9000">
  <div class="ws-announce-viewport">
    <div class="ws-announce-slides">
      <?php foreach ($items as $text): ?>
        <div class="ws-announce-slide"><?=htmlspecialchars($text)?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="ws-announce-dots" data-announce-dots aria-hidden="true"></div>
</div>
