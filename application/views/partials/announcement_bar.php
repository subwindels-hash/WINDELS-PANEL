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
        'Welcome to WINDELS PANEL',
        'SMM, VTU, gift cards, virtual numbers and identity verification — all in one panel',
        'Add funds and place orders from your dashboard',
    );
}

// Repeat until the strip is long enough that the loop never looks empty.
$group = $items;
while (count($group) < 6) {
    $group = array_merge($group, $items);
}
?>
<div class="ws-announce" role="region" aria-label="Announcements">
  <div class="ws-announce-track">
    <div class="ws-announce-group">
      <?php foreach ($group as $text): ?>
        <span class="ws-announce-item"><?=htmlspecialchars($text)?></span>
      <?php endforeach; ?>
    </div>
    <div class="ws-announce-group" aria-hidden="true">
      <?php foreach ($group as $text): ?>
        <span class="ws-announce-item"><?=htmlspecialchars($text)?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
