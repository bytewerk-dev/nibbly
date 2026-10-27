<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>While you're signed in, you see a bar at the top of every page of the website. With it you edit exactly the page you're on. Visitors don't see this bar.</p>
</div>
<?php echo $hb->shot('adminbar.webp', 'Home page with the bar for signed-in users; Visual Editor and Handbook are marked', '<b>The bar for signed-in users.</b> In the middle, “Visual Editor” switches on edit mode; on the left, Dashboard and Handbook lead on.'); ?>

<h3>Switching on edit mode</h3>
<ol class="hb-steps">
  <li><span>On the website, go to the page you want to change and click <?php echo $hb->bar('Visual Editor', 'primary'); ?> at the top.</span></li>
  <li><span>Move the mouse over the page. Everything you can edit gets a frame: texts, images, links and whole sections.</span></li>
  <li><span>Click and change what you want. The next chapters show how this works for <?php echo $hb->ref('texts'); ?>, <?php echo $hb->ref('links'); ?> and <?php echo $hb->ref('images'); ?>.</span></li>
  <li><span>Finally <?php echo $hb->bar('Save & Exit', 'primary', 'check'); ?>. More on this in <?php echo $hb->ref('saving'); ?>.</span></li>
</ol>
<?php echo $hb->shot('edit-mode.webp', 'Edit mode with framed texts; Save and Save & Exit are marked', '<b>Edit mode.</b> Editable parts are framed; at the top are Undo, Cancel and the two save buttons.'); ?>

<h3>Sections</h3>
<div class="hb-prose">
  <p>A page is made of sections (blocks): a text block, an image, a row of cards and so on. When you move over a section in edit mode, a small toolbar appears at its top right:</p>
</div>
<dl class="hb-fields">
  <div><dt><?php echo $hb->icon('edit', 'Edit'); ?> Edit</dt><dd>Opens all fields of the section in a form, including those you can't click directly on the page.</dd></div>
  <div><dt><?php echo $hb->icon('eye', 'Hide'); ?> Hide</dt><dd>Hides the section from visitors without deleting it. A second click shows it again. Single fields and list items have the same eye.</dd></div>
  <div><dt><?php echo $hb->icon('trash', 'Delete'); ?> Delete</dt><dd>Removes the section after asking.</dd></div>
  <div><dt><?php echo $hb->icon('grip', 'Drag to reorder'); ?> Move</dt><dd>Drag the handle to move the section up or down.</dd></div>
  <div><dt><?php echo $hb->icon('plus', 'Add block'); ?> Add block</dt><dd>The plus below a section inserts a new block at that point. You pick the type from a list with a search field, such as text, heading, image, list, card or YouTube.</dd></div>
</dl>
<?php echo $hb->shot('sections.webp', 'An image section in edit mode with its toolbar at the top right', '<b>A section’s toolbar.</b> Edit, Hide, Delete and the handle for moving.'); ?>
<?php echo $hb->note('Saved at once', 'When you add or delete a section or list item, nibbly saves immediately, together with all your other open changes, and reloads the page. Everything else, including moving and hiding, waits for Save.'); ?>

<h3>Content Editor</h3>
<div class="hb-prose">
  <p>Next to the Visual Editor is <?php echo $hb->bar('Content Editor'); ?>. It shows the same content as a form in the dashboard, field by field. That's handy for fields you can't see on the page, such as the description for search engines. For normal changes, editing right on the page is usually easier.</p>
</div>

<h3>Footer</h3>
<div class="hb-prose">
  <p>The footer is the same on every page, so changes to it apply to the whole website. In edit mode, clicking a footer entry opens the <?php echo $hb->ui('Edit footer'); ?> window with link text and address, per language. This, too, is applied only when you save.<?php if ($hb->module('menus') && $hb->isAdmin): ?> You also manage the footer's links and contact details together under <?php echo $hb->ref('menus'); ?>.<?php endif; ?></p>
</div>
