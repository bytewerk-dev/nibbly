<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('Menu/Footer', 'menu'); ?> you edit the website's menus, such as the main navigation at the top, and below them the details in the footer. On multilingual websites you pick the language at the top.</p>
</div>
<?php echo $hb->shot('menus.webp', 'Menus and footer with the main navigation and its entries', '<b>Menus &amp; Footer.</b> Each entry has a handle for moving on the left and the “Visible” switch on the right.'); ?>
<dl class="hb-fields">
  <div><dt>Editing an entry</dt><dd>Expand the entry, then change the <b>label</b> (the text in the menu) and the <b>link target</b>. Under <b>Open in</b> you choose whether the link opens in the same or a new window.</dd></div>
  <div><dt>Show and hide</dt><dd>The <?php echo $hb->toggle(true, 'Visible'); ?> <b>Visible</b> switch hides a menu item without deleting it.</dd></div>
  <div><dt>Order</dt><dd>Drag the handle <?php echo $hb->icon('grip', 'Handle'); ?> or click <?php echo $hb->ui('Move up'); ?> and <?php echo $hb->ui('Move down'); ?> in the expanded entry. <?php echo $hb->ui('Sub-entry'); ?> indents an item below the previous one, <?php echo $hb->ui('Up a level'); ?> brings it back.</dd></div>
  <div><dt>New menu item</dt><dd><?php echo $hb->btn('Add entry', 'secondary', 'plus'); ?>, then pick an <b>existing page</b> or enter a <b>free link</b> and <?php echo $hb->ui('Add'); ?>.</dd></div>
  <div><dt>Deleting</dt><dd><?php echo $hb->ui('Remove'); ?> in the expanded entry. The page itself stays.</dd></div>
</dl>

<h3>Footer</h3>
<div class="hb-prose">
  <p>Below the menus are the footer's fields: texts, links, link groups, logo and contact details such as phone, email and address. What's here appears on every page.</p>
</div>
<?php echo $hb->note('Don’t forget to save', 'nibbly applies changes to menus and footer only with “Save changes” in the bar at the bottom. “Discard” undoes everything since the last save. Before every change nibbly makes a backup; under “Backups” you bring back earlier versions.'); ?>
