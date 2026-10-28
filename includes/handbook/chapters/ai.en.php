<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>nibbly can work with an AI service such as OpenAI, Anthropic (Claude) or OpenRouter. This needs your own account with that service and an API key<?php echo $hb->isAdmin ? ', which you enter under Settings › ' . $hb->ui('AI Settings') : ', which an admin enters'; ?>. Without a key, the dashboard shows the notice that AI isn't configured.</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Assistant</h4><p>A chat on the dashboard's start page and, if switched on, in the Visual Editor. It answers questions and prepares changes that you review and release with “Apply changes”. “Undo” takes them back.</p></div>
  <div class="hb-card"><h4>Text and SEO</h4><p>Fills in a page's SEO title, meta description and short answer, suggests texts and translates pages, news, events and profiles into another language.</p></div>
  <div class="hb-card"><h4>Image generator</h4><p>Creates images from a description, in the dashboard and in the image manager. AI images get a label as EU law requires.</p></div>
</div>
<?php if ($hb->isAdmin): ?>
<?php echo $hb->shot('settings-ai.webp', 'AI settings with provider, API key, features and limits', '<b>AI settings.</b> Provider and key, which features are active, monthly budget and what editors may do.'); ?>
<dl class="hb-fields">
  <div><dt>Features</dt><dd>Assistant, text and SEO generation, image generation, and whether the chat appears in the Visual Editor and in the dashboard.</dd></div>
  <div><dt>Limits</dt><dd>A monthly budget and requests per day protect against unexpected costs. <?php echo $hb->ui('Usage this month'); ?> shows where you stand.</dd></div>
  <div><dt>What editors may do</dt><dd>Sets what the assistant may do for editors, such as publishing, generating images or creating new pages.</dd></div>
</dl>
<?php echo $hb->note('Privacy', 'Texts and images you send to the AI go to the chosen provider. Don’t include confidential or personal data that doesn’t belong there.'); ?>
<?php endif; ?>
