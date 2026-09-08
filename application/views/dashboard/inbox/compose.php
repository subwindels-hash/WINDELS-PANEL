<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card max-w-3xl">
  <div class="row justify-between">
    <h2 class="card-title mb-0">Message the team</h2>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/inbox')?>">← Inbox</a>
  </div>
  <p class="hint mt-2 mb-0">Your message goes straight to the team's inbox in the panel, and their
    reply arrives back here in <strong>your</strong> inbox. No email client needed.</p>

  <form method="post" action="<?=site_url('dashboard/inbox/send')?>" class="mt-4">
    <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
           value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>

    <label class="text-sm font-medium" for="subject">Subject</label>
    <input class="input" type="text" id="subject" name="subject" maxlength="150" required
           placeholder="What is this about?">

    <label class="text-sm font-medium mt-4" for="message">Message</label>
    <textarea class="input" id="message" name="message" rows="7" required
              placeholder="Write your message…"></textarea>

    <div class="row mt-4" style="gap:.5rem">
      <button class="btn btn-primary" type="submit">Send message</button>
      <a class="btn btn-ghost" href="<?=site_url('dashboard/inbox')?>">Cancel</a>
    </div>
  </form>
</div>
