<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Administrator-mode banner.
 *
 * Rendered by EVERY authenticated shell (layouts/app.php, layouts/app_theme.php).
 * An impersonated session that renders without this banner is a compliance
 * failure — the operator must always be able to see whose account they are in
 * and get back out — so the markup lives in one partial instead of being
 * copied per theme.
 */
$impersonation = $impersonation ?? array();
if (empty($impersonation['active'])) return;
$__imp_actor   = $impersonation['actor'] ?? null;
$__imp_ctx     = $impersonation['context'] ?? array();
$__imp_minutes = max(0, (int)ceil(((int)($__imp_ctx['expires_at'] ?? time()) - time()) / 60));
?>
<div role="alert" aria-live="assertive" class="ws-impersonation-banner">
  <div class="row justify-between" style="align-items:center;gap:1rem;flex-wrap:wrap;max-width:90rem;margin:0 auto">
    <div>
      <strong style="display:block;letter-spacing:.03em">
        Administrator Mode — You are currently viewing this account as an administrator.
      </strong>
      <span class="text-sm">
        Staff: <?=htmlspecialchars((string)($__imp_actor->username ?? 'staff'))?> ·
        Customer: <?=htmlspecialchars((string)($current_user->username ?? 'customer'))?> ·
        hard expiry in approximately <?=$__imp_minutes?> minute<?=$__imp_minutes === 1 ? '' : 's'?>.
      </span>
    </div>
    <form method="post" action="<?=site_url('impersonation/stop')?>" style="margin:0">
      <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
             value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
      <button class="btn btn-sm" type="submit" style="background:#fff;color:#7f1d1d;border:2px solid #fff;font-weight:700;white-space:nowrap">
        Return to Admin Dashboard
      </button>
    </form>
  </div>
</div>
