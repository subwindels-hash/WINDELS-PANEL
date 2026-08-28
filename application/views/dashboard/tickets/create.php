<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Full-page "open a ticket" form.
 *
 * The tickets index opens this same form inside a <dialog>, but the empty
 * state links straight here — and a customer who follows that link used to
 * meet a 404, because create() only answered POST. This page is that missing
 * GET half: same fields, same POST endpoint, plus a send button that is
 * visible without JavaScript.
 */
$old = $old_input ?? array();
$val = static function (string $key, string $default = '') use ($old) {
    return htmlspecialchars((string)($old[$key] ?? $default));
};
$order_prefill = $val('order_id', trim((string)($order_prefill ?? '')));
?>
<nav class="text-sm muted mb-4">
  <a href="<?=site_url('dashboard/tickets')?>">Support</a> · <span class="text-slate-700">Open a ticket</span>
</nav>

<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2">
    <div class="card">
      <h2 class="card-title">Open a ticket</h2>
      <p class="muted text-sm mt-1">
        Describe the problem in one place — our support team replies in the same thread,
        and every message is kept with the ticket.
      </p>

      <?=form_open_multipart('dashboard/tickets/create', array('class' => 'stack mt-4', 'novalidate' => true))?>
        <?php if (!empty($form_error)): ?>
          <div class="alert alert-danger"><?=htmlspecialchars((string)$form_error)?></div>
        <?php endif; ?>
        <label class="field"><span class="label">Subject *</span>
          <input class="input" name="subject" required maxlength="255"
                 placeholder="e.g. Order not progressing" value="<?=$val('subject')?>">
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="field"><span class="label">Department</span>
            <select class="select" name="department">
              <?php foreach (array('orders', 'payments', 'api', 'other') as $dept): ?>
                <option value="<?=$dept?>" <?=$val('department', 'orders') === $dept ? 'selected' : ''?>><?=ucfirst($dept)?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span class="label">Priority</span>
            <select class="select" name="priority">
              <?php foreach (array('LOW', 'MEDIUM', 'HIGH') as $prio): ?>
                <option value="<?=$prio?>" <?=$val('priority', 'MEDIUM') === $prio ? 'selected' : ''?>><?=ucfirst(strtolower($prio))?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label class="field"><span class="label">Related order (optional, ULID)</span>
          <input class="input mono" name="order_id" maxlength="26" placeholder="01ORDER..."
                 value="<?=$order_prefill?>">
          <span class="hint">Found on the Orders page — links the thread to the order support will read.</span>
        </label>

        <label class="field"><span class="label">Message *</span>
          <textarea class="textarea" name="message" required rows="7" maxlength="20000"
                    placeholder="What happened, what you expected, and any links or references that help us check."><?=$val('message')?></textarea>
        </label>

        <div>
          <label class="text-sm font-medium" for="new-attachments">Attach files (optional)</label>
          <input class="input" id="new-attachments" type="file" name="attachments[]" multiple
                 accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
          <p class="hint">A screenshot usually answers the first question support would ask. Up to 5 files.</p>
        </div>

        <div class="row" style="justify-content:flex-end;gap:.5rem">
          <a class="btn btn-ghost" href="<?=site_url('dashboard/tickets')?>">Cancel</a>
          <button type="submit" class="btn btn-primary">Send ticket to support</button>
        </div>
      <?=form_close()?>
    </div>
  </div>

  <aside class="card h-fit">
    <h3 class="card-title">Before you send</h3>
    <ul class="stack text-sm muted" style="gap:.5rem;list-style:disc;padding-left:1.1rem">
      <li>One ticket per problem — replies land in the same thread.</li>
      <li>Include the order reference when the question is about a purchase.</li>
      <li>Never send passwords or full card numbers; a screenshot of a receipt is enough.</li>
    </ul>
  </aside>
</div>
