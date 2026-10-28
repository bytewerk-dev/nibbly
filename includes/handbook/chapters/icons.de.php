<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>Der <?php echo $hb->nav('Icon Manager', 'icons'); ?> verwaltet die Symbole der Website, etwa für Karten oder Aufzählungen. Wählst du in einem Block ein Icon aus, stammt es aus dieser Sammlung. Jedes Icon zeigt, ob es zum Standard von nibbly gehört oder selbst hinzugefügt wurde.</p>
</div>
<?php echo $hb->shot('icons.webp', 'Icon Manager mit Suchfeld, Sortierung, globaler Strichstärke und einem Raster von Icons', '<b>Icon Manager.</b> Oben Suche, Sortierung und globale Strichstärke, darunter alle Icons.'); ?>
<dl class="hb-fields">
  <div><dt>Aus Icon-Bibliothek importieren</dt><dd>Durchsucht eine große, freie Icon-Sammlung. Ausgewählte Icons speichert nibbly auf der eigenen Website; die Lizenz des Icon-Sets wird angezeigt. Importierte Icons übernehmen die globale Strichstärke; Stil und Lizenzquelle bleiben auch nach dem Speichern erhalten.</dd></div>
  <div><dt>Icon hinzufügen</dt><dd>Für eigene Icons: einen eindeutigen Schlüssel, eine Beschriftung und den SVG-Code einfügen. Die Vorschau zeigt sofort, wie es aussieht. <?php echo $hb->ui('currentColor setzen'); ?> sorgt dafür, dass das Icon die Textfarbe der Website übernimmt.</dd></div>
  <div><dt>Darstellung</dt><dd>Im Bearbeitungsformular legst du fest, ob ein Icon als „Linie (Kontur)“ oder „Fläche (gefüllt)“ gezeichnet ist. nibbly erkennt das beim Einfügen aus dem SVG-Code und schlägt den passenden Wert vor; bei Bedarf stellst du ihn um.</dd></div>
  <div><dt>Strichstärke (global)</dt><dd>Das Feld in der Leiste oben legt die Linienstärke für alle Linien-Icons fest, die keine eigene haben. Standard ist 2. So wirken Icons aus verschiedenen Sammlungen einheitlich.</dd></div>
  <div><dt>Strichstärke pro Icon</dt><dd>Optionales Feld im Bearbeitungsformular. Bleibt es leer, gilt die globale Strichstärke. Steht im SVG-Code eine einheitliche Strichstärke, übernimmt nibbly sie beim Speichern automatisch in dieses Feld. Icons mit bewusst gemischten Stärken bleiben unverändert. Erlaubt sind Werte von 0,25 bis 6.</dd></div>
  <div><dt>Bearbeiten und Löschen</dt><dd>Ändert sich der Schlüssel eines Icons oder wird es gelöscht, zeigen Inhalte, die es verwenden, ein Standard-Icon.</dd></div>
</dl>
