<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>In edit mode you simply click buttons and standalone links (such as “Learn more” or links in the footer). The <?php echo $hb->ui('Edit Link'); ?> window opens. Links inside body text are set via the toolbar, see <?php echo $hb->ref('texts'); ?>.</p>
</div>
<?php echo $hb->shot('link-editor.webp', 'The Edit Link window with text, link target, address and the options Open in new tab and Offer as download', '<b>Edit Link.</b> At the top the visible text, below it the target.'); ?>
<dl class="hb-fields">
  <div><dt>Text</dt><dd>What the button or link says.</dd></div>
  <div><dt>Link target</dt><dd>The select lists all pages of the website under <b>Site pages</b>. <b>Custom</b> takes any address, such as another website (with <code>https://</code>), an email address (<code>mailto:</code>) or a phone number (<code>tel:</code>).</dd></div>
  <div><dt>Open in new tab</dt><dd>Useful for links to other websites, so your page stays open.</dd></div>
  <div><dt>Offer as download</dt><dd>For files such as PDFs, Office documents or ZIP archives: the browser downloads the file instead of showing it. For other targets the option is greyed out.</dd></div>
</dl>
<div class="hb-prose">
  <p><?php echo $hb->btn('Save', 'primary'); ?> in the window applies the link to the page. Like all other changes, it goes online only when you save in the bar at the top.</p>
</div>
<?php echo $hb->note('Testing a link', 'In edit mode, clicking a link opens the window instead of the target page. To try it out, leave edit mode and then click the link.'); ?>
