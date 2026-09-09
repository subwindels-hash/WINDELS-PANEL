<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$unenforced = $unenforced ?? array();
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-xs muted" href="<?=site_url('admin/staff')?>">← Staff</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Roles and permissions</h2>
    <p class="muted text-sm">What each role may do. Changes apply to everyone holding that role.</p>
  </div>
</div>

<?php if ($unenforced): ?>
<div class="alert alert-warning mb-4">
  <strong><?=count($unenforced)?> permission<?=count($unenforced) === 1 ? '' : 's'?> currently gate nothing.</strong>
  They are granted here but no code checks them yet, so ticking them changes nothing today:
  <span class="mono text-xs"><?=htmlspecialchars(implode(', ', $unenforced))?></span>
</div>
<?php endif; ?>

<?php foreach ($roles as $role): ?>
  <?php $held = $matrix[$role->name] ?? array(); ?>
  <div class="card mb-4">
    <div class="row justify-between mb-3" style="align-items:flex-start;flex-wrap:wrap;gap:.5rem">
      <div>
        <h3 style="font-size:1rem;font-weight:600" class="mb-0"><?=htmlspecialchars($role->name)?></h3>
        <p class="muted text-xs mb-0">
          <?=htmlspecialchars((string)$role->description)?>
          · <?=number_format((int)$role->headcount)?> account<?=$role->headcount == 1 ? '' : 's'?>
        </p>
      </div>
      <?php if (!$role->editable): ?>
        <span class="badge badge-default">locked</span>
      <?php endif; ?>
    </div>

    <?php if ($role->name === 'SUPER_ADMIN'): ?>
      <div class="alert alert-info">
        SUPER_ADMIN bypasses every permission check in code, so this grid would be decorative.
        It is intentionally not editable — and one active super admin must always exist, because
        it is the only role that can recover from a mistake made on this page.
      </div>
    <?php elseif ($role->name === 'CUSTOMER'): ?>
      <div class="alert alert-info">
        Every customer holds this role, so it deliberately carries no staff permissions.
      </div>
    <?php else: ?>
      <form method="post" action="<?=site_url('admin/staff/permissions/'.$role->name)?>">
        <?=$csrf()?>
        <?php foreach ($catalogue as $category => $perms): ?>
          <div style="margin-bottom:1rem">
            <div class="label" style="margin-bottom:.35rem"><?=htmlspecialchars(ucfirst($category))?></div>
            <div class="row" style="gap:.75rem;flex-wrap:wrap">
              <?php foreach ($perms as $p): ?>
                <?php
                  $on   = in_array($p->perm_key, $held, true);
                  $dead = in_array($p->perm_key, $unenforced, true);
                  $id   = 'perm-'.$role->name.'-'.$p->perm_key;
                ?>
                <label class="row" style="gap:.35rem;align-items:center;min-width:13rem"
                       title="<?=htmlspecialchars((string)$p->description)?>">
                  <input type="checkbox" id="<?=htmlspecialchars($id)?>"
                         name="permissions[]" value="<?=htmlspecialchars($p->perm_key)?>"
                         <?=$on ? 'checked' : ''?>>
                  <span class="mono text-xs<?=$dead ? ' muted' : ''?>">
                    <?=htmlspecialchars($p->perm_key)?><?=$dead ? ' *' : ''?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($role->name === $current_user->role): ?>
          <div class="alert alert-warning">
            This is your own role. You cannot remove <span class="mono"><?=htmlspecialchars($keystone)?></span>
            from it — you would lose the ability to grant it back.
          </div>
        <?php endif; ?>

        <div class="row justify-between" style="align-items:center;flex-wrap:wrap;gap:.5rem">
          <span class="muted text-xs">Saving replaces this role's whole permission set.</span>
          <button class="btn btn-primary btn-sm" type="submit">Save <?=htmlspecialchars($role->name)?></button>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
