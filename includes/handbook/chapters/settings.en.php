<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<?php if (!$hb->isAdmin): ?>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('Settings', 'settings'); ?> you find <?php echo $hb->ui('My Account'); ?>. There you change your password: current password, then the new one twice. A list shows which requirements are already met. All other settings are managed by the admins.</p>
</div>
<?php echo $hb->shot('my-account.webp', 'My Account with the fields for changing the password', '<b>My Account.</b> This is where you change your password.'); ?>
<?php else: ?>
<div class="hb-prose">
  <p>When you click <?php echo $hb->nav('Settings', 'settings'); ?>, the sidebar folds to icons and a second bar with all areas appears next to it. At its top, <?php echo $hb->ui('Search'); ?> finds any setting by keyword. Each area has its own save button at the bottom.</p>
</div>
<h3>Site</h3>
<dl class="hb-fields">
  <div><dt>Branding</dt><dd>Site name, favicon, logos for light and dark, the image for shared links and the logo in the dashboard. Here you can also block the website for search engines, for example while it's being built.</dd></div>
  <div><dt>Design</dt><dd>The look of the dashboard, not of the website: style, light or dark, base colour, font and size. Also how many entries lists show per page.</dd></div>
  <div><dt>Modules</dt><dd>Switch areas on and off, such as news, events or this handbook. Switched-off modules disappear from the dashboard; their content stays stored. Below: upload limit and web version for images.</dd></div>
  <div><dt>Language</dt><dd>The dashboard's language and the website's languages: order, main language and new languages, optionally starting with all content of an existing language.</dd></div>
</dl>
<?php echo $hb->shot('settings-modules.webp', 'Settings, Modules area, with switches for the modules; Handbook is marked', '<b>Modules.</b> The “Handbook” switch hides this guide and every link to it.'); ?>
<h3>Features</h3>
<dl class="hb-fields">
  <div><dt>Access</dt><dd><b>Maintenance mode</b>: visitors see a notice page (“Maintenance”, “Back soon” or a countdown); signed-in users see the website as usual. A secret link lets others take a look in advance.</dd></div>
  <div><dt>Email</dt><dd>How the website sends email and where form messages go. <?php echo $hb->ui('Send Test Email'); ?> checks the setup. Without email sending there's also no “Forgot password?”.</dd></div>
  <div><dt>Privacy</dt><dd>Hide email addresses from spam robots and switch the anonymous visitor statistics on or off.</dd></div>
  <div><dt>Forms</dt><dd>Fields, texts and behaviour of the forms, such as the contact form.</dd></div>
  <?php if ($hb->module('ai')): ?><div><dt>AI Settings</dt><dd>See <?php echo $hb->ref('ai'); ?>.</dd></div><?php endif; ?>
</dl>
<h3>Users</h3>
<dl class="hb-fields">
  <div><dt>Users</dt><dd>Create and manage accounts, see <?php echo $hb->ref('users'); ?>.</dd></div>
  <div><dt>My Account</dt><dd>Change your own password.</dd></div>
  <div><dt>Login</dt><dd>Where you land after signing in (back to the page or always the dashboard) and how the sign-in page looks.</dd></div>
</dl>
<?php endif; ?>
