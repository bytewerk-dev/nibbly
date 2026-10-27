<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Unter <?php echo $hb->nav('Seiten', 'pages'); ?> im Dashboard siehst du alle Seiten der Website. Über jedem Titel steht die Adresse der Seite (etwa <code>/kontakt</code>), daneben die SEO-Bewertung. Bei mehrsprachigen Websites hat jede weitere Sprache eine eigene Spalte.</p>
</div>
<?php echo $hb->shot('pages.webp', 'Seitenliste mit Titel, Adresse, SEO-Bewertung und Änderungsdatum; eine Zeile ist markiert', '<b>Seitenliste.</b> Fährst du mit der Maus über eine Zeile, erscheinen Bearbeiten, Ansehen, Duplizieren und Papierkorb.'); ?>
<dl class="hb-fields">
  <div><dt>Bearbeiten</dt><dd>Öffnet die Seite im Inhaltseditor des Dashboards (siehe unten).</dd></div>
  <div><dt>Ansehen</dt><dd>Öffnet die Seite auf der Website in einem neuen Tab. Dort bearbeitest du sie mit dem Visuellen Editor.</dd></div>
  <div><dt>Duplizieren</dt><dd>Legt eine Kopie an, praktisch als Vorlage für eine ähnliche Seite.</dd></div>
  <div><dt>Papierkorb</dt><dd>Verschiebt die Seite in den Papierkorb, ohne Rückfrage. Über den Filter <?php echo $hb->ui('Papierkorb'); ?> oben holst du sie mit <?php echo $hb->ui('Wiederherstellen'); ?> zurück.</dd></div>
  <div><dt>+ Erstellen</dt><dd>In der Spalte einer weiteren Sprache: legt die Übersetzung als Kopie an, die du dann übersetzt.</dd></div>
</dl>

<h3>Neue Seite anlegen</h3>
<ol class="hb-steps">
  <li><span>Klick oben rechts auf <?php echo $hb->btn('Neue Seite', 'primary', 'plus'); ?>.</span></li>
  <li><span>Gib einen <b>Titel</b> ein. Den <b>Slug</b>, also den Teil der Adresse, füllt nibbly daraus aus. Ein Schrägstrich macht eine Unterseite, etwa <code>leistungen/beratung</code>.</span></li>
  <li><span><?php echo $hb->btn('Seite erstellen', 'primary'); ?>. Die neue Seite ist sofort öffentlich erreichbar. In welchem Menü sie erscheint, legst du im Inhaltseditor unter <b>Navigation</b> fest<?php if ($hb->module('menus') && $hb->isAdmin): ?> oder unter <?php echo $hb->ref('menus'); ?><?php endif; ?>.</span></li>
</ol>
<?php echo $hb->note('Entwürfe', 'Seiten haben keinen Entwurfsstatus. Soll eine Seite noch nicht öffentlich sein, stell sie in den Seiteneinstellungen auf „Privat, passwortgeschützt“. Angemeldete Benutzer sehen sie trotzdem ohne Passwort.'); ?>

<h3>Der Inhaltseditor</h3>
<div class="hb-prose">
  <p>Der Inhaltseditor zeigt eine Seite als Formular: oben Titel und Text, darunter die Abschnitte zum Aufklappen. Rechts oben stehen <?php echo $hb->btn('Speichern', 'primary'); ?>, <?php echo $hb->ui('Ansehen'); ?> und die Sprachen. Änderungen im Inhaltseditor gelten erst nach dem Speichern.</p>
</div>
<?php echo $hb->shot('page-editor.webp', 'Inhaltseditor der Startseite mit Titel, Textfeld und Seitenaktionen', '<b>Inhaltseditor.</b> Dieselben Inhalte wie auf der Website, Feld für Feld.'); ?>
<dl class="hb-fields">
  <div><dt>Abschnitte</dt><dd>Aufklappen zum Bearbeiten, am Griff ziehen zum Sortieren, mit <?php echo $hb->ui('Hier einfügen'); ?> einen neuen Abschnitt an einer Stelle einfügen. Hinweise wie „Alt-Text fehlt“ zeigen, wo etwas fehlt.</dd></div>
  <div><dt>Basisdaten</dt><dd>Titel und Beschreibung der Seite.</dd></div>
  <div><dt>Navigation</dt><dd>In welchen Menüs die Seite erscheint, und der Brotkrumenpfad.</dd></div>
  <div><dt>Zugriff</dt><dd><?php echo $hb->ui('Öffentlich'); ?> oder <?php echo $hb->ui('Privat, passwortgeschützt'); ?> mit eigenem Seitenpasswort und Text für die Passwortseite.</dd></div>
  <div><dt>SEO / AEO</dt><dd>SEO-Titel, Meta Description (der Text unter dem Link in Google), eine Kurzantwort für KI-Suchen und das Bild für geteilte Links. Die SEO-Bewertung oben zeigt, was noch fehlt.</dd></div>
</dl>

<h3>SEO-Bewertung</h3>
<div class="hb-prose">
  <p>Die Bewertung neben jedem Seitentitel und in der Leiste auf der Website lautet <?php echo $hb->ui('SEO gut'); ?>, <?php echo $hb->ui('SEO prüfen'); ?> oder <?php echo $hb->ui('SEO kritisch'); ?>, mit Punkten bis 100. Fährst du auf der Website darüber, siehst du die Liste der Hinweise, etwa eine fehlende Meta-Beschreibung oder Bilder ohne Alternativtext. Ein Klick öffnet den Inhaltseditor.</p>
</div>
