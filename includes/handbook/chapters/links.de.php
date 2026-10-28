<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Buttons und eigenständige Links (etwa „Mehr erfahren“ oder Links im Footer) klickst du im Bearbeitungsmodus einfach an. Es öffnet sich das Fenster <?php echo $hb->ui('Link bearbeiten'); ?>. Links mitten im Fließtext setzt du über die Werkzeugleiste, siehe <?php echo $hb->ref('texts'); ?>.</p>
</div>
<?php echo $hb->shot('link-editor.webp', 'Fenster Link bearbeiten mit Text, Linkziel, Adresse und den Optionen In neuem Tab öffnen und Als Download anbieten', '<b>Link bearbeiten.</b> Oben der sichtbare Text, darunter das Ziel.'); ?>
<dl class="hb-fields">
  <div><dt>Text</dt><dd>Was auf dem Button oder Link steht.</dd></div>
  <div><dt>Linkziel</dt><dd>Das Auswahlfeld listet unter <b>Seiten</b> alle Seiten der Website. <b>Benutzerdefiniert</b> nimmt eine beliebige Adresse, etwa zu einer anderen Website (mit <code>https://</code>), eine E-Mail-Adresse (<code>mailto:</code>) oder eine Telefonnummer (<code>tel:</code>).</dd></div>
  <div><dt>In neuem Tab öffnen</dt><dd>Sinnvoll für Links auf fremde Websites, damit deine Seite offen bleibt.</dd></div>
  <div><dt>Als Download anbieten</dt><dd>Für Dateien wie PDFs, Office-Dokumente oder ZIP-Archive: Der Browser lädt die Datei herunter, statt sie anzuzeigen. Bei anderen Zielen ist die Option ausgegraut.</dd></div>
</dl>
<div class="hb-prose">
  <p>Mit <?php echo $hb->btn('Speichern', 'primary'); ?> im Fenster übernimmst du den Link in die Seite. Online ist er, wie alle anderen Änderungen, erst nach dem Speichern oben in der Leiste.</p>
</div>
<?php echo $hb->note('Link testen', 'Im Bearbeitungsmodus öffnet ein Klick auf einen Link das Fenster statt der Zielseite. Zum Ausprobieren beendest du den Bearbeitungsmodus und klickst den Link dann an.'); ?>
