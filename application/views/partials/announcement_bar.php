<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Inside a view $this is CI_Loader (no __get, no $auth), so the controller
// instance must be fetched explicitly. Fail open during install / CLI.
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
        'Prepaid wallet: add funds and spend on services — leftover deposits cannot be withdrawn.',
        'New here? Create an account, then browse Services or read Pricing.',
        'Need help? Open the FAQ, send a Contact message, or ask the on-site assistant.',
        'Staff sign in at Admin login. Customer passwords cannot open the back office.',
    );
}

// One visual cycle shows this group. Duration scales with readable characters
// so a longer announcement stays on screen long enough to read (~6 chars/sec).
$cycle_chars = 0;
foreach ($items as $text) {
    $cycle_chars += (function_exists('mb_strlen') ? mb_strlen($text) : strlen($text)) + 12;
}
$seconds = (int) max(55, min(180, $cycle_chars / 6));
?>
<div class="ws-announce" role="region" aria-label="Announcements" tabindex="0"
     style="--ws-announce-duration: <?=$seconds?>s">
  <div class="ws-announce-track">
    <div class="ws-announce-group">
      <?php foreach ($items as $text): ?>
        <span class="ws-announce-item"><?=htmlspecialchars($text)?></span>
      <?php endforeach; ?>
    </div>
    <div class="ws-announce-group" aria-hidden="true">
      <?php foreach ($items as $text): ?>
        <span class="ws-announce-item"><?=htmlspecialchars($text)?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
