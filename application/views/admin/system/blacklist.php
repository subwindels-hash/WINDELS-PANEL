<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$placeholder = array(
    'emails' => 'someone@example.test',
    'ips'    => '203.0.113.9',
    'links'  => 'spam-domain.test',
);
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Blacklist</h2>
    <p class="muted text-sm">Addresses, IPs and link patterns refused at sign-up and checkout</p>
  </div>
</div>

<?php $this->load->view('admin/system/_tabs', array('tabs'=>$tabs,'area'=>$area)); ?>

<?php foreach ($lists as $kind => $spec): ?>
  <div class="card mb-4">
    <div class="row justify-between mb-3" style="align-items:flex-start;flex-wrap:wrap;gap:.5rem">
      <h3 style="font-size:1rem;font-weight:600" class="mb-0"><?=htmlspecialchars($spec['label'])?></h3>
      <span class="muted text-xs"><?=count($entries[$kind])?> blocked</span>
    </div>

    <form method="post" action="<?=site_url('admin/blacklist/'.$kind.'/add')?>"
          class="row mb-3" style="gap:.35rem;flex-wrap:wrap;align-items:flex-end">
      <?=$csrf()?>
      <div class="field" style="flex:1;min-width:14rem">
        <label class="label">Add</label>
        <input class="input mono" name="value" required
               placeholder="<?=htmlspecialchars($placeholder[$kind])?>">
      </div>
      <div class="field" style="flex:1;min-width:12rem">
        <label class="label">Reason</label>
        <input class="input" name="reason" maxlength="500" placeholder="Optional">
      </div>
      <button class="btn btn-secondary btn-sm" type="submit">Block</button>
    </form>

    <?php if ($kind === 'links'): ?>
      <div class="alert alert-info mb-3">
        A plain value like <span class="mono">spam-domain.test</span> matches as a substring.
        A value wrapped in slashes is treated as a regular expression and runs against every
        signup and order, so patterns that backtrack catastrophically are refused when you add
        them, and patterns are capped at <?=SystemAdminService::MAX_PATTERN?> characters.
      </div>
    <?php endif; ?>

    <?php if (empty($entries[$kind])): ?>
      <p class="muted text-sm">Nothing blocked yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>Value</th><th>Reason</th><th>Added</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($entries[$kind] as $e): ?>
            <?php $value = $e->{$spec['column']}; ?>
            <tr>
              <td class="mono text-xs"><?=htmlspecialchars((string)$value)?></td>
              <td class="text-xs muted"><?=htmlspecialchars((string)($e->reason ?: '—'))?></td>
              <td class="text-xs muted whitespace-nowrap">
                <?=htmlspecialchars(date('M j, Y', strtotime($e->created_at)))?>
              </td>
              <td>
                <form method="post" action="<?=site_url('admin/blacklist/'.$kind.'/'.(int)$e->id.'/remove')?>"
                      style="display:inline">
                  <?=$csrf()?>
                  <button class="btn btn-ghost btn-sm" type="submit">Unblock</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
