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

      <?php
        // Operator-controlled contact block (Admin → Settings → Contact page).
        //
        // The map is first-party: ContactMapService resolves the operator's
        // query on the server ("lat,lng" locally, free text with one cached
        // geocode) and the nine OSM tiles around the point are fetched by
        // the server and served from THIS origin at /contact/map/tile/…
        // A visitor on this page makes no request to any third party; the
        // "Open in maps" button is the single, user-initiated exception.
        // When the query cannot be resolved (no outbound route, an unknown
        // place) the map box is omitted entirely and the printed details
        // stand in for it — no broken image, nothing to disclose.
        $cd  = $contact_details ?? array();
        $map = $map_context ?? array('enabled' => false, 'resolved' => false,
                                     'map_key' => null, 'tiles' => null,
                                     'marker' => null, 'search' => null);
        $address = trim((string)($cd['address'] ?? ''));
        $phone   = trim((string)($cd['phone'] ?? ''));
        $hours   = trim((string)($cd['hours'] ?? ''));
      ?>
      <?php if (!empty($map['resolved']) || $address !== '' || $phone !== '' || $hours !== ''): ?>
      <div class="card mt-6">
        <h2 class="card-title">Find us</h2>
        <div class="grid grid-2" style="gap:1.25rem;align-items:start">
          <div>
            <?php if ($address !== ''): ?>
              <h3 class="text-sm font-semibold mb-1">Address</h3>
              <p class="muted" style="white-space:pre-line;margin-top:0"><?=htmlspecialchars($address)?></p>
            <?php endif; ?>
            <?php if ($phone !== ''): ?>
              <h3 class="text-sm font-semibold mb-1 mt-3">Phone</h3>
              <p class="muted" style="margin-top:0">
                <a href="tel:<?=htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone))?>"><?=htmlspecialchars($phone)?></a>
              </p>
            <?php endif; ?>
            <?php if ($hours !== ''): ?>
              <h3 class="text-sm font-semibold mb-1 mt-3">Support hours</h3>
              <p class="muted" style="margin-top:0"><?=htmlspecialchars($hours)?></p>
            <?php endif; ?>
            <?php if (!empty($map['search'])): ?>
              <p class="mt-3 mb-0">
                <a class="btn btn-secondary btn-sm" href="<?=htmlspecialchars($map['search'])?>"
                   target="_blank" rel="noopener noreferrer">Open in maps</a>
              </p>
            <?php endif; ?>
          </div>
          <?php if (!empty($map['resolved']) && is_array($map['tiles'])): ?>
            <div class="ws-map ws-map-grid" role="img"
                 aria-label="Map showing our location at <?=htmlspecialchars((string)($cd['map_query'] ?? 'our address'))?>">
              <?php foreach ($map['tiles'] as $idx => $tile):
                    $i = $idx % 3; $j = intdiv($idx, 3); ?>
                <img class="ws-map-tile" src="<?=htmlspecialchars($tile)?>" alt=""
                     loading="lazy" width="256" height="256" decoding="async"
                     style="grid-column:<?= $i + 1 ?>;grid-row:<?= $j + 1 ?>">
              <?php endforeach; ?>
              <?php if (!empty($map['marker'])): ?>
                <span class="ws-map-pin" aria-hidden="true"
                      style="left:<?=htmlspecialchars($map['marker']['left']) ?>%;top:<?=htmlspecialchars($map['marker']['top']) ?>%"></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

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
