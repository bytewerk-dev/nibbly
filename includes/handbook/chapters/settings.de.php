<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<?php if (!$hb->isAdmin): ?>
<div class="hb-prose">
  <p>Unter <?php echo $hb->nav('Einstellungen', 'settings'); ?> findest du <?php echo $hb->ui('Mein Konto'); ?>. Dort änderst du dein Passwort: aktuelles Passwort, dann zweimal das neue. Eine Liste zeigt, welche Anforderungen schon erfüllt sind. Alle anderen Einstellungen verwalten die Admins.</p>
</div>
<?php echo $hb->shot('my-account.webp', 'Mein Konto mit den Feldern zum Ändern des Passworts', '<b>Mein Konto.</b> Hier änderst du dein Passwort.'); ?>
<?php else: ?>
<div class="hb-prose">
  <p>Klickst du auf <?php echo $hb->nav('Einstellungen', 'settings'); ?>, klappt die Seitenleiste zu Symbolen zusammen und daneben erscheint eine zweite Leiste mit allen Bereichen. Oben darin findest du über <?php echo $hb->ui('Suchen'); ?> jede Einstellung nach Stichwort. Jeder Bereich hat unten einen eigenen Speichern-Button.</p>
</div>
<h3>Website</h3>
<dl class="hb-fields">
  <div><dt>Branding</dt><dd>Name der Website, Favicon, Logos für hell und dunkel, das Bild für geteilte Links und das Logo im Dashboard. Hier lässt sich die Website auch für Suchmaschinen sperren, etwa während des Aufbaus.</dd></div>
  <div><dt>Design</dt><dd>Das Aussehen des Dashboards, nicht der Website: Stil, hell oder dunkel, Grundfarbe, Schrift und Größe. Dazu, wie viele Einträge Listen pro Seite zeigen.</dd></div>
  <div><dt>Module</dt><dd>Bereiche ein- und ausschalten, etwa News, Termine oder dieses Handbuch. Ausgeschaltete Module verschwinden aus dem Dashboard, ihre Inhalte bleiben gespeichert. Darunter: Upload-Grenze und Webfassung für Bilder.</dd></div>
  <div><dt>Sprache</dt><dd>Die Sprache des Dashboards und die Sprachen der Website: Reihenfolge, Hauptsprache und neue Sprachen, auf Wunsch mit allen Inhalten einer bestehenden Sprache als Startpunkt.</dd></div>
</dl>
<?php echo $hb->shot('settings-modules.webp', 'Einstellungen, Bereich Module, mit Schaltern für die einzelnen Module; Handbuch ist markiert', '<b>Module.</b> Der Schalter „Handbuch“ blendet diese Anleitung und alle Links dazu aus.'); ?>
<h3>Funktionen</h3>
<dl class="hb-fields">
  <div><dt>Zugriff</dt><dd>Der <b>Wartungsmodus</b>: Besucher sehen eine Hinweisseite („Wartung“, „Bald wieder online“ oder einen Countdown), angemeldete Benutzer sehen die Website normal. Über einen geheimen Link können auch andere vorab hineinschauen.</dd></div>
  <div><dt>E-Mail</dt><dd>Wie die Website E-Mails versendet und an wen Formularnachrichten gehen. <?php echo $hb->ui('Test-E-Mail senden'); ?> prüft die Einstellung. Ohne E-Mail-Versand gibt es auch kein „Passwort vergessen?“.</dd></div>
  <div><dt>Datenschutz</dt><dd>E-Mail-Adressen vor Spam-Robotern verschleiern und die anonyme Besucherstatistik ein- oder ausschalten.</dd></div>
  <div><dt>Formulare</dt><dd>Felder, Texte und Verhalten der Formulare, etwa des Kontaktformulars.</dd></div>
  <?php if ($hb->module('ai')): ?><div><dt>KI-Einstellungen</dt><dd>Siehe <?php echo $hb->ref('ai'); ?>.</dd></div><?php endif; ?>
</dl>
<h3>Benutzer</h3>
<dl class="hb-fields">
  <div><dt>Benutzer</dt><dd>Konten anlegen und verwalten, siehe <?php echo $hb->ref('users'); ?>.</dd></div>
  <div><dt>Mein Konto</dt><dd>Dein eigenes Passwort ändern.</dd></div>
  <div><dt>Login</dt><dd>Wohin man nach dem Anmelden kommt (zurück zur Seite oder immer ins Dashboard) und wie die Anmeldeseite aussieht.</dd></div>
</dl>
<?php endif; ?>
