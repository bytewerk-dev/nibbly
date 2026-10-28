<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>The <?php echo $hb->nav('Icon Manager', 'icons'); ?> manages the website's symbols, for example for cards or lists. When you choose an icon in a block, it comes from this collection. Each icon shows whether it's part of nibbly's standard set or was added later.</p>
</div>
<?php echo $hb->shot('icons.webp', 'Icon Manager with search field, sorting, global stroke width and a grid of icons', '<b>Icon Manager.</b> Search, sorting and global stroke width at the top, all icons below.'); ?>
<dl class="hb-fields">
  <div><dt>Import from icon library</dt><dd>Searches a large, free icon collection. Icons you pick are stored on your own website; the icon set's licence is shown. Imported icons use the global stroke width; their style and licence source are kept when you save.</dd></div>
  <div><dt>Add icon</dt><dd>For your own icons: enter a unique key, a label and the SVG code. The preview shows right away how it looks. <?php echo $hb->ui('Use currentColor'); ?> makes the icon take on the website's text colour.</dd></div>
  <div><dt>Style</dt><dd>In the edit form you set whether an icon is drawn as “Line (outline)” or “Solid (filled)”. nibbly detects this from the SVG code when you paste it and suggests the matching value; change it if needed.</dd></div>
  <div><dt>Stroke width (global)</dt><dd>The field in the bar at the top sets the line weight for all line icons that don't have their own. The default is 2. This keeps icons from different collections looking consistent.</dd></div>
  <div><dt>Stroke width per icon</dt><dd>Optional field in the edit form. If it's empty, the global stroke width applies. If the SVG code contains one uniform stroke width, nibbly moves it into this field when you save. Icons with deliberately mixed widths are left unchanged. Values from 0.25 to 6 are allowed.</dd></div>
  <div><dt>Editing and deleting</dt><dd>If an icon's key changes or it's deleted, content that uses it shows a default icon.</dd></div>
</dl>
