<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>In edit mode you click into a text and start typing, as in a word processor. There are two kinds of text fields:</p>
  <ul>
    <li><b>Short texts</b> such as headings, captions or labels. They are plain text without formatting. <?php echo $hb->key('Enter'); ?> finishes the field. If you empty a field completely, nibbly restores the old text.</li>
    <li><b>Body text</b> such as paragraphs. As soon as you click into it, a small toolbar appears above it.</li>
  </ul>
</div>
<?php echo $hb->shot('text-toolbar.webp', 'A paragraph in edit mode with the formatting toolbar above it', '<b>Toolbar for body text.</b> Block format, bold, italic, link, footnote, clean formatting and the HTML view.'); ?>
<dl class="hb-fields">
  <div><dt>Paragraph ▾</dt><dd>The block format of the element at the caret: paragraph, heading 2 to 4 or quote. One click opens the list, a second one changes the format of that paragraph only.</dd></div>
  <div><dt>B / I</dt><dd>Bold and italic for the selected text. Keyboard: <?php echo $hb->key('⌘ B', 'Ctrl B'); ?> and <?php echo $hb->key('⌘ I', 'Ctrl I'); ?>.</dd></div>
  <div><dt><?php echo $hb->icon('link', 'Insert link'); ?> Insert link</dt><dd>Select a word or phrase first, then click; without a selection you get a hint. With the caret in a link, the click opens that link for editing; an empty target removes it. You pick a page of the website or enter an address, see <?php echo $hb->ref('links'); ?>.</dd></div>
  <div><dt>Remove link</dt><dd>Removes the link and keeps the text. It is enough to have the caret inside the link.</dd></div>
  <div><dt>Footnote</dt><dd>Select the text of the note and click the footnote symbol. Visitors see a small number there; the text appears on hover or tap. A second click on an existing footnote turns it back into normal text.</dd></div>
  <div><dt>Split block here</dt><dd>Splits a text block into two between two paragraphs. The caret sets the place.</dd></div>
  <div><dt>Text box style</dt><dd>In the text blocks of posts and the text sections of pages: a menu with the looks your website knows for a text block, for example “Highlight”. It applies to the whole block, not to selected text. Which styles exist is defined by the website in <code>content/text-styles.json</code>.</dd></div>
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
