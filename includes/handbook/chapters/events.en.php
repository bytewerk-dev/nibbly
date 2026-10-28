<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('Events', 'calendar'); ?> you manage events. The list shows title, date, venue and status (<?php echo $hb->ui('Upcoming'); ?>, <?php echo $hb->ui('Today'); ?>, <?php echo $hb->ui('Past'); ?>). At the top you can also filter for hidden events and the trash.</p>
</div>
<?php echo $hb->shot('events.webp', 'Event list with title, date, venue and status', '<b>Event list.</b> Move over a row and Edit, View, Duplicate and Trash appear.'); ?>

<h3>Adding an event</h3>
<ol class="hb-steps">
  <li><span>Click <?php echo $hb->btn('New event', 'primary', 'plus'); ?> or <?php echo $hb->ui('Edit'); ?> on an existing event. <?php echo $hb->ui('Duplicate'); ?> saves work for recurring events.</span></li>
  <li><span>Under <b>Date &amp; time</b> enter at least the start date, plus start time, end date and end time as needed. An event without a date counts as a draft.</span></li>
  <li><span>Per language, fill in <b>title</b>, <b>venue</b>, <b>description</b> and <b>admission</b>. Optionally an image and a link, for example to registration.</span></li>
  <li><span><?php echo $hb->btn('Save', 'primary'); ?>. Each event gets its own page on the website.</span></li>
</ol>
<?php echo $hb->note('Hiding', 'You can hide an event for a while on the website: in edit mode with the eye on the event. There you can also edit and delete events directly; nibbly saves these changes at once.'); ?>
