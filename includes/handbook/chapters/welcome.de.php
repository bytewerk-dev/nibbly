<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Die Website <b><?php echo $hb::esc($hb->siteName); ?></b> läuft auf <b>nibbly</b>, einem schlanken Content-Management-System. Wenn du WordPress kennst, findest du dich schnell zurecht: Es gibt ein Dashboard, Einträge mit Entwurf und Veröffentlichung, eine Mediathek und einen Papierkorb.</p>
  <p>Der größte Unterschied: Seiten bearbeitest du <b>direkt auf der Website</b>. Du klickst auf einen Text und tippst los, ganz ohne Blockeditor. Plugins, Theme-Updates und Datenbank gibt es nicht.</p>
  <p>Du arbeitest an zwei Orten:</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Direkt auf der Website</h4><p>Für Texte, Bilder und Links auf den Seiten. Dafür schaltest du oben in der Leiste den <b>Visuellen Editor</b> ein.</p></div>
  <div class="hb-card"><h4>Im Dashboard</h4><p>Für alles, was in Listen verwaltet wird: Seiten<?php
    $lists = array_filter([
        $hb->module('news') ? 'News' : '',
        $hb->module('events') ? 'Termine' : '',
        $hb->module('people') ? 'Personen' : '',
        $hb->module('messages') ? 'Nachrichten' : '',
    ]);
    echo $lists ? ', ' . $hb::esc(implode(', ', $lists)) : '';
  ?> und die Einstellungen.</p></div>
</div>

<h3>Dein WordPress-Wissen übersetzt</h3>
<div class="hb-table-scroll">
  <table class="hb-compare">
    <thead><tr><th>In WordPress</th><th>In nibbly</th><th>Anmerkung</th></tr></thead>
    <tbody>
      <tr><td>/wp-admin</td><td>/admin</td><td>Anmeldung und Dashboard.</td></tr>
      <tr><td>Gutenberg, Seiten bearbeiten</td><td>Visueller Editor</td><td>Bearbeitung direkt auf der Seite, so wie Besucher sie sehen.</td></tr>
      <?php if ($hb->module('news')): ?><tr><td>Beiträge</td><td>News</td><td>Mit Entwurf, Veröffentlichung und Beitragsbild.</td></tr><?php endif; ?>
      <tr><td>Mediathek</td><td>Mediathek und Bild-Manager</td><td>Bilder hochladen, in Ordnern ablegen, Alternativtext pflegen.</td></tr>
      <?php if ($hb->module('menus') && $hb->isAdmin): ?><tr><td>Design › Menüs</td><td>Menü/Footer</td><td>Ziehen zum Sortieren, Schalter für sichtbar oder versteckt.</td></tr><?php endif; ?>
      <tr><td>Revisionen</td><td>Backups</td><td>Ältere Stände einer Seite mit einem Klick zurückholen.</td></tr>
      <?php if ($hb->isAdmin): ?><tr><td>Plugins</td><td>Module</td><td>Zusatzfunktionen schaltest du in den Einstellungen ein und aus.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="hb-legend"><i></i> Rahmen in den Bildern zeigen, wohin du klickst. Ein Klick auf ein Bild vergrößert es.</p>
<?php if (!$hb->isAdmin): ?>
<?php echo $hb->note('Deine Ausgabe', 'Dieses Handbuch zeigt nur die Bereiche, die du mit deinem Konto sehen kannst. Einstellungen, Benutzer und Komplettsicherungen verwalten die Admins.'); ?>
<?php endif; ?>
