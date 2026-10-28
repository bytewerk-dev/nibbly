<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>The <?php echo $hb->nav('Icon Manager', 'icons'); ?> manages the website's symbols, for example for cards or lists. When you choose an icon in a block, it comes from this collection. Each icon shows whether it's part of nibbly's standard set or was added later.</p>
</div>
<?php echo $hb->shot('icons.webp', 'Icon Manager with search field, sorting and a grid of icons', '<b>Icon Manager.</b> Search and sorting at the top, all icons below.'); ?>
<dl class="hb-fields">
  <div><dt>Import from icon library</dt><dd>Searches a large, free icon collection. Icons you pick are stored on your own website; the icon set's licence is shown.</dd></div>
  <div><dt>Add icon</dt><dd>For your own icons: enter a unique key, a label and the SVG code. The preview shows right away how it looks. <?php echo $hb->ui('Use currentColor'); ?> makes the icon take on the website's text colour.</dd></div>
  <div><dt>Editing and deleting</dt><dd>If an icon's key changes or it's deleted, content that uses it shows a default icon.</dd></div>
</dl>
