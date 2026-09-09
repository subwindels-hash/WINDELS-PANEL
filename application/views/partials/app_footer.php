<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Compact footer for the authenticated shell.
 *
 * The public site has partials/footer; signed-in pages used to end in nothing
 * at all, so the legal pages, the API docs and support were unreachable from
 * inside the panel without going back to the marketing site. Same links, less
 * furniture.
 */
$site_name = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
// Feature flags gate the footer exactly as they gate the sidebar: a link to a
// module an operator switched off would 404 the customer.
$links = array(array('services', 'Services'));
if (marvy_feature_enabled('marketplace', true)) $links[] = array('shop', 'Shop');
$links[] = array('api/docs', 'API docs');
if (marvy_feature_enabled('tickets', true))     $links[] = array('dashboard/tickets', 'Support');
if (marvy_feature_enabled('blog', true))        $links[] = array('blog', 'Blog');
$links[] = array('faq', 'FAQ');
$links[] = array('terms', 'Terms');
$links[] = array('privacy', 'Privacy');
$links[] = array('refund-policy', 'Refunds');
?>
<footer class="ws-app-footer">
  <div class="row justify-between" style="gap:1rem;flex-wrap:wrap;align-items:center">
    <p class="muted text-sm mb-0">&copy; <?=date('Y')?> <?=htmlspecialchars($site_name)?>. Prepaid wallet — balances are spent on the panel, not withdrawn.</p>
    <nav class="row text-sm" style="gap:.85rem;flex-wrap:wrap" aria-label="Footer">
      <?php foreach ($links as $l): ?>
        <a href="<?=site_url($l[0])?>"><?=htmlspecialchars($l[1])?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</footer>
