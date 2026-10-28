<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>News are dated posts, like posts in WordPress. Under <?php echo $hb->nav('News', 'news'); ?> you see all posts; at the top you filter by <?php echo $hb->ui('Published'); ?>, <?php echo $hb->ui('Draft'); ?> and <?php echo $hb->ui('Trash'); ?>.</p>
</div>
<?php echo $hb->shot('news.webp', 'News list with title, date and status of the posts', '<b>News list.</b> Move over a row and Edit, View, Publish or Unpublish and Trash appear.'); ?>

<h3>Writing a post</h3>
<ol class="hb-steps">
  <li><span>Click <?php echo $hb->btn('New Post', 'primary', 'plus'); ?>.</span></li>
  <li><span>Enter <b>title</b> and <b>content</b>. The <b>short description</b> appears in overviews and previews.</span></li>
  <li><span>On the right, under <b>post settings</b>, are the date, author, address (slug) and the <b>cover image</b>, which you pick from the image manager via <?php echo $hb->ui('Browse'); ?>.</span></li>
  <li><span>As long as <b>Draft</b> is ticked, visitors don't see the post. To publish, untick it and click <?php echo $hb->btn('Save post', 'primary'); ?>.</span></li>
</ol>
<div class="hb-prose">
  <p>In the list you can also put a post online directly with <?php echo $hb->ui('Publish'); ?> or take it down again with <?php echo $hb->ui('Unpublish'); ?>. You can also change existing posts on the website in the Visual Editor: title, text and author.</p>
  <p>Deleted posts go to the trash and can be restored from there. There are no categories or tags.</p>
</div>
<?php if ($hb->module('ai')): ?>
<?php echo $hb->note('Translating', 'On multilingual websites with AI features switched on, “Translate from …” creates a translation. New translations start as drafts so you can check them before publishing.'); ?>
<?php endif; ?>
