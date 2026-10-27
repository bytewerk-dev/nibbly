<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('People', 'people'); ?> you keep profiles, for example of the team, speakers or contacts. You create a profile once and show it on as many pages as you like. Profiles have nothing to do with the user accounts for the dashboard.</p>
</div>
<?php echo $hb->shot('people.webp', 'People list with name, status and groups', '<b>People.</b> At the top you filter by status and groups.'); ?>

<h3>Creating a profile</h3>
<ol class="hb-steps">
  <li><span>Click <?php echo $hb->btn('New Person', 'primary', 'plus'); ?>.</span></li>
  <li><span>Enter the <b>name</b> (required), <b>position</b> and <b>organisation</b>, plus a <b>short description</b> and the full <b>biography</b>.</span></li>
  <li><span>Pick or upload a <b>photo</b>, with alt text and photo credit.</span></li>
  <li><span>Under <b>more details</b> are quote, groups, order and contact details. Note: website, email and phone are publicly visible once the profile is published.</span></li>
  <li><span><?php echo $hb->btn('Save as draft'); ?> while something is still missing, or <?php echo $hb->btn('Publish', 'primary'); ?>.</span></li>
</ol>
<dl class="hb-fields">
  <div><dt>Groups</dt><dd>Several groups separated by commas, such as “Team, Board”. This lets you show a specific group on a page.</dd></div>
  <div><dt>Order</dt><dd>Smaller numbers come first; with equal numbers, alphabetically.</dd></div>
</dl>

<h3>Showing profiles on a page</h3>
<div class="hb-prose">
  <p>In edit mode you insert a block via <?php echo $hb->icon('plus', 'Add block'); ?>: <b>Person</b> shows a single profile, <b>Team / group of people</b> a whole group in two to four columns. Only published profiles appear on the website; each also has its own page.</p>
</div>
