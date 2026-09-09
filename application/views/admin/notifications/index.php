<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-4xl">
  <h2 class="card-title mb-0">Send a notification</h2>
  <p class="hint mt-2 mb-0">An in-app message from the team. Deliver it to <strong>every user at
    once</strong> — a maintenance window, a new payment method — or to <strong>one user</strong> by
    email. It appears in each recipient's bell and Notifications page the next time they load a
    page; no email is sent.</p>

  <form method="post" action="<?=site_url('admin/notifications/send')?>" class="mt-4">
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
           value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>

    <div class="row" style="gap:1.5rem">
      <label class="row text-sm" style="gap:.4rem">
        <input type="radio" name="mode" value="all" checked> All users
        <span class="muted">(<?=$total_users?> account<?=((int)$total_users)===1?'':'s'?>)</span>
      </label>
      <label class="row text-sm" style="gap:.4rem">
        <input type="radio" name="mode" value="one"> One user
      </label>
    </div>

    <label class="text-sm font-medium mt-3" for="recipient_email">Recipient email</label>
    <input class="input" type="email" id="recipient_email" name="recipient_email" maxlength="255"
           placeholder="only used when sending to one user">

    <label class="text-sm font-medium mt-4" for="title">Title</label>
    <input class="input" type="text" id="title" name="title" maxlength="150" required
           placeholder="e.g. Scheduled maintenance this Sunday">

    <label class="text-sm font-medium mt-4" for="body">Message</label>
    <textarea class="input" id="body" name="body" rows="6" required
              placeholder="What should every user know?"></textarea>

    <div class="row mt-4" style="gap:.5rem">
      <button class="btn btn-primary" type="submit"
              data-confirm="Deliver this notification?">Send notification</button>
    </div>
  </form>
</div>

<div class="card max-w-4xl mt-4">
  <h2 class="card-title mb-0">Recent notifications</h2>
  <?php if (empty($rows)): ?>
    <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'bell',
        'title' => 'Nothing sent yet',
        'body'  => 'Broadcasts you send appear here, newest first.',
    )); ?>
  <?php else: ?>
  <table class="table mt-3">
    <thead>
      <tr><th>Sent</th><th>Title</th><th>Recipient</th><th>State</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="whitespace-nowrap text-sm muted"><?=$r->created_at ? date('M j, Y H:i', strtotime($r->created_at)) : '—'?></td>
        <td class="text-sm"><?=htmlspecialchars((string) $r->title)?></td>
        <td class="mono text-sm"><?=htmlspecialchars((string) ($r->email ?: ('user #'.(int) $r->user_id)))?></td>
        <td class="text-sm"><?=empty($r->is_read) ? '<span class="badge badge-info">unread</span>' : '<span class="muted">read</span>'?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
