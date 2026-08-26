<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Public contact form.
 *
 * Signed in, the message becomes a support ticket you can follow in the
 * dashboard; signed out, it is emailed to the support address.
 *
 * @var string $support_email
 * @var string $error
 * @var string $success
 * @var array  $form
 */
$form = isset($form) ? $form : array();
$value = function ($key) use ($form) {
    return isset($form[$key]) ? htmlspecialchars($form[$key], ENT_QUOTES) : '';
};
?>
<section class="ws-page-hero">
  <div class="container" style="max-width:1100px">
    <div class="ws-hero-split">
      <div>
        <p class="ws-kicker">Support</p>
        <h1>Contact us</h1>
        <p class="ws-lede">Questions about an order, a payment or the reseller API — send them here. A person answers. The on-site assistant cannot open a ticket for you.</p>
      </div>
      <div class="ws-hero-media">
        <img src="<?=base_url('assets/images/faq/hero.jpg')?>" alt="Soft architectural light over a knowledge space — contact is a human conversation, not a call-centre stock photo." width="800" height="600" loading="lazy">
      </div>
    </div>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container" style="max-width:720px">
    <?php if (!empty($success)): ?>
      <div class="alert alert-success" role="status"><?=htmlspecialchars($success)?></div>
      <p><a class="btn btn-secondary" href="<?=site_url()?>">Back to home</a></p>
    <?php else: ?>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert"><?=htmlspecialchars($error)?></div>
      <?php endif; ?>

      <?php if (!empty($current_user)): ?>
        <p class="muted mb-4">
          You are signed in as <strong><?=htmlspecialchars($current_user->username)?></strong>,
          so this will open a support ticket you can track in
          <a href="<?=site_url('dashboard/tickets')?>">your dashboard</a>.
        </p>
      <?php endif; ?>

      <div class="card">
        <?=form_open('contact', array('class'=>'stack', 'novalidate'=>'novalidate'))?>
          <div>
            <label class="label" for="contact-name">Your name</label>
            <input class="input" id="contact-name" name="name" type="text" maxlength="100" required
                   autocomplete="name" value="<?=$value('name')?>">
          </div>

          <div>
            <label class="label" for="contact-email">Email</label>
            <input class="input" id="contact-email" name="email" type="email" maxlength="255" required
                   autocomplete="email"
                   value="<?=$value('email') ?: (!empty($current_user) ? htmlspecialchars($current_user->email, ENT_QUOTES) : '')?>">
            <p class="hint">We only use it to reply.</p>
          </div>

          <div>
            <label class="label" for="contact-subject">Subject</label>
            <input class="input" id="contact-subject" name="subject" type="text" maxlength="150" required
                   placeholder="Brief summary of your question"
                   value="<?=$value('subject')?>">
          </div>

          <div>
            <label class="label" for="contact-department">Topic</label>
            <select class="select" id="contact-department" name="department">
              <option value="orders" <?=($value('department')==='orders'?'selected':'')?>>Service / order support</option>
              <option value="payments" <?=($value('department')==='payments'?'selected':'')?>>Billing / payments</option>
              <option value="api" <?=($value('department')==='api'?'selected':'')?>>API support</option>
              <option value="other" <?=($value('department')==='other'?'selected':'')?>>Account / general inquiry</option>
            </select>
            <p class="hint">Signed-in customers get a ticket routed to the right team.</p>
          </div>

          <div>
            <label class="label" for="contact-message">Message</label>
            <textarea class="textarea" id="contact-message" name="message" rows="6" maxlength="5000"
                      required placeholder="Order ID, what you expected, what happened…"><?=$value('message')?></textarea>
          </div>

          <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label for="contact-website">Leave this field empty</label>
            <input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off">
          </div>

          <button class="btn btn-primary" type="submit" data-loading-text="Sending…">Send message</button>
        <?=form_close()?>
      </div>

      <div class="grid grid-3 mt-6">
        <div class="card">
          <h2 class="card-title">Email</h2>
          <p class="muted">Prefer a mailbox?
            <a href="mailto:<?=htmlspecialchars($support_email ?? 'support@marvy.local')?>"><?=htmlspecialchars($support_email ?? 'support@marvy.local')?></a>
          </p>
        </div>
        <div class="card">
          <h2 class="card-title">Tickets</h2>
          <p class="muted">Signed-in customers should use tickets so replies stay on the order history.</p>
        </div>
        <div class="card">
          <h2 class="card-title">What to include</h2>
          <p class="muted">Public order ID, approximate time, and what you already tried. Do not send passwords or full card numbers.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
