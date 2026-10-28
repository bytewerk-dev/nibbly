<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>nibbly kann mit einem KI-Dienst zusammenarbeiten, etwa OpenAI, Anthropic (Claude) oder OpenRouter. Dafür braucht es ein eigenes Konto bei diesem Dienst und einen API-Schlüssel<?php echo $hb->isAdmin ? ', den du unter Einstellungen › ' . $hb->ui('KI-Einstellungen') . ' einträgst' : ', den ein Admin einträgt'; ?>. Ohne Schlüssel zeigt das Dashboard den Hinweis „KI ist nicht konfiguriert“.</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Assistent</h4><p>Ein Chat auf der Startseite des Dashboards und, wenn eingeschaltet, im Visuellen Editor. Er beantwortet Fragen und bereitet Änderungen vor, die du prüfst und mit „Änderungen übernehmen“ freigibst. „Rückgängig“ nimmt sie wieder zurück.</p></div>
  <div class="hb-card"><h4>Text und SEO</h4><p>Füllt SEO-Titel, Meta-Beschreibung und Kurzantwort einer Seite aus, schlägt Texte vor und übersetzt Seiten, News, Termine und Profile in eine weitere Sprache.</p></div>
  <div class="hb-card"><h4>Bildgenerator</h4><p>Erzeugt Bilder aus einer Beschreibung, im Dashboard und in der Bildverwaltung. KI-Bilder bekommen eine Kennzeichnung, wie sie das EU-Recht verlangt.</p></div>
</div>
<?php if ($hb->isAdmin): ?>
<?php echo $hb->shot('settings-ai.webp', 'KI-Einstellungen mit Anbieter, API-Schlüssel, Funktionen und Limits', '<b>KI-Einstellungen.</b> Anbieter und Schlüssel, welche Funktionen aktiv sind, Monatsbudget und was Redakteure dürfen.'); ?>
<dl class="hb-fields">
  <div><dt>Funktionen</dt><dd>Assistent, Text- und SEO-Generierung, Bildgenerierung, und ob der Chat im Visuellen Editor und im Dashboard erscheint.</dd></div>
  <div><dt>Limits</dt><dd>Monatsbudget und Anfragen pro Tag schützen vor unerwarteten Kosten. <?php echo $hb->ui('Verbrauch diesen Monat'); ?> zeigt den Stand.</dd></div>
  <div><dt>Was Redakteure dürfen</dt><dd>Legt fest, was der Assistent für Redakteure tun darf, etwa veröffentlichen, Bilder erzeugen oder neue Seiten anlegen.</dd></div>
</dl>
<?php echo $hb->note('Datenschutz', 'Texte und Bilder, die du an die KI schickst, gehen an den gewählten Anbieter. Schick keine vertraulichen oder personenbezogenen Daten mit, die dort nicht hingehören.'); ?>
<?php endif; ?>
