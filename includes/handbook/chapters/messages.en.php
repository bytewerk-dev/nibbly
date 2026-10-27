<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>What visitors send via a form on the website, such as the contact form, ends up under <?php echo $hb->nav('Messages', 'mail'); ?>. A number on the menu item shows unread messages. If email sending is set up, messages also arrive by email.</p>
</div>
<?php echo $hb->shot('messages.webp', 'List of contact messages with sender, subject and date received', '<b>Messages.</b> Unread ones are bold. The star marks important messages.'); ?>
<dl class="hb-fields">
  <div><dt>Reading</dt><dd>Clicking a row opens the message and marks it as read. With the symbols in the row you set it back to unread or add a star.</dd></div>
  <div><dt>Tidying up</dt><dd><?php echo $hb->ui('Mark all as read'); ?> and deleting read messages are at the top.</dd></div>
  <div><dt>Deleting</dt><dd>In the opened message. Deleted messages are gone for good; there is no trash.</dd></div>
</dl>
<?php if ($hb->isAdmin): ?>
<div class="hb-prose">
  <p>Which fields a form has, and whether it stores messages or sends them by email, you set under Settings › <?php echo $hb->ui('Forms'); ?>. If something is missing, the messages page shows a hint at the top with a link there.</p>
</div>
<?php endif; ?>
