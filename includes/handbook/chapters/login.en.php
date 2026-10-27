<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<ol class="hb-steps">
  <li><span>Open your website's address with <b>/admin</b> at the end. Alternatively, double-click the year in the copyright line at the very bottom of the website's footer.</span></li>
  <li><span>Enter your username and password and click <?php echo $hb->btn('Login', 'primary'); ?>. The eye in the password field shows the password in plain text.</span></li>
  <li><span>You land in the dashboard. All areas are listed on the left; at the top right, <?php echo $hb->ui('View Site'); ?> takes you to the website and keeps you signed in. At the top left, next to the site name, <?php echo $hb->ui('Handbook'); ?> opens this guide in a new tab.</span></li>
</ol>
<?php echo $hb->pair(
    $hb->shot('login.webp', 'Sign-in page with fields for username and password', '<b>The sign-in page.</b> Username and password are marked.'),
    $hb->shot('dashboard.webp', 'Dashboard with the sidebar and the link to the handbook', '<b>The dashboard.</b> The areas on the left, the link to this handbook at the top.')
); ?>

<h3>Forgot your password</h3>
<div class="hb-prose">
  <p>Once the website is allowed to send email, <?php echo $hb->ui('Forgot password?'); ?> appears below the sign-in form. Enter your account's email address and nibbly sends you a link to reset your password. The link is valid for one hour. Without email sending, or without an email address in your account, an admin resets your password.</p>
  <p>After three wrong attempts you have to wait a little longer before each new try; the button shows the waiting time. After many failed attempts, nibbly blocks sign-ins from that connection for 24 hours. This protects against attacks that try out passwords.</p>
</div>

<h3>Signing out</h3>
<div class="hb-prose">
  <p>In the dashboard, <?php echo $hb->nav('Log Out', 'logout'); ?> is at the bottom of the sidebar; on the website it's at the far right of the bar. On the website you stay on the same page afterwards. Always sign out, especially on other people's computers. After one hour without activity nibbly ends the session by itself; just sign in again. In edit mode nibbly keeps the session open as long as you're working.</p>
</div>
