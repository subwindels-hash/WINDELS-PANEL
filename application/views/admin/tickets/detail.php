<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$sbadge = function ($s) {
    $map = array('OPEN'=>'badge-warning','PENDING'=>'badge-default','ANSWERED'=>'badge-info','CLOSED'=>'badge-success');
    return 'badge '.($map[$s] ?? 'badge-default');
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/tickets')?>">← Support queue</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600"><?=htmlspecialchars($ticket->subject)?></h2>
    <p class="muted text-sm">
      <span class="<?=$sbadge($ticket->status)?>"><?=htmlspecialchars($ticket->status)?></span>
      <span class="mono text-xs"><?=htmlspecialchars($ticket->public_id)?></span>
      · <?=htmlspecialchars((string)$ticket->username)?> (<?=htmlspecialchars((string)$ticket->email)?>)
      · opened <?=htmlspecialchars((string)$ticket->created_at)?>
    </p>
  </div>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div>
    <div class="card mb-4">
      <h3 class="text-sm font-semibold mb-2">Conversation</h3>
      <?php foreach ($messages as $m): ?>
        <?php $note = !empty($m->is_internal_note); ?>
        <div class="card mb-2" style="<?=$note
            ? 'background:#fffbeb;border-color:#fcd34d'
            : ($m->is_staff ? 'background:#f8fafc' : '')?>">
          <div class="row justify-between text-xs muted mb-1">
            <span>
              <?php if ($note): ?><span class="badge badge-warning">Internal note</span>
              <?php elseif ($m->is_staff): ?><span class="badge badge-info">Staff</span>
              <?php else: ?><span class="badge badge-default">Customer</span><?php endif; ?>
            </span>
            <span><?=htmlspecialchars((string)$m->created_at)?></span>
          </div>
          <div style="white-space:pre-wrap"><?=htmlspecialchars($m->message)?></div>
          <?php $this->load->view('partials/ticket_attachments',
                  array('attachments' => $m->attachments ?? array())); ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($has('tickets.reply')): ?>
    <div class="card">
      <h3 class="text-sm font-semibold mb-2">Reply</h3>
      <form method="post" enctype="multipart/form-data"
            action="<?=site_url('admin/tickets/'.$ticket->public_id.'/reply')?>">
        <?=$csrf()?>
        <textarea class="input mb-2" name="message" rows="5" required
                  placeholder="Write a reply to the customer…"></textarea>
        <label class="text-sm font-medium" for="staff-attachments">Attach files (optional)</label>
        <input class="input mb-2" id="staff-attachments" type="file" name="attachments[]" multiple
               accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
        <label class="row text-sm mb-2" style="gap:.4rem;align-items:center">
          <input type="checkbox" name="internal" value="1">
          Internal note — visible to staff only, the customer never sees it
        </label>
        <button class="btn btn-primary btn-sm" type="submit">Send</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card mb-4">
      <h3 class="text-sm font-semibold mb-2">Details</h3>
      <table class="table">
        <tbody>
          <tr><th>Priority</th><td><?=htmlspecialchars($ticket->priority)?></td></tr>
          <tr><th>Department</th><td><?=htmlspecialchars((string)($ticket->department ?: '—'))?></td></tr>
          <tr><th>Assignee</th><td><?=$ticket->assignee_username
              ? htmlspecialchars($ticket->assignee_username) : '<span class="muted">unassigned</span>'?></td></tr>
          <?php if ($order): ?>
          <tr><th>Order</th><td>
            <a class="mono text-xs" href="<?=site_url('admin/orders/'.$order->public_id)?>"><?=htmlspecialchars($order->public_id)?></a>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($has('tickets.manage')): ?>
    <div class="card">
      <h3 class="text-sm font-semibold mb-2">Manage</h3>

      <form method="post" action="<?=site_url('admin/tickets/'.$ticket->public_id.'/assign')?>" class="mb-4">
        <?=$csrf()?>
        <label class="text-sm font-medium" for="assigned_to_id">Assignee</label>
        <select class="input mb-2" id="assigned_to_id" name="assigned_to_id">
          <option value="">— Unassigned —</option>
          <?php foreach ($staff as $s): ?>
            <option value="<?=(int)$s->id?>" <?=(int)$ticket->assigned_to_id === (int)$s->id ? 'selected' : ''?>>
              <?=htmlspecialchars($s->username)?> (<?=htmlspecialchars($s->role)?>)
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary btn-sm" type="submit">Save assignee</button>
      </form>

      <form method="post" action="<?=site_url('admin/tickets/'.$ticket->public_id.'/status')?>" class="mb-4">
        <?=$csrf()?>
        <label class="text-sm font-medium" for="status">Status</label>
        <select class="input mb-2" id="status" name="status">
          <?php foreach (array('OPEN','PENDING','ANSWERED','CLOSED') as $s): ?>
            <option value="<?=$s?>" <?=$ticket->status === $s ? 'selected' : ''?>><?=$s?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary btn-sm" type="submit">Save status</button>
      </form>

      <form method="post" action="<?=site_url('admin/tickets/'.$ticket->public_id.'/priority')?>">
        <?=$csrf()?>
        <label class="text-sm font-medium" for="priority">Priority</label>
        <select class="input mb-2" id="priority" name="priority">
          <?php foreach (array('LOW','MEDIUM','HIGH','URGENT') as $p): ?>
            <option value="<?=$p?>" <?=$ticket->priority === $p ? 'selected' : ''?>><?=$p?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary btn-sm" type="submit">Save priority</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
