<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<dl class="hb-fields">
  <div><dt>Starkes Passwort</dt><dd>Mindestens 8 Zeichen, mit Groß- und Kleinbuchstaben, einer Ziffer und einem Sonderzeichen. Erfüllt dein Passwort das nicht, zeigt das Dashboard nach der Anmeldung eine Warnung mit einem Link zum Ändern.</dd></div>
  <div><dt>Eigenes Konto</dt><dd>Jede Person bekommt ein eigenes Konto. Teile Passwörter nicht, auch nicht im Team.</dd></div>
  <div><dt>Abmelden</dt><dd>Auf fremden oder geteilten Rechnern immer abmelden. Ohne Aktivität endet die Sitzung nach einer Stunde von selbst.</dd></div>
  <div><dt>Schutz der Anmeldung</dt><dd>Wiederholte Fehlversuche verlangsamen und sperren die Anmeldung, siehe <?php echo $hb->ref('login'); ?>.</dd></div>
  <div><dt>Private Seiten</dt><dd>Seiten mit Passwort und der Wartungsmodus sind für Suchmaschinen gesperrt. Vertrauliches gehört trotzdem nicht auf die Website.</dd></div>
</dl>
<?php if ($hb->isAdmin): ?>
<h3>Systemstatus</h3>
<div class="hb-prose">
  <p>Unter <?php echo $hb->nav('Systemstatus', 'shield'); ?> prüft nibbly den Server: ob interne Ordner von außen erreichbar sind, ob die Website über https läuft, ob Suchmaschinen sie finden dürfen, wann das letzte Backup war und welche Upload-Grenzen gelten. Zu jedem Problem steht dort, wie man es behebt. Ernste Probleme zeigt das Dashboard zusätzlich oben als Warnung.</p>
</div>
<?php echo $hb->shot('system.webp', 'Systemstatus mit den Bereichen Sicherheit, Suchmaschinen, PHP, Speicher und Uploads', '<b>Systemstatus.</b> Ein Hinweis am Menüpunkt zeigt, wenn etwas zu prüfen ist.'); ?>
<?php endif; ?>
