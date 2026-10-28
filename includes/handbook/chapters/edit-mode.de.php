<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Solange du angemeldet bist, siehst du oben auf jeder Seite der Website eine Leiste. Damit bearbeitest du genau die Seite, auf der du gerade bist. Besucher sehen diese Leiste nicht.</p>
</div>
<?php echo $hb->shot('adminbar.webp', 'Startseite mit der Leiste für angemeldete Benutzer; Visueller Editor und Handbuch sind markiert', '<b>Die Leiste für angemeldete Benutzer.</b> Mittig schaltet „Visueller Editor“ den Bearbeitungsmodus ein, links führen Dashboard und Handbuch weiter.'); ?>

<h3>Bearbeitungsmodus einschalten</h3>
<ol class="hb-steps">
  <li><span>Geh auf der Website zur Seite, die du ändern möchtest, und klicke oben auf <?php echo $hb->bar('Visueller Editor', 'primary'); ?>.</span></li>
  <li><span>Fahr mit der Maus über die Seite. Alles, was sich bearbeiten lässt, bekommt einen Rahmen: Texte, Bilder, Links und ganze Abschnitte.</span></li>
  <li><span>Klick hinein und ändere, was du möchtest. Die folgenden Kapitel zeigen, wie das bei <?php echo $hb->ref('texts'); ?>, <?php echo $hb->ref('links'); ?> und <?php echo $hb->ref('images'); ?> geht.</span></li>
  <li><span>Zum Schluss <?php echo $hb->bar('Speichern & Beenden', 'primary', 'check'); ?>. Mehr dazu unter <?php echo $hb->ref('saving'); ?>.</span></li>
</ol>
<?php echo $hb->shot('edit-mode.webp', 'Bearbeitungsmodus mit umrandeten Texten; Speichern und Speichern & Beenden sind markiert', '<b>Bearbeitungsmodus.</b> Bearbeitbare Stellen sind umrandet, oben stehen Rückgängig, Abbrechen und die beiden Speichern-Buttons.'); ?>

<h3>Abschnitte</h3>
<div class="hb-prose">
  <p>Eine Seite besteht aus Abschnitten (Blöcken): einem Textblock, einem Bild, einer Kartenreihe und so weiter. Fährst du im Bearbeitungsmodus über einen Abschnitt, erscheint oben rechts eine kleine Werkzeugleiste:</p>
</div>
<dl class="hb-fields">
  <div><dt><?php echo $hb->icon('edit', 'Bearbeiten'); ?> Bearbeiten</dt><dd>Öffnet alle Felder des Abschnitts in einem Formular, auch solche, die man auf der Seite nicht direkt anklicken kann.</dd></div>
  <div><dt><?php echo $hb->icon('eye', 'Ausblenden'); ?> Ausblenden</dt><dd>Versteckt den Abschnitt vor Besuchern, ohne ihn zu löschen. Ein zweiter Klick blendet ihn wieder ein. Einzelne Felder und Listeneinträge haben dasselbe Auge.</dd></div>
  <div><dt><?php echo $hb->icon('trash', 'Löschen'); ?> Löschen</dt><dd>Entfernt den Abschnitt nach einer Rückfrage.</dd></div>
  <div><dt><?php echo $hb->icon('grip', 'Ziehen zum Umsortieren'); ?> Verschieben</dt><dd>Am Griff ziehen, um den Abschnitt weiter nach oben oder unten zu setzen.</dd></div>
  <div><dt><?php echo $hb->icon('plus', 'Block hinzufügen'); ?> Block hinzufügen</dt><dd>Das Plus unter einem Abschnitt fügt an dieser Stelle einen neuen Block ein. Du wählst den Typ aus einer Liste mit Suchfeld, etwa Text, Überschrift, Bild, Liste, Karte oder YouTube.</dd></div>
</dl>
<?php echo $hb->shot('sections.webp', 'Ein Bildabschnitt im Bearbeitungsmodus mit seiner Werkzeugleiste oben rechts', '<b>Werkzeugleiste eines Abschnitts.</b> Bearbeiten, Ausblenden, Löschen und der Griff zum Verschieben.'); ?>
<?php echo $hb->note('Sofort gespeichert', 'Wenn du einen Abschnitt oder Listeneintrag hinzufügst oder löschst, speichert nibbly sofort, zusammen mit allen anderen offenen Änderungen, und lädt die Seite neu. Alles andere, auch Verschieben und Ausblenden, wartet auf Speichern.'); ?>

<h3>Inhaltseditor</h3>
<div class="hb-prose">
  <p>Neben dem Visuellen Editor steht <?php echo $hb->bar('Inhaltseditor'); ?>. Er zeigt dieselben Inhalte als Formular im Dashboard, Feld für Feld. Das ist praktisch für Felder, die auf der Seite nicht sichtbar sind, etwa die Beschreibung für Suchmaschinen. Für normale Änderungen ist die Bearbeitung direkt auf der Seite meist einfacher.</p>
</div>

<h3>Footer</h3>
<div class="hb-prose">
  <p>Der Footer ist auf allen Seiten gleich. Änderungen daran gelten deshalb für die ganze Website. Im Bearbeitungsmodus öffnet ein Klick auf einen Footer-Eintrag das Fenster <?php echo $hb->ui('Footer bearbeiten'); ?> mit Linktext und Adresse, je Sprache. Auch das wird erst mit Speichern übernommen.<?php if ($hb->module('menus') && $hb->isAdmin): ?> Die Links und Kontaktangaben im Footer verwaltest du außerdem gesammelt unter <?php echo $hb->ref('menus'); ?>.<?php endif; ?></p>
</div>
