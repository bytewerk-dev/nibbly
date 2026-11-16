<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('Newsletter', 'mail'); ?> you manage subscribers, write campaigns and send them. The area has five tabs: <?php echo $hb->ui('Campaigns'); ?>, <?php echo $hb->ui('Subscribers'); ?>, <?php echo $hb->ui('Lists'); ?>, <?php echo $hb->ui('Log'); ?> and, for admins, <?php echo $hb->ui('Settings'); ?>.</p>
</div>
<h3>Subscribers and lists</h3>
<div class="hb-prose">
  <p>Subscribers arrive through the sign-up form on the website, through <?php echo $hb->ui('Import'); ?> (a CSV file with a header row) or one by one with <?php echo $hb->ui('Add subscriber'); ?>. Only add people who have agreed to receive the newsletter.</p>
  <p>Every subscriber has a status:</p>
</div>
<dl class="hb-fields">
  <div><dt>Awaiting confirmation</dt><dd>Signed up but has not yet clicked the link in the confirmation mail. Receives no newsletter. nibbly sends reminders by itself; the list can also send the confirmation again by hand.</dd></div>
  <div><dt>Subscribed</dt><dd>Receives campaigns.</dd></div>
  <div><dt>Unsubscribed</dt><dd>Used the link in a mail to unsubscribe and receives nothing more.</dd></div>
  <div><dt>Undeliverable</dt><dd>The mail server refused the address for good.</dd></div>
</dl>
<div class="hb-prose">
  <p><b>Lists</b> group subscribers, for example by topic. Without lists every campaign goes to all confirmed subscribers. <?php echo $hb->ui('Export CSV'); ?> downloads the subscribers of the current filter.</p>
</div>
<h3>Writing a campaign</h3>
<ol class="hb-steps">
  <li><span>Choose <?php echo $hb->btn('New campaign', 'primary', 'plus'); ?> and enter the <b>subject</b> and the <b>preview text</b>. The preview text appears in the inbox after the subject.</span></li>
  <li><span>Tick the lists under <b>Recipients</b>. Without a selection the campaign goes to everyone.</span></li>
  <li><span>Build the content from <b>blocks</b>: heading, text, image, button, divider, spacer and news post. The <?php echo $hb->ui('News post'); ?> block takes over title, teaser, image and link of a post. The arrows move a block, the bin removes it.</span></li>
  <li><span>The <b>preview</b> on the right shows how the mail arrives, switchable between desktop and phone.</span></li>
  <li><span><?php echo $hb->ui('Send test'); ?> sends the campaign to an address of your choice.</span></li>
  <li><span><?php echo $hb->ui('Send…'); ?> sends right away or at a set time.</span></li>
</ol>
<?php echo $hb->note('Placeholders', 'In subject and text nibbly replaces placeholders with the subscriber’s data: <code>{firstname}</code>, <code>{lastname}</code>, <code>{email}</code>. A fallback after the vertical bar keeps the sentence complete without a name: <code>Hello {firstname|everyone}</code>.'); ?>
<h3>Sending and report</h3>
<div class="hb-prose">
  <p>Mails go out in batches, so the mail server accepts them. While the dashboard is open, sending continues by itself; with a cron job (newsletter settings) it also runs in the background, which scheduled campaigns need. A daily limit protects against the provider’s limits. Once it is reached, the rest goes out the next day.</p>
  <p>The <b>report</b> of a campaign shows how many mails were sent, who unsubscribed and which addresses failed. nibbly measures opens and clicks only when this is switched on in the settings. <?php echo $hb->ui('Pause'); ?> halts a running campaign. After repeated errors nibbly pauses by itself and names the reason.</p>
  <p>The <?php echo $hb->ui('Log'); ?> tab lists every outgoing mail of the last 30 days with recipient, subject and result, including confirmations and tests. On failure it shows the mail server’s answer, which helps when someone says a mail did not arrive.</p>
  <p>Every mail ends with an unsubscribe link. Many mail programs also show an unsubscribe button of their own.</p>
</div>
<h3>Setting up (admins)</h3>
<dl class="hb-fields">
  <div><dt>Sender and delivery</dt><dd>Delivery through the website’s mail setup, through <b>Gmail</b> or your own SMTP server. Gmail needs 2-step verification and an app password from your Google account; it allows about 500 mails per day.</dd></div>
  <div><dt>Sign-up</dt><dd>Double opt-in (recommended), reminders, the consent text of the form and your own fields. The form goes on a page as the block <?php echo $hb->ui('Newsletter sign-up'); ?>.</dd></div>
  <div><dt>Statistics</dt><dd>Measuring opens and clicks. This is personal data: switch it on only with a matching consent and a note in your privacy policy.</dd></div>
  <div><dt>Design</dt><dd>Accent colour, background, logo and the footer text. In commercial mails the imprint belongs there.</dd></div>
</dl>
<h3>The sign-up form on the website</h3>
<div class="hb-prose">
  <p>The easiest way is to add the block <?php echo $hb->ui('Newsletter sign-up'); ?> to a page. It asks for the email address, optionally for the first name, and can add people to a list.</p>
  <p>If the form should sit in a template for good, for example in the footer, the person who looks after the website places this line in the template:</p>
  <pre><code>&lt;?php require_once $_includeBase . 'includes/newsletter.php'; echo nibblyNewsletterForm(); ?&gt;</code></pre>
  <p>While the module is switched off, the line outputs nothing.</p>
</div>
<div class="hb-prose">
  <p>Links in the mails need the <b>website address</b>, see <?php echo $hb->ref('settings'); ?>.</p>
</div>
