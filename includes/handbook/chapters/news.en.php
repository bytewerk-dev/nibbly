<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>News are dated posts, like posts in WordPress. Under <?php echo $hb->nav('News', 'news'); ?> you see all posts; at the top you filter by <?php echo $hb->ui('Published'); ?>, <?php echo $hb->ui('Draft'); ?> and <?php echo $hb->ui('Trash'); ?>.</p>
</div>
<?php echo $hb->shot('news.webp', 'News list with title, date and status of the posts', '<b>News list.</b> Move over a row and Edit, View, Publish or Unpublish and Trash appear.'); ?>

<h3>Writing a post</h3>
<ol class="hb-steps">
  <li><span>Click <?php echo $hb->btn('New Post', 'primary', 'plus'); ?>.</span></li>
  <li><span>Enter <b>title</b> and <b>content</b>. The content is made of blocks: text, image, gallery, YouTube, audio, divider and spacer. The <b>kicker</b> sits above the title and also appears in overviews, the <b>short description</b> in overviews and previews; it takes bold, italic and links, but no paragraphs; <?php echo $hb->ui('Code'); ?> shows the HTML.</span></li>
  <li><span>On the right, under <b>post settings</b>, are the date, author, address (slug), categories and tags and the <b>title image</b>, which you pick via <?php echo $hb->ui('Media library'); ?>. You choose whether it appears in the text column, at full width or not at all.</span></li>
  <li><span>Below the excerpt is the <b>photo credit for the whole post</b>: it applies to the title image and every gallery image without a credit of its own and appears at the end of the post as “Photo:” or “Photos:”, depending on how many images the post shows. In the Visual Editor you edit it right there.</span></li>
  <li><span>The <b>sidebar</b> sits next to the text: a cover with a label, the short description and blocks of its own. Comments can be switched off for a single post.</span></li>
  <li><span>As long as <b>Draft</b> is ticked, visitors don't see the post. To publish, untick it and click <?php echo $hb->btn('Save post', 'primary'); ?>.</span></li>
</ol>
<div class="hb-prose">
  <p>In the list you can also put a post online directly with <?php echo $hb->ui('Publish'); ?> or take it down again with <?php echo $hb->ui('Unpublish'); ?>. You can also change existing posts on the website in the Visual Editor: kicker, title, short description, author, cover and every block. There a text block gets its style from the toolbar, an image can be turned into a gallery, and blocks can be split, merged and moved.</p>
  <p>Deleted posts go to the trash and can be restored from there. Every save keeps the previous state, see <?php echo $hb->ref('backups'); ?>. The list can be filtered by category.</p>
  <p>The search field above the list finds posts by title and kicker, umlauts in both spellings (“Jörg”, “Joerg”). Switch the toggle beside it to <?php echo $hb->ui('Content'); ?> and nibbly also searches the excerpt, blocks, sidebar, captions, photo credit, author and tags.</p>
</div>
<?php if ($hb->module('ai')): ?>
<?php echo $hb->note('Translating', 'On multilingual websites with AI features switched on, “Translate from …” creates a translation. New translations start as drafts so you can check them before publishing.'); ?>
<?php endif; ?>
