<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Unter <?php echo $hb->nav('Menü/Footer', 'menu'); ?> bearbeitest du die Menüs der Website, etwa die Hauptnavigation oben, und darunter die Angaben im Footer. Bei mehrsprachigen Websites wählst du oben die Sprache.</p>
</div>
<?php echo $hb->shot('menus.webp', 'Bereich Menüs und Footer mit der Hauptnavigation und ihren Einträgen', '<b>Menüs &amp; Footer.</b> Jeder Eintrag hat links einen Griff zum Verschieben und rechts den Schalter „Sichtbar“.'); ?>
<dl class="hb-fields">
  <div><dt>Eintrag bearbeiten</dt><dd>Eintrag aufklappen, dann <b>Beschriftung</b> (der Text im Menü) und <b>Linkziel</b> ändern. Unter <b>Öffnen in</b> wählst du, ob der Link im gleichen oder in einem neuen Fenster öffnet.</dd></div>
  <div><dt>Ein- und ausblenden</dt><dd>Der Schalter <?php echo $hb->toggle(true, 'Sichtbar'); ?> <b>Sichtbar</b> blendet einen Menüpunkt aus, ohne ihn zu löschen.</dd></div>
  <div><dt>Reihenfolge</dt><dd>Am Griff <?php echo $hb->icon('grip', 'Griff'); ?> ziehen oder im aufgeklappten Eintrag <?php echo $hb->ui('Nach oben'); ?> und <?php echo $hb->ui('Nach unten'); ?> klicken. <?php echo $hb->ui('Untereintrag'); ?> rückt einen Punkt unter den vorherigen ein, <?php echo $hb->ui('Ebene höher'); ?> holt ihn zurück.</dd></div>
  <div><dt>Neuer Menüpunkt</dt><dd><?php echo $hb->btn('Eintrag hinzufügen', 'secondary', 'plus'); ?>, dann eine <b>vorhandene Seite</b> wählen oder einen <b>freien Link</b> eintragen und <?php echo $hb->ui('Hinzufügen'); ?>.</dd></div>
  <div><dt>Löschen</dt><dd><?php echo $hb->ui('Eintrag entfernen'); ?> im aufgeklappten Eintrag. Die Seite selbst bleibt dabei erhalten.</dd></div>
</dl>

<h3>Footer</h3>
<div class="hb-prose">
  <p>Unter den Menüs stehen die Felder des Footers: Texte, Links, Linkgruppen, Logo und Kontaktangaben wie Telefon, E-Mail und Adresse. Was hier steht, erscheint auf allen Seiten.</p>
</div>
<?php echo $hb->note('Speichern nicht vergessen', 'Änderungen an Menüs und Footer übernimmt nibbly erst mit „Änderungen speichern“ in der Leiste unten. „Verwerfen“ macht alles seit dem letzten Speichern rückgängig. Vor jeder Änderung legt nibbly eine Sicherung an; unter „Sicherungen“ holst du ältere Stände zurück.'); ?>
