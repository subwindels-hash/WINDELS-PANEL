<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Public contact form.
 *
 * Signed in, the message becomes a support ticket you can follow in the
 * dashboard; signed out, it is emailed to the support address. Either way the
 * form is a plain server-rendered POST — no JavaScript is required for it to
 * work, so there is nothing here that can fail on a second submission.
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
<section class="py-12">
  <div class="container" style="max-width:720px">
    <header class="text-center mb-8">
      <h1>Contact us</h1>
      <p class="muted">
        Questions about an order, a payment or the reseller API — send them here and a
        human answers.
      </p>
    </header>

    <?php if (!empty($success)): ?>
      <div class="card" style="border-color:#a7f3d0;background:#ecfdf5">
        <p style="color:#065f46;margin:0"><?=htmlspecialchars($success)?></p>
      </div>
    <?php else: ?>

      <?php if (!empty($error)): ?>
        <div class="card mb-4" style="border-color:#fecaca;background:#fef2f2">
          <p style="color:#991b1b;margin:0"><?=htmlspecialchars($error)?></p>
        </div>
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
            <p class="muted text-xs mt-1">We only use it to reply.</p>
          </div>

          <div>
            <label class="label" for="contact-subject">Subject</label>
            <input class="input" id="contact-subject" name="subject" type="text" maxlength="150" required
                   value="<?=$value('subject')?>">
          </div>

          <div>
            <label class="label" for="contact-message">Message</label>
            <textarea class="textarea" id="contact-message" name="message" rows="6" maxlength="5000"
                      required placeholder="Order ID, what you expected, what happened…"><?=$value('message')?></textarea>
          </div>

          <?php // Honeypot. Hidden from people, irresistible to bots. ?>
          <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label for="contact-website">Leave this field empty</label>
            <input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off">
          </div>

          <button class="btn btn-primary" type="submit">Send message</button>
        <?=form_close()?>
      </div>

      <p class="muted text-center mt-4 text-sm">
        Prefer email? Write to
        <a href="mailto:<?=htmlspecialchars($support_email ?? 'support@windels.local')?>"><?=htmlspecialchars($support_email ?? 'support@windels.local')?></a>.
      </p>
    <?php endif; ?>
  </div>
</section>
