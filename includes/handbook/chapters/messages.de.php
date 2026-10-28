<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Was Besucher über ein Formular der Website schicken, etwa das Kontaktformular, landet unter <?php echo $hb->nav('Nachrichten', 'mail'); ?>. Eine Zahl am Menüpunkt zeigt ungelesene Nachrichten. Ist der E-Mail-Versand eingerichtet, kommen die Nachrichten zusätzlich per E-Mail.</p>
</div>
<?php echo $hb->shot('messages.webp', 'Liste der Kontaktnachrichten mit Absender, Betreff und Eingangsdatum', '<b>Nachrichten.</b> Ungelesene stehen fett. Mit dem Stern markierst du wichtige Nachrichten.'); ?>
<dl class="hb-fields">
  <div><dt>Lesen</dt><dd>Ein Klick auf eine Zeile öffnet die Nachricht und markiert sie als gelesen. Über die Symbole in der Zeile stellst du sie wieder auf ungelesen oder setzt einen Stern.</dd></div>
  <div><dt>Aufräumen</dt><dd><?php echo $hb->ui('Alle als gelesen markieren'); ?> und <?php echo $hb->ui('Gelesene löschen'); ?> stehen oben.</dd></div>
  <div><dt>Löschen</dt><dd>In der geöffneten Nachricht. Gelöschte Nachrichten sind endgültig weg, es gibt keinen Papierkorb.</dd></div>
</dl>
<?php if ($hb->isAdmin): ?>
<div class="hb-prose">
  <p>Welche Felder ein Formular hat und ob es Nachrichten speichert oder per E-Mail sendet, legst du unter Einstellungen › <?php echo $hb->ui('Formulare'); ?> fest. Fehlt etwas, zeigt die Nachrichtenseite oben einen Hinweis mit einem Link dorthin.</p>
</div>
<?php endif; ?>
