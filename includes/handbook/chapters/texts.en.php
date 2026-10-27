<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>In edit mode you click into a text and start typing, as in a word processor. There are two kinds of text fields:</p>
  <ul>
    <li><b>Short texts</b> such as headings, captions or labels. They are plain text without formatting. <?php echo $hb->key('Enter'); ?> finishes the field. If you empty a field completely, nibbly restores the old text.</li>
    <li><b>Body text</b> such as paragraphs. As soon as you click into it, a small toolbar appears above it.</li>
  </ul>
</div>
<?php echo $hb->shot('text-toolbar.webp', 'A paragraph in edit mode with the formatting toolbar above it', '<b>Toolbar for body text.</b> Bold, italic, link, clean formatting and the HTML view.'); ?>
<dl class="hb-fields">
  <div><dt>B / I</dt><dd>Bold and italic for the selected text. Keyboard: <?php echo $hb->key('⌘ B', 'Ctrl B'); ?> and <?php echo $hb->key('⌘ I', 'Ctrl I'); ?>.</dd></div>
  <div><dt><?php echo $hb->icon('link', 'Insert link'); ?> Insert link</dt><dd>Select a word or phrase first, then click; without a selection you get a hint. You pick a page of the website or enter an address, see <?php echo $hb->ref('links'); ?>.</dd></div>
  <div><dt>Clean formatting</dt><dd>Removes bold, italic and other markup from the selected text.</dd></div>
  <div><dt>&lt;/&gt; HTML</dt><dd>Shows the paragraph as HTML code. Only needed if you know exactly what you're doing.</dd></div>
</dl>

<h3>Tips</h3>
<div class="hb-prose">
  <ul>
    <li><b>Pasting text from Word or an email:</b> nibbly keeps only simple markup such as bold, italic and links. Fonts, colours and sizes of the source are dropped so the page stays consistent.</li>
    <li><b>Cancel a single field:</b> <?php echo $hb->key('Esc'); ?> discards the change in the field you're typing in.</li>
    <li><b>Multilingual websites:</b> each language has its own version of the page. Switch the language on the website and edit the translation there.</li>
    <li><b>Fields you can't see:</b> some texts only appear in a certain state, for example on a phone. You find them via <?php echo $hb->icon('edit', 'Edit'); ?> in the section's toolbar or in the Content Editor.</li>
  </ul>
</div>
