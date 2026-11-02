<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>News sind datierte Beiträge, wie Beiträge in WordPress. Unter <?php echo $hb->nav('News', 'news'); ?> siehst du alle Beiträge; oben filterst du nach <?php echo $hb->ui('Veröffentlicht'); ?>, <?php echo $hb->ui('Entwurf'); ?> und <?php echo $hb->ui('Papierkorb'); ?>.</p>
</div>
<?php echo $hb->shot('news.webp', 'News-Liste mit Titel, Datum und Status der Beiträge', '<b>News-Liste.</b> Beim Darüberfahren erscheinen Bearbeiten, Ansehen, Veröffentlichen oder Zurückziehen und Papierkorb.'); ?>

<h3>Beitrag schreiben</h3>
<ol class="hb-steps">
  <li><span>Klick auf <?php echo $hb->btn('Neuer Beitrag', 'primary', 'plus'); ?>.</span></li>
  <li><span>Gib <b>Titel</b> und <b>Inhalt</b> ein. Der Inhalt besteht aus Blöcken: Text, Bild, Galerie, YouTube, Audio, Trennlinie und Abstand. Die <b>Dachzeile</b> steht über dem Titel und erscheint auch in Übersichten, die <b>Kurzbeschreibung</b> in Übersichten und Vorschauen; sie kennt Fett, Kursiv und Links, aber keine Absätze; über <?php echo $hb->ui('Code'); ?> siehst du das HTML.</span></li>
  <li><span>Rechts unter <b>Beitragseinstellungen</b> stehen Datum, Autor, die Adresse (Slug), Kategorien und Schlagwörter sowie das <b>Titelbild</b>, das du über <?php echo $hb->ui('Durchsuchen'); ?> aus der Bildverwaltung wählst. Du bestimmst, ob es in der Textspalte, über die volle Breite oder gar nicht gezeigt wird.</span></li>
  <li><span>Die <b>Seitenleiste</b> steht neben dem Text: ein Cover mit Bezeichnung, die Kurzbeschreibung und eigene Blöcke. Kommentare lassen sich für den einzelnen Beitrag ausschalten.</span></li>
  <li><span>Solange das Häkchen <b>Entwurf</b> gesetzt ist, sehen Besucher den Beitrag nicht. Zum Veröffentlichen das Häkchen entfernen und <?php echo $hb->btn('Beitrag speichern', 'primary'); ?>.</span></li>
</ol>
<div class="hb-prose">
  <p>In der Liste stellst du einen Beitrag auch direkt mit <?php echo $hb->ui('Veröffentlichen'); ?> online oder nimmst ihn mit <?php echo $hb->ui('Zurückziehen'); ?> wieder herunter. Bestehende Beiträge kannst du außerdem auf der Website im Visuellen Editor ändern: Dachzeile, Titel, Kurzbeschreibung, Autor, Cover und alle Blöcke. Ein Textblock bekommt dort über die Werkzeugleiste einen Stil, ein Bild lässt sich in eine Galerie umwandeln, Blöcke lassen sich teilen, zusammenführen und verschieben.</p>
  <p>Gelöschte Beiträge landen im Papierkorb und lassen sich von dort wiederherstellen. Bei jedem Speichern sichert nibbly den vorherigen Stand, siehe <?php echo $hb->ref('backups'); ?>. In der Liste filterst du nach Kategorie.</p>
</div>
<?php if ($hb->module('ai')): ?>
<?php echo $hb->note('Übersetzen', 'Bei mehrsprachigen Websites legst du mit eingeschalteten KI-Funktionen über „Aus … übersetzen“ eine Übersetzung an. Neue Übersetzungen starten als Entwurf, damit du sie vor der Veröffentlichung prüfen kannst.'); ?>
<?php endif; ?>
