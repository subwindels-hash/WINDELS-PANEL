<?php defined('BASEPATH') OR exit('No direct script access allowed');
$t = $ticket;
?>
<nav class="text-sm muted mb-4">
  <a href="<?=site_url('dashboard/tickets')?>">Support</a> · <span class="text-slate-700"><?=htmlspecialchars(substr($t->public_id,0,12))?>…</span>
</nav>

<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <div class="row justify-between">
        <div>
          <h2 class="card-title"><?=htmlspecialchars($t->subject)?></h2>
          <p class="muted text-xs mono"><?=htmlspecialchars($t->public_id)?> · <?=htmlspecialchars($t->department)?> · <?=date('M j, Y H:i', strtotime($t->created_at))?> UTC</p>
        </div>
        <span class="badge <?=$t->status==='OPEN'?'badge-success':($t->status==='CLOSED'?'badge-default':'badge-warning')?>" style="align-self:flex-start"><?=htmlspecialchars($t->status)?></span>
      </div>
      <?php if (!empty($linked_order)): ?>
        <p class="mt-3 text-sm">Related order:
          <a class="text-brand-700" href="<?=site_url('dashboard/orders/'.$linked_order->public_id)?>"><?=htmlspecialchars($linked_order->public_id)?></a>
        </p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 class="card-title">Conversation</h3>
      <ul class="stack mt-3" style="gap:1rem">
        <?php foreach ($messages as $m): ?>
          <li class="ws-msg <?=$m->is_staff?'is-staff':''?>">
            <div class="row justify-between text-xs muted">
              <strong><?=$m->is_staff?'Support':'You'?></strong>
              <span><?=date('M j, H:i', strtotime($m->created_at))?> UTC</span>
            </div>
            <p class="mt-1" style="white-space:pre-wrap"><?=htmlspecialchars($m->message)?></p>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($t->status !== 'CLOSED'): ?>
    <div class="card">
      <h3 class="card-title">Reply</h3>
      <?=form_open('dashboard/tickets/'.$t->public_id.'/reply', array('class'=>'mt-2 stack'))?>
        <textarea class="textarea" name="message" required rows="4" maxlength="20000" placeholder="Type your reply…"></textarea>
        <div class="row" style="justify-content:space-between">
          <button class="btn btn-primary" type="submit">Send reply</button>
      <?=form_close()?>
          <form method="post" action="<?=site_url('dashboard/tickets/'.$t->public_id.'/close')?>">
            <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
            <button class="btn btn-ghost btn-sm" type="submit">Close ticket</button>
          </form>
        </div>
    </div>
    <?php else: ?>
    <div class="card muted text-center">This ticket is closed.
      <form method="post" action="<?=site_url('dashboard/tickets/'.$t->public_id.'/reply')?>" class="mt-2">
        <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
        <input type="hidden" name="message" value="I'd like to reopen this ticket.">
        <button class="btn btn-secondary btn-sm" type="submit">Reopen with a reply</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <aside class="card h-fit">
    <h3 class="card-title">Details</h3>
    <dl class="stack" style="gap:.5rem">
      <div class="row justify-between"><span class="muted">Status</span><strong><?=htmlspecialchars($t->status)?></strong></div>
      <div class="row justify-between"><span class="muted">Priority</span><span><?=htmlspecialchars($t->priority)?></span></div>
      <div class="row justify-between"><span class="muted">Department</span><span><?=htmlspecialchars($t->department)?></span></div>
      <div class="row justify-between"><span class="muted">Last reply</span><span class="text-sm"><?=$t->last_reply_at?date('M j, H:i', strtotime($t->last_reply_at)):'—'?></span></div>
    </dl>
  </aside>
</div>
<style>
.ws-msg{padding:.85rem 1rem;border:1px solid var(--slate-100);border-radius:.75rem;background:#fff}
.ws-msg.is-staff{border-color:var(--brand-200,#c7d2fe);background:var(--brand-50,#eef2ff)}
</style>
