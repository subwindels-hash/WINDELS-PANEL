<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<section class="ws-page-hero">
  <div class="container" style="max-width:720px">
    <p class="ws-kicker">On-site assistant</p>
    <h1>Ask the panel, not a cloud model</h1>
    <p class="ws-lede">This assistant matches your question to MarvySocials’s built-in knowledge. It does not call OpenAI, Anthropic, Gemini or any other third-party AI API, and it cannot complete account actions for you.</p>
    <p>Use the Assistant button at the bottom of any public page, or type below if you prefer a full page.</p>
  </div>
</section>
<section class="ws-section-sm">
  <div class="container" style="max-width:640px">
    <div class="card">
      <p><?=htmlspecialchars(class_exists('SiteOperatorKnowledge') ? SiteOperatorKnowledge::assistant_disclaimer() : '')?></p>
      <button type="button" class="btn btn-primary btn-block mt-4" data-open-assistant>Open the assistant</button>
      <p class="muted" style="margin-top:.75rem">Suggested starting points:</p>
      <ul>
        <?php foreach ((class_exists('SiteOperatorKnowledge') ? SiteOperatorKnowledge::suggested_questions() : array()) as $q): ?>
          <li><?=htmlspecialchars($q)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>
