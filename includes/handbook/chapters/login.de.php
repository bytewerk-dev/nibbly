<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<ol class="hb-steps">
  <li><span>Öffne die Adresse deiner Website mit <b>/admin</b> am Ende. Alternativ: Doppelklick auf die Jahreszahl im Copyright ganz unten im Footer der Website.</span></li>
  <li><span>Gib Benutzername und Passwort ein und klicke auf <?php echo $hb->btn('Anmelden', 'primary'); ?>. Das Auge im Passwortfeld zeigt das Passwort im Klartext.</span></li>
  <li><span>Du landest im Dashboard. Links stehen alle Bereiche, oben rechts wechselst du mit <?php echo $hb->ui('Website anzeigen'); ?> zur Website und bleibst dabei angemeldet. Oben links neben dem Namen der Website öffnet <?php echo $hb->ui('Handbuch'); ?> diese Anleitung in einem neuen Tab.</span></li>
</ol>
<?php echo $hb->pair(
    $hb->shot('login.webp', 'Anmeldeseite mit Feldern für Benutzername und Passwort', '<b>Die Anmeldeseite.</b> Benutzername und Passwort sind markiert.'),
    $hb->shot('dashboard.webp', 'Dashboard mit Seitenleiste und dem Link zum Handbuch', '<b>Das Dashboard.</b> Links die Bereiche, oben der Link zu diesem Handbuch.')
); ?>

<h3>Passwort vergessen</h3>
<div class="hb-prose">
  <p>Sobald die Website E-Mails versenden darf, steht unter dem Anmeldeformular <?php echo $hb->ui('Passwort vergessen?'); ?>. Gib die E-Mail-Adresse deines Kontos ein, nibbly schickt dir einen Link zum Zurücksetzen. Der Link gilt eine Stunde. Ohne E-Mail-Versand oder ohne E-Mail-Adresse im Konto setzt ein Admin dein Passwort zurück.</p>
  <p>Nach drei falschen Versuchen musst du vor jedem weiteren Versuch etwas länger warten; der Button zeigt die Wartezeit. Nach sehr vielen Fehlversuchen sperrt nibbly die Anmeldung von diesem Anschluss für 24 Stunden. Das schützt vor Angriffen, die Passwörter durchprobieren.</p>
</div>

<h3>Abmelden</h3>
<div class="hb-prose">
  <p>Im Dashboard steht <?php echo $hb->nav('Abmelden', 'logout'); ?> unten in der Seitenleiste, auf der Website ganz rechts in der Leiste. Auf der Website bleibst du danach auf derselben Seite. Melde dich vor allem auf fremden Rechnern immer ab. Nach einer Stunde ohne Aktivität beendet nibbly die Sitzung von selbst; danach meldest du dich einfach neu an. Im Bearbeitungsmodus hält nibbly die Sitzung offen, solange du arbeitest.</p>
</div>
