<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>To replace an image, click it in edit mode. The <?php echo $hb->ui('Image manager'); ?> opens with the current image already selected.</p>
</div>
<ol class="hb-steps">
  <li><span>In edit mode, click the image you want to replace.</span></li>
  <li><span>Pick an existing image or upload a new one: drag files into the area at the bottom or click <?php echo $hb->btn('Upload', 'primary', 'upload'); ?>. Several files at once work too.</span></li>
  <li><span>Click <?php echo $hb->btn('Select image', 'primary'); ?>.</span></li>
  <li><span>nibbly asks for the <b>alt text</b>: a short description of the image for people who can't see it and for search engines, such as “Team at the summer party in the courtyard”.</span></li>
  <li><span>Like all changes, the new image goes online only after <?php echo $hb->bar('Save', 'save', 'check'); ?>.</span></li>
</ol>
<?php echo $hb->shot('image-manager.webp', 'Image manager with folders on the left, image previews and the upload area at the bottom', '<b>Image manager.</b> Folders on the left, the images in the middle, the upload area at the bottom and the select button at the bottom right.'); ?>

<h3>Keeping the image manager tidy</h3>
<dl class="hb-fields">
  <div><dt>Folders</dt><dd>On the left, the folder symbol creates new folders, for example for a project or a year. Empty folders can be deleted again.</dd></div>
  <div><dt>Search and view</dt><dd>At the top you search by file name and switch between grid and list.</dd></div>
  <div><dt>Below each image</dt><dd>Small symbols for preview, download, copy path, move to another folder, rename, replace, AI label and delete. Move the mouse over a symbol to see what it does.</dd></div>
  <div><dt>Trash</dt><dd>Deleted images go to the <?php echo $hb->ui('Trash'); ?> first (at the top next to <?php echo $hb->ui('Library'); ?>). From there you bring them back with <?php echo $hb->ui('Restore'); ?> or delete them for good.</dd></div>
  <div><dt>Same file name</dt><dd>If the name already exists, nibbly asks whether to overwrite the old file or save the new one under a different name.</dd></div>
</dl>

<h3>Allowed files and size</h3>
<div class="hb-prose">
  <p>For images, JPG, PNG, WebP, GIF and SVG are allowed. The maximum size per file is shown in the upload area. You don't have to shrink large photos yourself: on upload nibbly automatically creates a <b>web version</b> that loads quickly (at most 2000 pixels on the longest side). The original is kept; the <?php echo $hb->ui('Original'); ?> badge on the image leads to it.</p>
  <p>nibbly can't crop images or set a focal point. Prepare the image in an image editor beforehand.</p>
</div>

<?php if ($hb->isAdmin): ?>
<h3>The Media Library</h3>
<div class="hb-prose">
  <p>In the dashboard, <?php echo $hb->nav('Media Library', 'image'); ?> manages all files of the website in one place: images, audio, video and documents such as PDFs. At the top you filter by file type; otherwise everything works as in the image manager.</p>
</div>
<?php echo $hb->shot('media.webp', 'The Media Library in the dashboard with file type filter, folders and files', '<b>Media Library.</b> All files of the website, filtered by type.'); ?>
<?php endif; ?>
<?php echo $hb->note('Don’t forget the alt text', 'It describes the image for screen readers and search engines. If it’s missing, the page’s SEO check lists it as a hint.'); ?>
