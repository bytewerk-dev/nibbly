<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>nibbly kennt zwei Rollen:</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Admin</h4><p>Darf alles: auch Menüs, Mediathek, Einstellungen, Benutzer, Backups und Systemstatus.</p></div>
  <div class="hb-card"><h4>Editor</h4><p>Für die Redaktion: Seiten, News, Termine, Personen und Nachrichten, der Visuelle Editor und Bilder hochladen. Von den Einstellungen nur „Mein Konto“.</p></div>
</div>
<?php echo $hb->shot('users.webp', 'Einstellungen, Bereich Benutzer, mit der Liste der Konten', '<b>Benutzer.</b> Name, E-Mail, Rolle und letzte Anmeldung jedes Kontos.'); ?>
<h3>Benutzer anlegen</h3>
<ol class="hb-steps">
  <li><span>Einstellungen › <?php echo $hb->ui('Benutzer'); ?> › <?php echo $hb->btn('Benutzer hinzufügen', 'primary', 'plus'); ?>.</span></li>
  <li><span><b>Benutzername</b> (mindestens drei Zeichen), <b>E-Mail</b> und <b>Rolle</b> wählen. Ohne E-Mail-Adresse funktioniert „Passwort vergessen?“ für dieses Konto nicht.</span></li>
  <li><span>Ein <b>Passwort</b> eintragen oder mit <?php echo $hb->ui('Generieren'); ?> erzeugen lassen und der Person auf sicherem Weg mitteilen. Sie ändert es danach unter <?php echo $hb->ui('Mein Konto'); ?>.</span></li>
</ol>
<div class="hb-prose">
  <p>In der Liste setzt <?php echo $hb->ui('Passwort'); ?> ein neues Passwort für jemanden, der seines vergessen hat. Der letzte Admin lässt sich weder löschen noch zum Editor machen, damit sich niemand aussperrt.</p>
</div>
