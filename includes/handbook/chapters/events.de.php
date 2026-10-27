<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Unter <?php echo $hb->nav('Termine', 'calendar'); ?> verwaltest du Veranstaltungen. Die Liste zeigt Titel, Datum, Ort und Status (<?php echo $hb->ui('Bevorstehend'); ?>, <?php echo $hb->ui('Heute'); ?>, <?php echo $hb->ui('Vergangen'); ?>). Oben filterst du außerdem nach ausgeblendeten Terminen und dem Papierkorb.</p>
</div>
<?php echo $hb->shot('events.webp', 'Terminliste mit Titel, Datum, Ort und Status', '<b>Terminliste.</b> Beim Darüberfahren erscheinen Bearbeiten, Ansehen, Duplizieren und Papierkorb.'); ?>

<h3>Termin anlegen</h3>
<ol class="hb-steps">
  <li><span>Klick auf <?php echo $hb->btn('Neuer Termin', 'primary', 'plus'); ?> oder bei einem bestehenden Termin auf <?php echo $hb->ui('Bearbeiten'); ?>. <?php echo $hb->ui('Duplizieren'); ?> spart Arbeit bei wiederkehrenden Terminen.</span></li>
  <li><span>Unter <b>Datum &amp; Uhrzeit</b> mindestens das Startdatum eintragen, dazu nach Bedarf Startzeit, Enddatum und Endzeit. Ein Termin ohne Datum gilt als Entwurf.</span></li>
  <li><span>Je Sprache <b>Titel</b>, <b>Ort</b>, <b>Beschreibung</b> und <b>Eintritt</b> ausfüllen. Optional ein Bild und ein Link, etwa zur Anmeldung.</span></li>
  <li><span><?php echo $hb->btn('Speichern', 'primary'); ?>. Jeder Termin bekommt eine eigene Seite auf der Website.</span></li>
</ol>
<?php echo $hb->note('Ausblenden', 'Einen Termin vorübergehend verstecken kannst du auf der Website: im Bearbeitungsmodus mit dem Auge am Termin. Dort bearbeitest und löschst du Termine auch direkt; diese Änderungen speichert nibbly sofort.'); ?>
