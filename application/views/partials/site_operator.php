<?php defined('BASEPATH') OR exit('No direct script access allowed');
$site_name = function_exists('marvy_site_name') ? marvy_site_name() : 'MarvySocials';
$suggestions = class_exists('SiteOperatorKnowledge')
    ? SiteOperatorKnowledge::suggested_questions()
    : array('What services can I order?', 'How does pricing work?', 'How do I create an account?');
$welcome = class_exists('SiteOperatorKnowledge')
    ? SiteOperatorKnowledge::assistant_disclaimer()
    : 'On-site assistant for '.$site_name.'.';
?>
<button type="button" class="ws-assistant-launch" id="ws-assistant-launch"
        aria-controls="ws-assistant" aria-expanded="false"
        aria-haspopup="dialog" title="Open the <?=htmlspecialchars($site_name)?> assistant">
  <img src="<?=base_url('assets/images/ai/avatar.jpg')?>" alt="" width="28" height="28" class="ws-avatar" style="width:28px;height:28px">
  <span>Assistant</span>
</button>
<section class="ws-assistant" id="ws-assistant" hidden role="dialog" aria-modal="false"
         data-assistant data-endpoint="<?=htmlspecialchars(site_url('assistant/chat'))?>"
         aria-label="Site assistant">
  <header class="ws-assistant-head">
    <div class="row" style="gap:.7rem">
      <img src="<?=base_url('assets/images/ai/avatar.jpg')?>" alt="" width="40" height="40" class="ws-avatar">
      <div>
      <h2>Site assistant</h2>
      <p class="muted" style="margin:0;font-size:.8rem">Built-in knowledge · no external AI API</p>
      </div>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="ws-assistant-close">Close</button>
  </header>
  <div class="ws-assistant-log" id="ws-assistant-log">
    <div class="ws-bubble ws-bubble-assistant"><?=htmlspecialchars($welcome)?>

Ask about services, pricing, accounts or where to find a page.</div>
  </div>
  <div class="ws-suggest" id="ws-assistant-suggest">
    <?php foreach ($suggestions as $q): ?>
      <button type="button" data-suggest="<?=htmlspecialchars($q)?>"><?=htmlspecialchars($q)?></button>
    <?php endforeach; ?>
  </div>
  <div class="ws-assistant-status" id="ws-assistant-status" aria-live="polite"></div>
  <form class="ws-assistant-form" id="ws-assistant-form" data-no-guard>
    <label class="sr-only" for="ws-assistant-input">Your question</label>
    <input class="input" id="ws-assistant-input" name="message" autocomplete="off"
           maxlength="1000" placeholder="Ask about the panel…">
    <button class="btn btn-primary" type="submit" id="ws-assistant-send">Send</button>
  </form>
</section>
