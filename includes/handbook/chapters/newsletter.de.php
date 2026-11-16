<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Unter <?php echo $hb->nav('Newsletter', 'mail'); ?> verwaltest du Abonnenten, schreibst Kampagnen und versendest sie. Der Bereich hat fünf Reiter: <?php echo $hb->ui('Kampagnen'); ?>, <?php echo $hb->ui('Abonnenten'); ?>, <?php echo $hb->ui('Listen'); ?>, <?php echo $hb->ui('Protokoll'); ?> und, für Admins, <?php echo $hb->ui('Einstellungen'); ?>.</p>
</div>
<h3>Abonnenten und Listen</h3>
<div class="hb-prose">
  <p>Abonnenten kommen über das Anmeldeformular auf der Website, über <?php echo $hb->ui('Importieren'); ?> (CSV-Datei mit Kopfzeile) oder einzeln über <?php echo $hb->ui('Abonnent hinzufügen'); ?>. Trage nur Personen ein, die dem Empfang zugestimmt haben.</p>
  <p>Jeder Abonnent hat einen Status:</p>
</div>
<dl class="hb-fields">
  <div><dt>Bestätigung offen</dt><dd>Hat sich angemeldet, aber den Link in der Bestätigungsmail noch nicht angeklickt. Erhält keinen Newsletter. nibbly erinnert automatisch; in der Liste lässt sich die Bestätigung auch von Hand erneut senden.</dd></div>
  <div><dt>Bestätigt</dt><dd>Erhält Kampagnen.</dd></div>
  <div><dt>Abgemeldet</dt><dd>Hat sich über den Link in einer Mail abgemeldet und erhält nichts mehr.</dd></div>
  <div><dt>Unzustellbar</dt><dd>Der Mailserver hat die Adresse dauerhaft abgelehnt.</dd></div>
</dl>
<div class="hb-prose">
  <p><b>Listen</b> gruppieren Abonnenten, zum Beispiel nach Thema. Ohne Listen geht jede Kampagne an alle bestätigten Abonnenten. <?php echo $hb->ui('CSV exportieren'); ?> lädt die gerade gefilterten Abonnenten herunter.</p>
</div>
<h3>Eine Kampagne schreiben</h3>
<ol class="hb-steps">
  <li><span><?php echo $hb->btn('Neue Kampagne', 'primary', 'plus'); ?> wählen, <b>Betreff</b> und <b>Vorschautext</b> eintragen. Der Vorschautext erscheint im Posteingang hinter dem Betreff.</span></li>
  <li><span>Bei <b>Empfänger</b> die Listen ankreuzen. Ohne Auswahl geht die Kampagne an alle.</span></li>
  <li><span>Den Inhalt aus <b>Bausteinen</b> zusammensetzen: Überschrift, Text, Bild, Button, Trennlinie, Abstand und Beitrag. Der Baustein <?php echo $hb->ui('Beitrag'); ?> übernimmt Titel, Anreißer, Bild und Link eines News-Beitrags. Die Pfeile verschieben einen Baustein, der Papierkorb entfernt ihn.</span></li>
  <li><span>Rechts zeigt die <b>Vorschau</b>, wie die Mail ankommt, umschaltbar zwischen Desktop und Handy.</span></li>
  <li><span><?php echo $hb->ui('Test senden'); ?> schickt die Kampagne an eine Adresse deiner Wahl.</span></li>
  <li><span><?php echo $hb->ui('Senden…'); ?> verschickt sofort oder zu einem Zeitpunkt.</span></li>
</ol>
<?php echo $hb->note('Platzhalter', 'In Betreff und Text ersetzt nibbly Platzhalter durch die Daten des Abonnenten: <code>{firstname}</code>, <code>{lastname}</code>, <code>{email}</code>. Mit einem Ersatzwert nach dem senkrechten Strich bleibt der Satz auch ohne Namen vollständig: <code>Hallo {firstname|zusammen}</code>.'); ?>
<h3>Versand und Auswertung</h3>
<div class="hb-prose">
  <p>Die Mails gehen in Portionen hinaus, damit der Mailserver sie annimmt. Solange das Dashboard offen ist, läuft der Versand von selbst weiter; mit einem Cronjob (Einstellungen des Newsletters) läuft er auch im Hintergrund, das ist für geplante Kampagnen nötig. Ein Tageslimit schützt vor den Grenzen des Anbieters. Ist es erreicht, geht der Rest am nächsten Tag hinaus.</p>
  <p>Die <b>Auswertung</b> einer Kampagne zeigt, wie viele Mails versendet wurden, wer sich abgemeldet hat und welche Adressen fehlgeschlagen sind. Öffnungen und Klicks misst nibbly nur, wenn das in den Einstellungen eingeschaltet ist. <?php echo $hb->ui('Pausieren'); ?> hält einen laufenden Versand an. Nach wiederholten Fehlern pausiert nibbly von selbst und nennt den Grund.</p>
  <p>Der Reiter <?php echo $hb->ui('Protokoll'); ?> listet jede ausgehende Mail der letzten 30 Tage mit Empfänger, Betreff und Ergebnis, auch Bestätigungen und Tests. Bei einem Fehler steht dort die Antwort des Mailservers; das hilft, wenn jemand sagt, eine Mail sei nicht angekommen.</p>
  <p>Jede Mail enthält unten einen Link zum Abmelden. Viele Mailprogramme zeigen zusätzlich einen eigenen Abmelde-Button an.</p>
</div>
<h3>Einrichten (Admins)</h3>
<dl class="hb-fields">
  <div><dt>Absender und Versand</dt><dd>Versand über die E-Mail-Einstellung der Website, über <b>Gmail</b> oder einen eigenen SMTP-Server. Für Gmail brauchst du die Bestätigung in zwei Schritten und ein App-Passwort aus deinem Google-Konto; Gmail erlaubt rund 500 Mails pro Tag.</dd></div>
  <div><dt>Anmeldung</dt><dd>Double-Opt-in (empfohlen), Erinnerungen, der Einwilligungstext des Formulars und eigene Felder. Das Formular kommt als Baustein <?php echo $hb->ui('Newsletter-Anmeldung'); ?> auf eine Seite.</dd></div>
  <div><dt>Statistik</dt><dd>Öffnungen und Klicks messen. Das sind personenbezogene Daten: nur mit passender Einwilligung und einem Hinweis in der Datenschutzerklärung einschalten.</dd></div>
  <div><dt>Gestaltung</dt><dd>Akzentfarbe, Hintergrund, Logo und der Text der Fußzeile. In geschäftlichen Mails gehört dort das Impressum hin.</dd></div>
</dl>
<h3>Das Anmeldeformular auf der Website</h3>
<div class="hb-prose">
  <p>Am einfachsten fügst du auf einer Seite den Baustein <?php echo $hb->ui('Newsletter-Anmeldung'); ?> ein. Er fragt nach der E-Mail-Adresse, auf Wunsch nach dem Vornamen, und kann in eine Liste eintragen.</p>
  <p>Soll das Formular fest in einer Vorlage stehen, etwa im Fußbereich, setzt die Person, die die Website betreut, diese Zeile in die Vorlage:</p>
  <pre><code>&lt;?php require_once $_includeBase . 'includes/newsletter.php'; echo nibblyNewsletterForm(); ?&gt;</code></pre>
  <p>Solange das Modul ausgeschaltet ist, gibt die Zeile nichts aus.</p>
</div>
<div class="hb-prose">
  <p>Links in den Mails brauchen die <b>Adresse der Website</b>, siehe <?php echo $hb->ref('settings'); ?>.</p>
</div>
