<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('Pages', 'pages'); ?> in the dashboard you see all pages of the website. Above each title is the page's address (such as <code>/contact</code>), next to it the SEO rating. On multilingual websites each further language has its own column.</p>
</div>
<?php echo $hb->shot('pages.webp', 'Page list with title, address, SEO rating and date of change; one row is marked', '<b>Page list.</b> Move the mouse over a row and Edit, View, Duplicate and Trash appear.'); ?>
<dl class="hb-fields">
  <div><dt>Edit</dt><dd>Opens the page in the dashboard's Content Editor (see below).</dd></div>
  <div><dt>View</dt><dd>Opens the page on the website in a new tab. There you edit it with the Visual Editor.</dd></div>
  <div><dt>Duplicate</dt><dd>Creates a copy, handy as a template for a similar page.</dd></div>
  <div><dt>Trash</dt><dd>Moves the page to the trash, without asking. Via the <?php echo $hb->ui('Trash'); ?> filter at the top you bring it back with <?php echo $hb->ui('Restore'); ?>.</dd></div>
  <div><dt>+ Create</dt><dd>In the column of another language: creates the translation as a copy, which you then translate.</dd></div>
</dl>

<h3>Adding a page</h3>
<ol class="hb-steps">
  <li><span>Click <?php echo $hb->btn('New Page', 'primary', 'plus'); ?> at the top right.</span></li>
  <li><span>Enter a <b>title</b>. nibbly fills in the <b>slug</b>, the part of the address, from it. A slash makes a subpage, such as <code>services/consulting</code>.</span></li>
  <li><span><?php echo $hb->btn('Create Page', 'primary'); ?>. The new page is publicly reachable right away. Which menu it appears in you set in the Content Editor under <b>Navigation</b><?php if ($hb->module('menus') && $hb->isAdmin): ?> or under <?php echo $hb->ref('menus'); ?><?php endif; ?>.</span></li>
</ol>
<?php echo $hb->note('Drafts', 'Pages have no draft status. If a page shouldn’t be public yet, set it to “Private, password protected” in the page settings. Signed-in users still see it without a password.'); ?>

<h3>The Content Editor</h3>
<div class="hb-prose">
  <p>The Content Editor shows a page as a form: title and text at the top, the sections below to expand. At the top right are <?php echo $hb->btn('Save', 'primary'); ?>, <?php echo $hb->ui('View'); ?> and the languages. Changes in the Content Editor apply only after saving.</p>
</div>
<?php echo $hb->shot('page-editor.webp', 'Content Editor of the home page with title, text field and page actions', '<b>Content Editor.</b> The same content as on the website, field by field.'); ?>
<dl class="hb-fields">
  <div><dt>Sections</dt><dd>Expand to edit, drag the handle to sort, <?php echo $hb->ui('Insert here'); ?> to add a new section at a spot. Hints such as “alt text missing” show where something is missing.</dd></div>
  <div><dt>Basic data</dt><dd>Title and description of the page.</dd></div>
  <div><dt>Navigation</dt><dd>Which menus the page appears in, and the breadcrumb.</dd></div>
  <div><dt>Access</dt><dd><?php echo $hb->ui('Public'); ?> or <?php echo $hb->ui('Private, password protected'); ?> with its own page password and text for the password page.</dd></div>
  <div><dt>SEO / AEO</dt><dd>SEO title, meta description (the text below the link in Google), a short answer for AI search and the image for shared links. The SEO rating at the top shows what's missing.</dd></div>
</dl>

<h3>SEO rating</h3>
<div class="hb-prose">
  <p>The rating next to each page title and in the bar on the website reads <?php echo $hb->ui('SEO looks good'); ?>, <?php echo $hb->ui('Review SEO'); ?> or <?php echo $hb->ui('SEO needs attention'); ?>, with points up to 100. Move the mouse over it on the website to see the list of hints, such as a missing meta description or images without alt text. A click opens the Content Editor.</p>
</div>
