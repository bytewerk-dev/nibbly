<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Kaputt machen kannst du kaum etwas. nibbly sichert auf zwei Ebenen:</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Backups einer Seite</h4><p>Bei jedem Speichern sichert nibbly den vorherigen Stand. Die letzten 30 Stände jeder Seite bleiben erhalten.</p></div>
  <?php if ($hb->isAdmin): ?><div class="hb-card"><h4>Website-Backup</h4><p>Eine ZIP-Datei mit der ganzen Website: Inhalte, Bilder und Programmdateien. Manuell oder automatisch nach Zeitplan.</p></div><?php endif; ?>
</div>

<h3>Älteren Stand einer Seite zurückholen</h3>
<ol class="hb-steps">
  <li><span>Öffne die Seite auf der Website. Hat sie Backups, steht mittig in der Leiste ein Auswahlfeld mit Datum und Uhrzeit.</span></li>
  <li><span>Wähl den gewünschten Stand und klick auf <?php echo $hb->bar('Übernehmen'); ?>.</span></li>
  <li><span>Nach der Rückfrage lädt die Seite mit dem alten Stand neu. Der bisherige Stand wird vorher selbst als Backup gesichert, du kannst also jederzeit zurück.</span></li>
</ol>
<div class="hb-prose">
  <p>Im Dashboard findest du dieselben Backups im Inhaltseditor einer Seite, mit <?php echo $hb->ui('Ansehen'); ?> zum Vergleichen vorab.<?php if ($hb->module('menus') && $hb->isAdmin): ?> Menüs und Footer haben ihre eigenen Sicherungen, siehe <?php echo $hb->ref('menus'); ?>.<?php endif; ?></p>
</div>

<?php if ($hb->isAdmin): ?>
<h3>Website-Backup</h3>
<?php echo $hb->shot('backups.webp', 'Bereich Backup mit Backup erstellen, Wiederherstellen und gespeicherten Backups', '<b>Backup.</b> Oben erstellen und wiederherstellen, darunter die gespeicherten Backups und der Zeitplan.'); ?>
<dl class="hb-fields">
  <div><dt>Backup erstellen</dt><dd>Lädt eine ZIP-Datei der ganzen Website herunter und bewahrt eine Kopie auf dem Server auf. Manuell erstellte Backups löscht nibbly nie von selbst.</dd></div>
  <div><dt>Wiederherstellen</dt><dd>Aus einer ZIP-Datei oder einem gespeicherten Backup. <b>Nur Inhalte</b> ersetzt Seiten, Bilder und Vorlagen und lässt Programm und Benutzerkonten in Ruhe. <b>Vollständig</b> ersetzt alles; der aktuelle Stand wird vorher gesichert.</dd></div>
  <div><dt>Automatische Backups</dt><dd>Nach Zeitplan, mit Aufbewahrung (etwa 7 tägliche, 4 wöchentliche, 12 monatliche) und Speichergrenze. Dafür muss der Server regelmäßig einen Aufruf auslösen; die Anleitung dazu steht unter „Cron-Setup“.</dd></div>
  <div><dt>Externe Ziele</dt><dd>Kopien landen zusätzlich bei Dropbox, Google Drive, OneDrive, auf einem SFTP- oder FTP-Server oder in einem S3-Speicher. So ist ein Backup auch dann sicher, wenn der Server ausfällt.</dd></div>
</dl>
<?php echo $hb->note('Vorsicht', 'Ganz unten im Bereich Backup steht „Komplett-Reset“. Er löscht die ganze Website und lässt sich nicht rückgängig machen. Lade vorher ein aktuelles Backup herunter.'); ?>
<?php endif; ?>
