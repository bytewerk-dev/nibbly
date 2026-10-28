<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Im Bearbeitungsmodus stehen oben in der Leiste vier Werkzeuge. Änderungen an Texten, Bildern und Links sind erst nach dem Speichern online. Bis dahin siehst nur du sie.</p>
</div>
<dl class="hb-fields">
  <div><dt><?php echo $hb->icon('undo', 'Rückgängig'); ?> <?php echo $hb->icon('redo', 'Wiederholen'); ?></dt><dd>Rückgängig und Wiederholen, Schritt für Schritt. Tastatur: <?php echo $hb->key('⌘ Z', 'Strg Z'); ?> und <?php echo $hb->key('⌘ ⇧ Z', 'Strg ⇧ Z'); ?>. Solange du in einem Text tippst, wirken die Tasten nur auf diesen Text.</dd></div>
  <div><dt><?php echo $hb->bar('Abbrechen'); ?></dt><dd>Verwirft alle Änderungen seit dem letzten Speichern, ohne Rückfrage, und lädt die Seite neu.</dd></div>
  <div><dt><?php echo $hb->bar('Speichern', 'save', 'check'); ?></dt><dd>Speichert und lässt den Bearbeitungsmodus an. Gut zwischendurch, bei längeren Arbeiten. Tastatur: <?php echo $hb->key('⌘ S', 'Strg S'); ?>.</dd></div>
  <div><dt><?php echo $hb->bar('Speichern & Beenden', 'primary', 'check'); ?></dt><dd>Speichert und zeigt die Seite wieder so, wie Besucher sie sehen.</dd></div>
</dl>
<?php echo $hb->shot('undo.webp', 'Leiste im Bearbeitungsmodus nach einer Änderung; Rückgängig, Abbrechen und die Speichern-Buttons sind markiert', '<b>Nach einer Änderung.</b> Rückgängig ist jetzt aktiv. Ganz rechts die beiden Speichern-Buttons.'); ?>

<?php echo $hb->note('Nicht vergessen', 'Klickst du im Bearbeitungsmodus auf einen Link zu einer anderen Seite, fragt nibbly nach, ob du den Bearbeitungsmodus verlassen möchtest. Beim Schließen des Tabs warnt der Browser. Abschnitte und Listeneinträge hinzufügen oder löschen speichert nibbly dagegen sofort.'); ?>

<h3>Wenn zwei gleichzeitig arbeiten</h3>
<div class="hb-prose">
  <p>Bearbeitet jemand anderes dieselbe Seite und speichert vor dir, überschreibt nibbly seine Arbeit nicht stillschweigend. Es erscheint das Fenster <?php echo $hb->ui('Inzwischen geändert'); ?> mit deiner ungespeicherten und der aktuell gespeicherten Fassung nebeneinander. Du kannst <?php echo $hb->ui('Weiter bearbeiten'); ?>, deine Änderungen als Datei sichern (<?php echo $hb->ui('Meine Änderungen herunterladen'); ?>) oder sie verwerfen und die neue Fassung laden. Am einfachsten: deinen Text aus dem linken Kasten kopieren, neu laden und wieder einfügen.</p>
</div>

<h3>Ältere Stände zurückholen</h3>
<div class="hb-prose">
  <p>Bei jedem Speichern sichert nibbly den vorherigen Stand der Seite, die letzten 30 bleiben erhalten. Damit kommst du auch nach dem Speichern noch zu einem früheren Stand zurück, siehe <?php echo $hb->ref('backups'); ?>.</p>
</div>
