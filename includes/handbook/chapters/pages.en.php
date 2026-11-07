<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Under <?php echo $hb->nav('Pages', 'pages'); ?> in the dashboard you see all pages of the website. Above each title is the page's address (such as <code>/contact</code>), next to it the SEO rating. On multilingual websites each further language has its own column.</p>
</div>
<div class="hb-prose">
  <p>The search field above the list finds pages by title. Switch the toggle beside it to <?php echo $hb->ui('Content'); ?> and nibbly also searches the description, texts, sections and captions of the pages.</p>
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
  <li><span>Enter a <b>title</b>. For a subpage, choose the <b>parent page</b>. nibbly fills in the <b>slug</b>, the last part of the address, from the title; the resulting address is shown below, such as <code>/services/consulting</code>.</span></li>
  <li><span><?php echo $hb->btn('Create Page', 'primary'); ?>. The new page is publicly reachable right away. Which menu it appears in you set in the Content Editor under <b>Navigation</b><?php if ($hb->module('menus') && $hb->isAdmin): ?> or under <?php echo $hb->ref('menus'); ?><?php endif; ?>.</span></li>
</ol>
<?php echo $hb->note('Drafts', 'Pages have no draft status. If a page shouldn’t be public yet, set it to “Private, password protected” in the page settings. Signed-in users still see it without a password.'); ?>

<h3>The Content Editor</h3>
<div class="hb-prose">
  <p>The Content Editor shows a page as a form: title and text at the top, the sections below to expand and the description, and at the very bottom, set apart by a line, the SEO fields. On the right are <?php echo $hb->btn('Save', 'primary'); ?>, <?php echo $hb->ui('View'); ?> and the languages, below them page image, page settings, access and backups. Changes in the Content Editor apply only after saving.</p>
</div>
<div class="hb-prose">
  <p>The section list on the left collapses with the symbol beside its heading, which gives the fields the full width. Pages with their own template show their field groups instead of sections; fields that allow formatting on the website get the same text toolbar as a text section.</p>
</div>
<?php echo $hb->shot('page-editor.webp', 'Content Editor of the home page with title, text field and page actions', '<b>Content Editor.</b> The same content as on the website, field by field.'); ?>
<dl class="hb-fields">
  <div><dt>Sections</dt><dd>Expand to edit, drag the handle to sort, <?php echo $hb->ui('Insert here'); ?> to add a new section at a spot. Hints such as “alt text missing” show where something is missing.</dd></div>
  <div><dt>Section list</dt><dd>Left of the sections, with a search field and a filter. The icon in its header collapses it so the fields use the full width; nibbly remembers this in the browser. <?php echo $hb->ui('Expand All'); ?> opens every section at once.</dd></div>
  <div><dt>Pages with their own template</dt><dd>Some pages have field groups of their own, such as “hero” or “content”, instead of sections. They appear in the same view; fields that show formatted text on the website get a toolbar and the <?php echo $hb->ui('Visual'); ?> / <?php echo $hb->ui('Code'); ?> switch.</dd></div>
  <div><dt>Navigation, breadcrumb</dt><dd>Two expandable rows right below the title: which menus the page appears in, and the breadcrumb.</dd></div>
  <div><dt>Page image</dt><dd>In the sidebar: the page's title image with alt text. It also appears on the overview cards of the parent page.</dd></div>
  <div><dt>Description</dt><dd>Below the sections, ahead of SEO / AEO: the card text on the parent page and the meta description at once. The field takes bold, italic and links, but no paragraphs; <?php echo $hb->ui('Code'); ?> shows the HTML. The meta tags get the plain text.</dd></div>
  <div><dt>Page settings</dt><dd>In the sidebar: the address. <?php echo $hb->ui('Move / rename …'); ?> gives the page a new address or moves it below another page: subpages move along, internal links are rewritten, and the old address redirects permanently to the new one.</dd></div>
  <div><dt>Access</dt><dd>In the sidebar: <?php echo $hb->ui('Public'); ?> or <?php echo $hb->ui('Private, password protected'); ?> with its own page password and text for the password page.</dd></div>
  <div><dt>SEO / AEO</dt><dd>At the end of the page, below the sections: SEO title, meta description (the text below the link in Google), a short answer for AI search and the image for shared links. The SEO rating at the top shows what's missing.</dd></div>
</dl>

<h3>SEO rating</h3>
<div class="hb-prose">
  <p>The rating next to each page title and in the bar on the website reads <?php echo $hb->ui('SEO looks good'); ?>, <?php echo $hb->ui('Review SEO'); ?> or <?php echo $hb->ui('SEO needs attention'); ?>, with points up to 100. Move the mouse over it on the website to see the list of hints, such as a missing meta description or images without alt text. A click opens the Content Editor.</p>
</div>
