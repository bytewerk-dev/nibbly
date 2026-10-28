<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <ul>
    <li><b>Quick way to editing:</b> if you're signed in and click twice on an empty spot of the website, nibbly asks whether you want to switch on the Visual Editor.</li>
    <li><b>Back to the page:</b> if you sign in via the website's footer, you land back on the page you came from.</li>
    <li><b>Large photos</b> don't need shrinking. nibbly creates a web version automatically on upload.</li>
    <li><b>Always fill in the alt text:</b> it describes the image for screen readers and search engines.</li>
    <li><b>Statistics:</b> the dashboard's start page shows views and visits of recent days, without cookies and without bots. Your own visits as a signed-in admin don't count.</li>
    <li><b>This handbook</b> is always available via <?php echo $hb->ui('Handbook'); ?>: in the dashboard at the top left next to the site name, and on the website in the bar next to “Dashboard”. At the top right, <?php echo $hb->icon('moon', 'Dark mode'); ?> switches between light and dark.</li>
  </ul>
</div>
