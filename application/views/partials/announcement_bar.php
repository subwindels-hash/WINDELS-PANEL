<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * The announcement strip at the top of every page.
 *
 * Everything about it is now an operator setting (Admin → Settings →
 * Branding): whether it shows at all, what it says, its background colour, its
 * text colour and how fast it scrolls. Before this the words came only from
 * published CMS announcements — with a hard-coded fallback nobody could edit —
 * and the colours were fixed in the stylesheet, so "change the banner" was a
 * code change.
 *
 * Precedence, most specific first:
 *   1. `announcement_text` — one message per line, typed by the operator;
 *   2. published announcements from Content → Announcements;
 *   3. a short built-in explanation of how the panel works, so a brand-new
 *      install is never blank.
 *
 * A line may carry a link, written `[Read more](/blog/outage)`. The anchor is
 * built by AnnouncementText, never pasted through: an outage notice that
 * cannot point at the page explaining the outage is half a notice, and letting
 * an operator type raw HTML into the one strip on every page of a site holding
 * wallet balances is how a banner becomes stored XSS.
 */
$site_name = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
if (!class_exists('AnnouncementText', false)) {
    require_once APPPATH.'libraries/AnnouncementText.php';
}

$setting = function ($key, $default) {
    try {
        $CI =& get_instance();
        if (!function_exists('marvy_load_database') || !marvy_load_database()) return $default;
        $CI->load->model('Setting_model');
        $value = $CI->Setting_model->get($key, $default);
        return $value === null || $value === '' ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
};

// A settings read that fails (no database yet on a fresh install) must not
// take the whole page down, so every lookup carries its own default.
$enabled = $setting('announcement_enabled', true);
if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'false') return;

$custom = trim((string)$setting('announcement_text', ''));
$items  = array();

if ($custom !== '') {
    foreach (preg_split('/\r\n|\r|\n/', $custom) as $line) {
        $line = trim($line);
        if ($line !== '') $items[] = $line;
    }
}

if (!$items) {
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
    foreach ((array)$announcements as $a) {
        $text = trim((string)($a->title ?? ''));
        $body = trim(strip_tags((string)($a->content ?? '')));
        if ($body !== '' && $body !== $text) {
            $text = $text === '' ? $body : $text.' — '.$body;
        }
        if ($text !== '') $items[] = $text;
    }
}

if (!$items) {
    $items = array(
        $site_name.' is a prepaid platform — add funds to spend on services.',
        'Create an account, browse Services, then place an order from your dashboard.',
        'Need help? Open FAQ, Contact, or a support ticket from your account.',
    );
}

// Colours are validated as #rrggbb when saved (SettingsService 'color'), so
// they are safe in a style attribute; escaped again here on principle.
$bg   = (string)$setting('announcement_bg_color', '#0b1b3a');
$ink  = (string)$setting('announcement_text_color', '#ffffff');

// The strip used to be a scrolling marquee; the operator asked for it to stop
// moving, so it is now always static — every message renders, stacked, and
// nothing scrolls across the top of the page. The speed setting is still read
// (older installs and the settings screen write it) but deliberately does not
// drive any motion: the bar has no animation, on any screen, for anyone.
$speed   = (int)$setting('announcement_speed_seconds', 40);
$seconds = 0;
$static  = true;

$style = 'background:'.htmlspecialchars($bg, ENT_QUOTES).';'
       . 'color:'.htmlspecialchars($ink, ENT_QUOTES).';'
       . 'border-bottom-color:rgba(255,255,255,.16);';

// AnnouncementText escapes every character it did not write itself, so the
// output is placed unescaped on purpose — escaping it again would print the
// anchor as text.
?>
<div class="ws-announce is-static" role="region" aria-label="Announcements"
     data-announce style="<?=$style?>">
  <div class="ws-announce-viewport">
    <div class="ws-announce-stack">
      <?php foreach ($items as $text): ?>
        <span class="ws-announce-item"><?=AnnouncementText::render($text)?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
// The bar's height feeds the sticky offsets of everything below it
// (--ws-announce-h). A single message is the classic 2.75rem, but stacked
// messages make the strip taller — publish the real height to :root so the
// sticky chrome stays exactly under it on every viewport.
(function () {
  var bar = document.querySelector('[data-announce]');
  if (!bar) return;
  function sync() {
    document.documentElement.style.setProperty('--ws-announce-h', bar.offsetHeight + 'px');
  }
  sync();
  window.addEventListener('resize', sync);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(sync);
})();
</script>
