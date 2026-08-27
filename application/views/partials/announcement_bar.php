<?php defined('BASEPATH') OR exit('No direct script access allowed');
$site_name = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
if (!isset($announcements)) {
    $announcements = array();
    try {
        $CI =& get_instance();
        if (!function_exists('marvy_load_database') || !marvy_load_database()) {
            throw new RuntimeException('database unavailable');
        }
        $CI->load->model('Announcement_model');
        $announcements = $CI->Announcement_model->visible('*');
    } catch (Throwable $e) { /* hidden until the table is available */ }
}

$items = array();
if (!empty($announcements)) {
    foreach ($announcements as $a) {
        $text = trim((string)($a->title ?? ''));
        $body = trim(strip_tags((string)($a->content ?? '')));
        if ($body !== '' && $body !== $text) {
            $text = $text === '' ? $body : $text.' — '.$body;
        }
        if ($text !== '') $items[] = $text;
    }
}
if (empty($items)) {
    $items = array(
        $site_name.' is a prepaid platform — add funds to spend on services.',
        'Create an account, browse Services, then place an order from your dashboard.',
        'Need help? Open FAQ, Contact, or a support ticket from your account.',
        'Staff sign in at Admin login. Customer passwords cannot open the back office.',
    );
}
$seconds = max(28, count($items) * 10);
$render_items = function () use ($items) {
    foreach ($items as $text): ?>
      <span class="ws-announce-item"><?=htmlspecialchars($text)?></span>
    <?php endforeach;
};
?>
<div class="ws-announce" role="region" aria-label="Announcements"
     data-announce style="--ws-announce-duration: <?=(int)$seconds?>s">
  <div class="ws-announce-viewport">
    <div class="ws-announce-track">
      <?php $render_items(); ?>
      <?php $render_items(); ?>
    </div>
  </div>
</div>
