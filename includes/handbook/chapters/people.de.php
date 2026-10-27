<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Unter <?php echo $hb->nav('Personen', 'people'); ?> pflegst du Profile, etwa für das Team, Vortragende oder Ansprechpartner. Ein Profil legst du einmal an und zeigst es auf beliebig vielen Seiten. Profile haben nichts mit den Benutzerkonten für das Dashboard zu tun.</p>
</div>
<?php echo $hb->shot('people.webp', 'Personenliste mit Name, Status und Gruppen', '<b>Personen.</b> Oben filterst du nach Status und Gruppen.'); ?>

<h3>Profil anlegen</h3>
<ol class="hb-steps">
  <li><span>Klick auf <?php echo $hb->btn('Neue Person', 'primary', 'plus'); ?>.</span></li>
  <li><span><b>Name</b> (Pflicht), <b>Funktion</b> und <b>Organisation</b> eintragen, dazu eine <b>Kurzbeschreibung</b> und die ausführliche <b>Biografie</b>.</span></li>
  <li><span>Ein <b>Foto</b> wählen oder hochladen, dazu Alternativtext und Fotocredit.</span></li>
  <li><span>Unter <b>Weitere Angaben</b> stehen Zitat, Gruppen, Reihenfolge und Kontaktdaten. Achtung: Website, E-Mail und Telefon sind öffentlich sichtbar, sobald das Profil veröffentlicht ist.</span></li>
  <li><span><?php echo $hb->btn('Als Entwurf speichern'); ?>, solange noch etwas fehlt, oder <?php echo $hb->btn('Veröffentlichen', 'primary'); ?>.</span></li>
</ol>
<dl class="hb-fields">
  <div><dt>Gruppen</dt><dd>Mehrere Gruppen mit Komma getrennt, etwa „Team, Vorstand“. Damit zeigst du auf einer Seite gezielt eine Gruppe.</dd></div>
  <div><dt>Reihenfolge</dt><dd>Kleinere Zahlen stehen weiter vorne.</dd></div>
</dl>

<h3>Profile auf einer Seite zeigen</h3>
<div class="hb-prose">
  <p>Im Bearbeitungsmodus fügst du über <?php echo $hb->icon('plus', 'Block hinzufügen'); ?> einen Block ein: <b>Person</b> zeigt ein einzelnes Profil, <b>Team / Personengruppe</b> eine ganze Gruppe in zwei bis vier Spalten. Auf der Website erscheinen nur veröffentlichte Profile; jedes hat zusätzlich eine eigene Seite.</p>
</div>
