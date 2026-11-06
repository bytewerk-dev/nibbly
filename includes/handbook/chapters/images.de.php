<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Ein Bild tauschst du im Bearbeitungsmodus mit einem Klick darauf. Es öffnet sich die <?php echo $hb->ui('Bildverwaltung'); ?>, das aktuelle Bild ist schon ausgewählt.</p>
</div>
<ol class="hb-steps">
  <li><span>Klick im Bearbeitungsmodus auf das Bild, das du ersetzen möchtest.</span></li>
  <li><span>Wähl ein vorhandenes Bild aus oder lade ein neues hoch: Dateien in den Bereich unten ziehen oder auf <?php echo $hb->btn('Hochladen', 'primary', 'upload'); ?> klicken. Mehrere Dateien auf einmal gehen auch.</span></li>
  <li><span>Klick auf <?php echo $hb->btn('Bild auswählen', 'primary'); ?>.</span></li>
  <li><span>nibbly fragt nach dem <b>Alternativtext</b>: eine kurze Beschreibung des Bildes für Menschen, die es nicht sehen, und für Suchmaschinen, etwa „Team beim Sommerfest im Innenhof“.</span></li>
  <li><span>Wie alle Änderungen ist das neue Bild erst nach <?php echo $hb->bar('Speichern', 'save', 'check'); ?> online.</span></li>
</ol>
<?php echo $hb->shot('image-manager.webp', 'Bildverwaltung mit Ordnern links, Bildvorschauen und dem Upload-Bereich unten', '<b>Bildverwaltung.</b> Links die Ordner, in der Mitte die Bilder, unten der Upload-Bereich und rechts unten „Bild auswählen“.'); ?>

<h3>Ordnung in der Bildverwaltung</h3>
<dl class="hb-fields">
  <div><dt>Ordner</dt><dd>Links legst du mit dem Ordner-Symbol neue Ordner an, etwa für ein Projekt oder ein Jahr. Ist ein Ordner ausgewählt, entsteht der neue darin; so baust du etwa „2026/Oktober“. Ordner mit Unterordnern klappst du mit dem Pfeil zu und auf. Leere Ordner lassen sich wieder löschen.</dd></div>
  <div><dt>Suche und Ansicht</dt><dd>Oben suchst du nach Dateinamen und wechselst zwischen Raster und Liste. Beide zeigen zu jedem Bild Dateigröße, Pixelmaße (Breite × Höhe) und Datum.</dd></div>
  <div><dt>Unter jedem Bild</dt><dd>Kleine Symbole für Herunterladen, Pfad kopieren, Verschieben in einen anderen Ordner, Umbenennen, Ersetzen und Löschen, dahinter <?php echo $hb->ui('Eigenschaften'); ?>. Fahr mit der Maus darüber, dann steht dort, was das Symbol tut. Ein Klick aufs Bild wählt es aus; die Lupe, die beim Überfahren oben links erscheint, zeigt es groß.</dd></div>
  <div><dt>Eigenschaften</dt><dd>Das Symbol mit den Schiebereglern öffnet ein kleines Feld mit den Eigenschaften der Datei, etwa der KI-Kennzeichnung (KI-generiert oder mit KI verändert). Jede Änderung ist sofort gespeichert, aktive Eigenschaften stehen als Kennzeichen oben links am Bild. Sie gelten für die Datei, also überall, wo das Bild verwendet wird, und bleiben beim Umbenennen und Verschieben erhalten. Je nach Website gibt es weitere Eigenschaften.</dd></div>
  <div><dt>Papierkorb</dt><dd>Gelöschte Bilder landen zuerst im <?php echo $hb->ui('Papierkorb'); ?> (oben neben <?php echo $hb->ui('Bibliothek'); ?>). Von dort holst du sie mit <?php echo $hb->ui('Wiederherstellen'); ?> zurück oder löschst sie endgültig.</dd></div>
  <div><dt>Gleicher Dateiname</dt><dd>Gibt es den Namen schon, fragt nibbly, ob die alte Datei überschrieben oder die neue unter anderem Namen gespeichert werden soll.</dd></div>
</dl>

<h3>Erlaubte Dateien und Größe</h3>
<div class="hb-prose">
  <p>Für Bilder sind JPG, PNG, WebP, GIF und SVG erlaubt. Die Höchstgröße pro Datei steht im Upload-Bereich. Große Fotos musst du nicht selbst verkleinern: nibbly rechnet sie beim Hochladen automatisch in eine <b>Webfassung</b> um, die schnell lädt (höchstens 2000 Pixel an der längsten Seite). Das Original bleibt gespeichert; das Kennzeichen <?php echo $hb->ui('Original'); ?> am Bild führt dorthin.</p>
  <p>Zusätzlich legt nibbly von jedem Foto zwei kleinere Fassungen an (640 und 1280 Pixel breit). Sie erscheinen nicht in der Bildverwaltung; Browser laden damit auf kleinen Bildschirmen automatisch die passende Größe.</p>
  <p>Zuschneiden oder einen Bildausschnitt wählen kannst du in nibbly nicht. Bereite das Bild dafür vorher in einem Bildprogramm vor.</p>
</div>

<?php if ($hb->isAdmin): ?>
<h3>Die Mediathek</h3>
<div class="hb-prose">
  <p>Im Dashboard unter <?php echo $hb->nav('Mediathek', 'image'); ?> verwaltest du alle Dateien der Website an einem Ort: Bilder, Audio, Video und Dokumente wie PDFs. Oben filterst du nach Dateityp, sonst funktioniert alles wie in der Bildverwaltung.</p>
</div>
<?php echo $hb->note('Auf dem eigenen Rechner', 'Läuft nibbly lokal auf deinem Computer, zeigt die Mediathek hinter den Eigenschaften drei weitere Symbole: <b>Im Finder zeigen</b> (unter Windows „Im Explorer zeigen“), <b>Mit Standardprogramm öffnen</b>, etwa um ein Foto in der Bildbearbeitung zu öffnen, und <b>Lokalen Pfad kopieren</b>. Auf der Website im Internet erscheinen sie nie.'); ?>
<?php echo $hb->shot('media.webp', 'Die Mediathek im Dashboard mit Dateityp-Filter, Ordnern und Dateien', '<b>Mediathek.</b> Alle Dateien der Website, nach Typ gefiltert.'); ?>
<?php endif; ?>
<?php echo $hb->note('Alternativtext nicht vergessen', 'Er beschreibt das Bild für Screenreader und Suchmaschinen. Fehlt er, zeigt die SEO-Prüfung einer Seite das als Hinweis an.'); ?>
