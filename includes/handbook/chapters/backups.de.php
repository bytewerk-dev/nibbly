<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Kaputt machen kannst du kaum etwas. nibbly sichert auf zwei Ebenen:</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Backups einer Seite oder eines Beitrags</h4><p>Bei jedem Speichern sichert nibbly den vorherigen Stand. Standardmäßig bleiben die letzten 30 Stände je Seite und Sprache oder je Beitrag erhalten.</p></div>
  <?php if ($hb->isAdmin): ?><div class="hb-card"><h4>Website-Backup</h4><p>Eine ZIP-Datei mit der ganzen Website: Inhalte, Bilder und Programmdateien. Manuell oder automatisch nach Zeitplan.</p></div><?php endif; ?>
</div>

<h3>Älteren Stand einer Seite oder eines Beitrags zurückholen</h3>
<ol class="hb-steps">
  <li><span>Öffne die Seite oder den Beitrag auf der Website. Gibt es Backups, steht mittig in der Leiste ein Auswahlfeld mit Datum und Uhrzeit.</span></li>
  <li><span>Wähl den gewünschten Stand und klick auf <?php echo $hb->bar('Übernehmen'); ?>.</span></li>
  <li><span>Nach der Rückfrage lädt die Seite mit dem alten Stand neu. Der bisherige Stand wird vorher selbst als Backup gesichert, du kannst also jederzeit zurück.</span></li>
</ol>
<div class="hb-prose">
  <p>Im Dashboard findest du dieselben Backups im Inhaltseditor einer Seite und in der Seitenleiste des Beitragseditors, mit <?php echo $hb->ui('Ansehen'); ?> zum Vergleichen vorab.<?php if ($hb->module('menus') && $hb->isAdmin): ?> Menüs und Footer haben ihre eigenen Sicherungen, siehe <?php echo $hb->ref('menus'); ?>.<?php endif; ?></p>
</div>

<h3>Einen Seitenstand behalten</h3>
<ol class="hb-steps">
  <li><span>Öffne die gewünschte Sprachversion der Seite im Inhaltseditor. Such unter <?php echo $hb->ui('Backups'); ?> den Stand anhand von Datum und Uhrzeit. Mit <?php echo $hb->ui('Ansehen'); ?> kannst du den Inhalt prüfen.</span></li>
  <li><span>Klick beim gewünschten Stand auf das Lesezeichensymbol <?php echo $hb->ui('Stand behalten'); ?>. Gib ihm bei Bedarf eine Bezeichnung wie „Vor dem Relaunch“ und klick auf <?php echo $hb->ui('Speichern'); ?>. Der Stand bleibt zusätzlich zu den automatisch aufbewahrten Versionen erhalten.</span></li>
  <li><span>Um die Bezeichnung zu ändern, öffne am selben Stand <?php echo $hb->ui('Schutz bearbeiten'); ?>. Wählst du dort <?php echo $hb->ui('Schutz aufheben'); ?>, gilt für diesen Stand bei der nächsten Bereinigung wieder die normale Aufbewahrungsregel.</span></li>
</ol>

<?php if ($hb->isAdmin): ?>
<h3>Aufbewahrung von Seitenständen</h3>
<p>Öffne im Dashboard <?php echo $hb->ui('Backup'); ?> und klappe <?php echo $hb->ui('Aufbewahrung von Seitenständen'); ?> auf. Die Regel gilt für die ganze Website. Jede Seite, Unterseite und Sprachversion hat ihren eigenen Verlauf.</p>
<dl class="hb-fields">
  <div><dt>Die letzten Stände</dt><dd>Die neuesten ungeschützten Stände bleiben erhalten. Standardmäßig sind es 30. In der Auswahlliste siehst du die für deine Website eingestellte Anzahl.</dd></div>
  <div><dt>Zeitlich gestaffelt</dt><dd>Die letzten fünf ungeschützten Stände bleiben erhalten. Hinzu kommt jeweils der jüngste ungeschützte Stand aus den letzten sieben Tagen, vier Kalenderwochen, zwölf Monaten und zwei Jahren mit Änderungen. Ein Stand kann zugleich Tages-, Wochen- und Monatsstand sein. Zeiträume ohne Änderungen belegen keinen Platz.</dd></div>
</dl>
<p>Wähle eine Regel und dann unter <?php echo $hb->ui('Vorschau für eine Seite'); ?> eine Seite mit Backups. Die Liste zeigt für jeden vorhandenen Stand, ob er bleibt oder zur Bereinigung vorgesehen ist. Mit <?php echo $hb->ui('Speichern'); ?> übernimmst du die Regel. Erst wenn die jeweilige Seite wieder gespeichert wird, kommt der bisherige Seitenstand als Backup hinzu und nibbly bereinigt ihren Verlauf.</p>

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
