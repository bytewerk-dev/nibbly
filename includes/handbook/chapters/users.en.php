<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>nibbly has two roles:</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Admin</h4><p>May do everything, including menus, media library, settings, users, backups and system status.</p></div>
  <div class="hb-card"><h4>Editor</h4><p>For editorial work: pages, news, events, people and messages, the Visual Editor and uploading images. Of the settings, only “My Account”.</p></div>
</div>
<?php echo $hb->shot('users.webp', 'Settings, Users area, with the list of accounts', '<b>Users.</b> Name, email, role and last sign-in of each account.'); ?>
<h3>Adding a user</h3>
<ol class="hb-steps">
  <li><span>Settings › <?php echo $hb->ui('Users'); ?> › <?php echo $hb->btn('Add User', 'primary', 'plus'); ?>.</span></li>
  <li><span>Choose a <b>username</b> (at least three characters), <b>email</b> and <b>role</b>. Without an email address, “Forgot password?” doesn't work for this account.</span></li>
  <li><span>Enter a <b>password</b> or let <?php echo $hb->ui('Generate'); ?> create one, and pass it on securely. The person then changes it under <?php echo $hb->ui('My Account'); ?>.</span></li>
</ol>
<div class="hb-prose">
  <p>In the list, <?php echo $hb->ui('Password'); ?> sets a new password for someone who forgot theirs. The last admin can neither be deleted nor turned into an editor, so nobody locks themselves out.</p>
</div>
