<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Im Bearbeitungsmodus klickst du in einen Text und tippst los, wie in einem Textprogramm. Es gibt zwei Arten von Textfeldern:</p>
  <ul>
    <li><b>Kurze Texte</b> wie Überschriften, Bildunterschriften oder Beschriftungen. Sie sind reiner Text ohne Formatierung. <?php echo $hb->key('Enter'); ?> schließt das Feld ab. Leerst du ein Feld ganz, stellt nibbly den alten Text wieder her.</li>
    <li><b>Fließtext</b> wie Absätze. Sobald du hineinklickst, erscheint darüber eine kleine Werkzeugleiste.</li>
  </ul>
</div>
<?php echo $hb->shot('text-toolbar.webp', 'Ein Absatz im Bearbeitungsmodus mit der Werkzeugleiste für Formatierung darüber', '<b>Werkzeugleiste für Fließtext.</b> Fett, Kursiv, Link, Formatierung entfernen und die HTML-Ansicht.'); ?>
<dl class="hb-fields">
  <div><dt>B / I</dt><dd>Fett und Kursiv für den markierten Text. Tastatur: <?php echo $hb->key('⌘ B', 'Strg B'); ?> und <?php echo $hb->key('⌘ I', 'Strg I'); ?>.</dd></div>
  <div><dt><?php echo $hb->icon('link', 'Link einfügen'); ?> Link einfügen</dt><dd>Erst ein Wort oder einen Satzteil markieren, dann klicken; ohne Markierung erscheint ein Hinweis. Du wählst eine Seite der Website oder trägst eine Adresse ein, siehe <?php echo $hb->ref('links'); ?>.</dd></div>
  <div><dt>Formatierung bereinigen</dt><dd>Entfernt Fett, Kursiv und andere Auszeichnungen aus dem markierten Text.</dd></div>
  <div><dt>&lt;/&gt; HTML</dt><dd>Zeigt den Absatz als HTML-Code. Nur nötig, wenn du genau weißt, was du tust.</dd></div>
</dl>

<h3>Tipps</h3>
<div class="hb-prose">
  <ul>
    <li><b>Text aus Word oder einer E-Mail einfügen:</b> nibbly übernimmt nur einfache Auszeichnungen wie Fett, Kursiv und Links. Schriftarten, Farben und Größen der Vorlage fallen weg, damit die Seite einheitlich bleibt.</li>
    <li><b>Abbrechen für ein einzelnes Feld:</b> Mit <?php echo $hb->key('Esc'); ?> verwirfst du die Änderung in dem Feld, in dem du gerade schreibst.</li>
    <li><b>Mehrsprachige Websites:</b> Jede Sprache hat ihre eigene Fassung der Seite. Wechsle auf der Website die Sprache und bearbeite dort die Übersetzung.</li>
    <li><b>Felder, die du nicht siehst:</b> Manche Texte erscheinen erst in einem bestimmten Zustand, etwa auf dem Handy. Die findest du über <?php echo $hb->icon('edit', 'Bearbeiten'); ?> in der Werkzeugleiste des Abschnitts oder im Inhaltseditor.</li>
  </ul>
</div>
