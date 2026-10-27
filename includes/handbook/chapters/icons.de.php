<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Der <?php echo $hb->nav('Icon Manager', 'icons'); ?> verwaltet die Symbole der Website, etwa für Karten oder Aufzählungen. Wählst du in einem Block ein Icon aus, stammt es aus dieser Sammlung. Jedes Icon zeigt, ob es zum Standard von nibbly gehört oder selbst hinzugefügt wurde.</p>
</div>
<?php echo $hb->shot('icons.webp', 'Icon Manager mit Suchfeld, Sortierung und einem Raster von Icons', '<b>Icon Manager.</b> Oben Suche und Sortierung, darunter alle Icons.'); ?>
<dl class="hb-fields">
  <div><dt>Aus Icon-Bibliothek importieren</dt><dd>Durchsucht eine große, freie Icon-Sammlung. Ausgewählte Icons speichert nibbly auf der eigenen Website; die Lizenz des Icon-Sets wird angezeigt.</dd></div>
  <div><dt>Icon hinzufügen</dt><dd>Für eigene Icons: einen eindeutigen Schlüssel, eine Beschriftung und den SVG-Code einfügen. Die Vorschau zeigt sofort, wie es aussieht. <?php echo $hb->ui('currentColor setzen'); ?> sorgt dafür, dass das Icon die Textfarbe der Website übernimmt.</dd></div>
  <div><dt>Bearbeiten und Löschen</dt><dd>Ändert sich der Schlüssel eines Icons oder wird es gelöscht, zeigen Inhalte, die es verwenden, ein Standard-Icon.</dd></div>
</dl>
