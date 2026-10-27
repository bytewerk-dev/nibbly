<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<dl class="hb-fields">
  <div><dt>Strong password</dt><dd>At least 8 characters, with upper- and lower-case letters, a digit and a special character. If your password doesn't meet this, the dashboard shows a warning with a link to change it after you sign in.</dd></div>
  <div><dt>Your own account</dt><dd>Everyone gets their own account. Don't share passwords, not even within the team.</dd></div>
  <div><dt>Signing out</dt><dd>Always sign out on other people's or shared computers. Without activity the session ends after one hour by itself.</dd></div>
  <div><dt>Sign-in protection</dt><dd>Repeated failed attempts slow down and block sign-ins, see <?php echo $hb->ref('login'); ?>.</dd></div>
  <div><dt>Private pages</dt><dd>Password-protected pages and maintenance mode are blocked for search engines. Confidential material still doesn't belong on the website.</dd></div>
</dl>
<?php if ($hb->isAdmin): ?>
<h3>System status</h3>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('System status', 'shield'); ?> nibbly checks the server: whether internal folders can be reached from outside, whether the website runs on https, whether search engines may find it, when the last backup was and which upload limits apply. For each problem it shows how to fix it. The dashboard also shows serious problems as a warning at the top.</p>
</div>
<?php echo $hb->shot('system.webp', 'System status with the areas security, search engines, PHP, storage and uploads', '<b>System status.</b> A badge on the menu item shows when something needs checking.'); ?>
<?php endif; ?>
