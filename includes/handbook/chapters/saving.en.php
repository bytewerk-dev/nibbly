<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>In edit mode the bar at the top holds four tools. Changes to texts, images and links go online only when you save. Until then only you see them.</p>
</div>
<dl class="hb-fields">
  <div><dt><?php echo $hb->icon('undo', 'Undo'); ?> <?php echo $hb->icon('redo', 'Redo'); ?></dt><dd>Undo and redo, step by step. Keyboard: <?php echo $hb->key('⌘ Z', 'Ctrl Z'); ?> and <?php echo $hb->key('⌘ ⇧ Z', 'Ctrl ⇧ Z'); ?>. While you're typing in a text, the keys only affect that text.</dd></div>
  <div><dt><?php echo $hb->bar('Cancel'); ?></dt><dd>Discards all changes since the last save, without asking, and reloads the page.</dd></div>
  <div><dt><?php echo $hb->bar('Save', 'save', 'check'); ?></dt><dd>Saves and keeps edit mode on. Good in between, during longer work. Keyboard: <?php echo $hb->key('⌘ S', 'Ctrl S'); ?>.</dd></div>
  <div><dt><?php echo $hb->bar('Save & Exit', 'primary', 'check'); ?></dt><dd>Saves and shows the page again the way visitors see it.</dd></div>
</dl>
<?php echo $hb->shot('undo.webp', 'The bar in edit mode after a change; Undo, Cancel and the save buttons are marked', '<b>After a change.</b> Undo is now active. On the right, the two save buttons.'); ?>

<?php echo $hb->note('Don’t forget', 'If you click a link to another page in edit mode, nibbly asks whether you want to leave edit mode. When you close the tab, the browser warns you. Adding or deleting sections and list items, on the other hand, nibbly saves at once.'); ?>

<h3>When two people work at the same time</h3>
<div class="hb-prose">
  <p>If someone else edits the same page and saves before you, nibbly doesn't silently overwrite their work. The window <?php echo $hb->ui('Changed in the meantime'); ?> appears with your unsaved version and the currently saved version side by side. You can <?php echo $hb->ui('Keep editing'); ?>, save your changes as a file (<?php echo $hb->ui('Download my changes'); ?>) or discard them and load the new version. The easiest way: copy your text from the left box, reload and paste it again.</p>
</div>

<h3>Bringing back earlier versions</h3>
<div class="hb-prose">
  <p>Every time you save, nibbly keeps the previous version of the page; the last 30 are kept. So even after saving you can go back to an earlier version, see <?php echo $hb->ref('backups'); ?>.</p>
</div>
