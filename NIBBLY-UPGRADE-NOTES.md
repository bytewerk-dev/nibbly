# Nibbly-Erkenntnisse aus dem Ordination-Rathmayr-Betrieb

Diese Notiz dokumentiert Befunde aus dem Live-Einsatz von Nibbly auf der
Ordination-Rathmayr-Site, die für eine **spätere Nibbly-Verbesserung im Core**
relevant sind. Sie ist gedacht als Input für eine separate Session, in der das
Nibbly-Basissystem entsprechend nachgeschärft wird.

Site: ordination-rathmayr.at
Aktuelle Nibbly-Version: 1.1.0 (Update durchgeführt 2026-04-28)

---

## Problem: `inline-editor.css` überschreibt die Settings-basierten Editor-Variablen

**Befund (2026-04-29):** Der Edit-Button in der Admin-Bar (`#admin-btn-edit`)
und andere Editor-UI-Elemente übernehmen die Werte aus
`content/settings.json → theme.primaryColor / buttonGlow / buttonRadius`
**nicht**. Sie bleiben in der Default-Nibbly-Blau-Farbe (`#2563eb`) mit dem
Default-Border-Radius (6 px) und immer dem Glow-Gradient — auch wenn die Site
in den Settings explizit eine andere Farbe und „flat" gewählt hat.

### Ursache

Nibbly hat zwei kollidierende Mechanismen:

1. **`includes/header.php`** (sowohl Core 1.1.0 als auch unsere Custom-Variante)
   injiziert nach dem Stylesheet-Block ein `<style>:root{ --editor-primary:…;
   --editor-btn-bg:…; --editor-btn-radius:…; … }</style>` mit den aus
   `settings.json` abgeleiteten Werten.
2. **`includes/footer.php`** lädt für eingeloggte Admins
   `<link rel="stylesheet" href="css/inline-editor.css">`. Diese Datei enthält
   selbst einen kompletten `:root { … }`-Block:

   ```css
   :root {
       --editor-primary: #2563eb;
       --editor-primary-hover: #1d4ed8;
       --editor-primary-light: #60a5fa;
       --editor-btn-bg: radial-gradient(ellipse at 50% 0%, #4580ed 0%, #2563eb 70%);
       --editor-btn-bg-hover: radial-gradient(ellipse at 50% 0%, #5d94ef 0%, #2563eb 70%);
       --editor-btn-radius: 6px;
       /* … */
   }
   ```

Da `inline-editor.css` **nach** dem injizierten `<style>` aus `header.php`
geladen wird und beide Blöcke dieselbe Spezifität haben, gewinnen in CSS
immer die später deklarierten Regeln — also die Defaults aus
`inline-editor.css`. Die Variablen-Injection in `header.php` ist damit für
jede UI-Stelle, die `inline-editor.css` betrifft, **wirkungslos**.

### Warum es im Default-Setup nicht auffällt

Der Default-Wert für `theme.primaryColor` in einer frischen Nibbly-Installation
ist `#2563eb` — exakt derselbe Hex-Code wie der `:root`-Default in
`inline-editor.css`. Solange ein Site-Owner die Farbe nicht ändert, fällt
der Bug nicht auf. Sobald die Farbe in den Settings angepasst wird, schlägt's
zu. Auf unserer Site (Primärfarbe `#8fab3b`, Site-Grün; `buttonGlow: false`;
`buttonRadius: 24`) war das sofort sichtbar.

### Beobachtung: das Backend hat den Bug nicht

Im Admin-Dashboard (`admin/dashboard.php`) übernehmen die Buttons die
konfigurierten Farben korrekt. Dort wird eine andere Variablenfamilie
verwendet (`--nb-primary-btn`), die nicht von einem späteren Stylesheet
überschrieben wird. Der Bug betrifft nur die Inline-Editor-UI im Frontend.

### Empfehlung für Nibbly Core (saubere Lösung)

Den `:root { … }`-Block aus `css/inline-editor.css` entfernen und die
Default-Werte stattdessen als zweites Argument in den `var()`-Aufrufen
liefern:

```css
/* Vorher (in :root-Block) */
:root {
    --editor-btn-bg: radial-gradient(ellipse at 50% 0%, #4580ed 0%, #2563eb 70%);
    --editor-btn-radius: 6px;
}

.admin-bar-btn-edit {
    background: var(--editor-btn-bg);
    border-radius: var(--editor-btn-radius);
}

/* Nachher (Defaults im var()-Fallback) */
.admin-bar-btn-edit {
    background: var(--editor-btn-bg, radial-gradient(ellipse at 50% 0%, #4580ed 0%, #2563eb 70%));
    border-radius: var(--editor-btn-radius, 6px);
}
```

Damit gibt es keine konkurrierende `:root`-Deklaration mehr, und der
`<style>`-Block aus `header.php` ist verbindlich. Aufwand: ein ~30-Minuten-
Refactor in einer einzigen Datei.

### Workaround in dieser Site

In `includes/footer.php` werden die Editor-Variablen ein zweites Mal nach
dem `<link>`-Tag zu `inline-editor.css` emittiert. Damit hat unser Block
das letzte Wort. Der Code dupliziert die Berechnung aus `header.php` und
sollte entfernt werden, sobald der Core-Fix in Nibbly umgesetzt ist.

Verifikation: Befund gegen `nibbly-main/css/inline-editor.css` 1.1.0
(Zeilen 7–24) und `nibbly-main/includes/footer.php:134` reproduzierbar.

---

## Problem: Reload bei strukturellen Änderungen reißt den Editor-Flow auseinander

**Befund (2026-04-29):** Wenn ein Admin im Visual Editor ein Listen-Item
hinzufügt, verschiebt oder löscht (z. B. einen Trust-Badge in der Hero-List
auf der Startseite), führt Nibbly automatisch einen Full-Page-Reload aus.
Beim Löschen kommt vorher ein Confirm-Dialog mit dem Hinweis „Die Seite wird
nach dem Löschen neu geladen."

Die UX-Wirkung: Andere noch nicht gespeicherte Änderungen auf derselben Seite
werden zwar implizit mitgespeichert (`saveStructuralChange` sammelt alle
`dirtyPages` ein), aber für den Nutzer fühlt sich das wie ein Bruch im
Editor-Flow an. Es wirkt, als müsse man „neu starten", obwohl technisch alles
sauber persistiert wird.

### Ursache (technisch begründet, aber konzeptionell unschön)

Die Funktion `saveStructuralChange()` in
[js/inline-editor.js:4276–4313](js/inline-editor.js#L4276-L4313) trägt im
Kommentar ihre Begründung selbst:

> *"Structural changes that require a page reload (add/delete sections or
> list items) because PHP templates render the HTML server-side."*

Heißt: Die Editor-JS-Seite kennt das PHP-Template nicht und kann ein neu
hinzugefügtes Listen-Item nicht selbst ins DOM injizieren — sie weiß nicht,
welche Klassen, welches Markup, welche Wrapper-Struktur. Daher wird brutal
neu geladen, mit `sessionStorage.setItem('site-edit-mode', 'true')`, damit
der Edit-Modus nach dem Reload sofort wieder anspringt.

### Warum das ein Core-Thema ist und kein Fix in der Site

Der Reload-Mechanismus ist im Core fest verdrahtet — sowohl `addListItem()`
als auch `deleteListItem()` rufen `saveStructuralChange()` auf. Es gibt keinen
Hook und keine Setting, mit der eine Site dieses Verhalten ausschalten könnte.

### Empfehlung für Nibbly Core

Mehrere mögliche Wege, in absteigender Eleganz:

1. **Server-seitiges Partial-Re-Render via API.** Statt eines Full-Reloads
   ruft der Editor einen API-Endpunkt auf, der ihm den HTML-Snippet für die
   neue Liste rendert (z. B. `POST /api/render-list?page=…&listKey=…`).
   Das Snippet wird per JS in den DOM ersetzt. Damit bleibt der Editor-State
   (Undo-Stack, Scroll-Position, andere offene Edits) erhalten.
2. **Iframe-Sandbox für Live-Preview.** Der eigentliche Inhalt läuft in
   einem `<iframe>`, das bei Strukturänderungen einzeln neu geladen wird —
   die Editor-UI außenrum (Admin-Bar, Undo, Save) bleibt stabil.
3. **Optimistic DOM-Insertion mit Cloning.** Beim Add ein vorhandenes
   Item klonen, Text-/Bildfelder leeren, in DOM einfügen. Beim Delete
   einfach das DOM-Element entfernen. Bricht aber, sobald das Template-
   Markup zwischen Items variiert (z. B. unterschiedliche Card-Typen).

Aufwand für (1): mittel — ein neuer API-Endpoint, ein JS-Hook in
`saveStructuralChange()`, und ein Render-Helper, der ein einzelnes
`editableListItems`-Set durchläuft. Realistisch ein Tagesprojekt.

### Status in dieser Site

Kein Workaround möglich, da das Verhalten im Core JS verdrahtet ist und
der Core JS bei Updates pauschal überschrieben wird. Der Reload bleibt
bestehen, bis Nibbly selbst eine der obigen Lösungen umsetzt.

### Folgebugs im aktuellen Reload-Pfad (auch ohne Refactor sofort fixbar)

Selbst wenn der Reload als Mechanismus akzeptiert bleibt, hat der aktuelle
Code zwei vermeidbare UX-Bugs, die unabhängig vom „großen" Partial-Render-
Refactor mit jeweils einer Zeile Code zu beheben sind. Beide reproduziert
auf der Live-Site beim Hinzufügen/Löschen eines Listen-Items.

**Bug A: Browser-Confirm-Dialog beim programmatischen Reload**

`enterEditMode()` registriert einen `beforeunload`-Listener
([js/inline-editor.js:754](js/inline-editor.js#L754)), der jede Navigation
und jedes Reload abfängt:

```js
function beforeUnloadGuard(e) {
    if (EditorConfig.editMode) {
        e.preventDefault();
        e.returnValue = '';
    }
}
```

`saveStructuralChange()` ruft am Ende `location.reload()` auf, ohne den
Listener vorher zu entfernen
([js/inline-editor.js:4309](js/inline-editor.js#L4309)). Folge: Der Browser
zeigt seinen nativen „Möchten Sie diese Seite wirklich verlassen?"-Dialog,
obwohl der Reload bewusst vom Editor selbst ausgelöst wurde und die Daten
in den vorherigen Zeilen bereits per API gespeichert wurden.

Vergleich: An den Stellen, an denen der Editor einen kontrollierten Navigation-
Reload macht — `navClickGuard` ([js/inline-editor.js:728](js/inline-editor.js#L728))
und `exitEditMode` ([js/inline-editor.js:772](js/inline-editor.js#L772)) —
wird der Listener vor dem Verlassen ordentlich abgemeldet. Im strukturellen
Reload-Pfad fehlt das.

**Fix (Core, eine Zeile):** In `saveStructuralChange()` direkt vor dem
`setTimeout(() => location.reload(), 300)`:

```js
window.removeEventListener('beforeunload', beforeUnloadGuard);
sessionStorage.setItem('site-edit-mode', 'true');
setTimeout(() => location.reload(), 300);
```

**Bug B: Toolbar zeigt nach Reload den „Edit starten"-Button, obwohl der
Edit-Modus aktiv ist**

Symptom: Nach dem Reload kann der Admin Felder weiterhin anklicken und
bearbeiten, die Top-Toolbar zeigt aber den blauen „Bearbeiten"-Start-Button
statt der Undo/Redo/Save-Controls. Inkonsistenter Zustand: `editMode === true`
intern, Toolbar zeigt `editMode === false`.

Ursache ist ein Race in der Init-Kette:

```js
Promise.all(loadPromises).then(() => {
    createEditorUI();
    // ...
    showAdminBar();              // async, awaitet fetch('/admin/api.php?action=load-settings')

    // Auto-restore edit mode after structural-change reload
    if (sessionStorage.getItem('site-edit-mode') === 'true') {
        sessionStorage.removeItem('site-edit-mode');
        enterEditMode();         // ruft updateAdminBarMode(true)
    }
});
```

`showAdminBar()` ist `async` und enthält ein `await fetch(...)` für die
Branding-Settings ([js/inline-editor.js:587–593](js/inline-editor.js#L587-L593)).
Wird ohne `await` aufgerufen, läuft der Code direkt mit `enterEditMode()`
weiter, **bevor** die Admin-Bar im DOM ist.

`enterEditMode()` → `updateAdminBarMode(true)` versucht dann
`document.querySelectorAll('.admin-bar-actions')` und `.admin-bar-edit-controls`
umzuschalten ([js/inline-editor.js:659–664](js/inline-editor.js#L659-L664)) —
beides sind leere NodeLists, die `forEach`-Schleifen tun nichts. Wenn der
Settings-Fetch wenige hundert Millisekunden später durchläuft und
`showAdminBar()` die Bar einfügt, hat die Bar ihre Default-Styles
(`display:none` auf den Edit-Controls, `display:''` auf den Actions).

**Fix (Core, eine Zeile):** Den Auto-Restore erst nach `showAdminBar()`
ausführen:

```js
Promise.all(loadPromises).then(async () => {
    createEditorUI();
    // ...
    await showAdminBar();        // <- await statt fire-and-forget

    if (sessionStorage.getItem('site-edit-mode') === 'true') {
        sessionStorage.removeItem('site-edit-mode');
        enterEditMode();
    }
});
```

Alternativ: `showAdminBar()` so umschreiben, dass die Branding-Settings
im Hintergrund nachgeladen werden und die Bar synchron mit Defaults im
DOM erzeugt wird (robuster, weil die Auto-Restore-Logik dann nicht mehr
auf eine fragile Promise-Kette angewiesen ist).

**Site-Workaround (2026-04-30 angewendet):** Beide Fixes betreffen
`js/inline-editor.js`, das 1:1 aus `nibbly-main/` kommt und bei jedem
Update überschrieben wird. Da die UX-Wirkung am Live-System spürbar ist,
wurden beide Patches lokal direkt angewendet:

- `saveStructuralChange()` ([js/inline-editor.js:4309–4317](js/inline-editor.js#L4309-L4317)):
  `window.removeEventListener('beforeunload', beforeUnloadGuard)` vor dem
  `setTimeout(() => location.reload(), 300)`.
- Init-`Promise.all().then()`-Block ([js/inline-editor.js:521–555](js/inline-editor.js#L521-L555)):
  Callback auf `async` umgestellt und `await showAdminBar()` verwendet,
  damit die Admin-Bar im DOM ist, bevor `enterEditMode()` aus der Auto-
  Restore-Logik sie umschalten will.

Beide Stellen sind im Quellcode mit dem Kommentar
`// Site-Workaround (siehe NIBBLY-UPGRADE-NOTES.md): …` markiert. Sobald
der Core diese Patches übernimmt, können die lokalen Änderungen beim
nächsten `nibbly-main`-Update einfach mit überschrieben werden — keine
weitere Pflege nötig.

**Zusatz-Fix: Cache-Busting für Editor-Assets** — *im Core erledigt
(Commit `9a0af13`).* `inline-editor.js`, `image-manager.js`,
`inline-editor.css` und `image-manager.css` werden jetzt im Admin-Block
von `includes/footer.php` mit `?v=<filemtime>` eingebunden, sodass der
Browser bei jeder Datei-Änderung am Server zwingend neu lädt. Site-Owner
müssen Admins nach Hotfixes nicht mehr zum Hard-Reload auffordern.

### Verifikation

Beide Bugs reproduziert mit Nibbly 1.1.0 auf ordination-rathmayr.at am
2026-04-29, beim Hinzufügen und Löschen von Trust-Badges in der Startseiten-
Hero-Liste.

---

## Problem: Hover-Toolbar überdeckt kleine Listen-Items / Sections

**Befund (2026-04-30):** Die Hover-Toolbar `.editable-list-overlay` (Drag,
Hide, Delete) wird per `position: absolute; top: 4px; right: 4px;`
**innerhalb** des Listen-Items platziert. Bei sehr kleinen Items — etwa
einem schmalen Trust-Badge in der Hero-Liste, einer einzelnen Card, oder
einer kompakten Info-Box-Zeile — ist die ~90 px breite und ~34 px hohe
Toolbar größer als der freie Klickbereich. Der Bearbeitungsrahmen darunter
ist dann praktisch nicht mehr klickbar, weil die Toolbar mit
`pointer-events: auto` darüberliegt und alle Mausevents abfängt.

Dasselbe gilt analog für `.editable-overlay` auf Sektionen — dort weniger
sichtbar, weil Sections in der Regel groß genug sind.

### Ursache

`css/inline-editor.css:1761` und `css/inline-editor.css:189` positionieren
beide Overlays mit `top: 0/4px` und `right: 0/4px` innerhalb des Items
bzw. der Section. Es gibt keine adaptive Logik, die bei kleinen Items
außerhalb positioniert.

### Empfehlung für Nibbly Core

Toolbar generell **oberhalb** des editierbaren Elements platzieren — sie
ragt aus dem Rahmen heraus, verdeckt nie den Inhalt, funktioniert für
beliebige Item-Größen. Konkret:

```css
.editable-list-overlay {
    bottom: calc(100% - 2px);   /* statt top: 4px */
    right: 0;
    /* die 2 px Überlappung mit dem Item dient als Hover-Brücke,
       damit der Hover beim Übergang Item → Toolbar nicht abreißt */
}

body.edit-mode-active .editable-list-item:hover .editable-list-overlay,
body.edit-mode-active .editable-list-overlay:hover {
    opacity: 1;
    pointer-events: auto;
}
```

Die zweite Selektor-Erweiterung (`.editable-list-overlay:hover`) hält die
Toolbar sichtbar, sobald die Maus über sie selbst fährt — sonst würde der
Hover des Items beim Mauswechsel ins Overlay verloren gehen.

`border-radius` von `0 4px 0 4px` (rechtsoben + linksunten gerundet,
unten ans Item gewachsen) auf `4px 4px 4px 0` (alles gerundet außer
linksunten) angepasst, sodass die Toolbar oben die Form einer Sprechblase
am Item hat.

Bei Top-of-Viewport-Items (Toolbar würde aus dem Viewport rutschen) ist
das in der Praxis nicht problematisch, weil die Admin-Bar bereits 40 px
Top-Offset reserviert. Falls doch nötig, kann ein zukünftiger Refactor
mit JS-basiertem Flip auf `top: 100%` erweitern.

### Site-Workaround (2026-04-30 angewendet)

Beide CSS-Regeln in [css/inline-editor.css](css/inline-editor.css) so
geändert, wie oben beschrieben — Block markiert mit
`/* Site-Workaround (siehe NIBBLY-UPGRADE-NOTES.md): … */`. Da
`inline-editor.css` ohnehin schon Site-modifiziert ist (vgl. Workaround
oben zur `:root`-Variablen-Kollision), ist das in dieser Site
pflegeleicht. Bei einem Nibbly-Update kommt der manuelle Diff sowieso
auf den Tisch.

---

## Erledigt: `data-hidden` auch außerhalb des Edit-Modus abgeblendet

*Im Core erledigt (Commit `51efae5`).* Die `[data-hidden="true"]`-Opacity-
Regel in `css/inline-editor.css` wurde zusätzlich an `body.has-admin-bar`
gekoppelt, sodass eingeloggte Admins versteckte Elemente konsistent
abgeblendet sehen — unabhängig vom Edit-Modus. Der frühere Site-Workaround
ist damit obsolet.

---

**Befund (2026-04-29):** Die mitgelieferte Deploy-Vorlage
[nibbly-main/deploy.example.sh](nibbly-main/deploy.example.sh) verwendet
ausschließlich `mirror --reverse --delete` (Lokal → Server, mit Löschabgleich).
Damit überschreibt jeder Deploy-Lauf die JSON-Dateien in `content/` mit der
**älteren** lokalen Version — und löscht zusätzlich alle vom Admin am Server
hochgeladenen Bilder in `assets/images/`.

Konkrete Symptome auf einer produktiven Site:

- Eine Redakteurin editiert über den Inline-Editor einen Text — der wird in
  `content/pages/de_xy.json` am Server geschrieben.
- Der Site-Owner deployt Code-Änderungen mit `bash deploy.sh`.
- Die JSON wird mit der älteren lokalen Version überschrieben. Die
  redaktionelle Änderung ist weg.

Dasselbe gilt für hochgeladene Bilder, neu eingegangene
Kontaktformular-Mails (`content/messages.json` o. ä.) und generell für
**alles, was der Admin-Workflow am Server schreibt** — also alles, wofür
Nibbly als CMS überhaupt gebaut ist.

### Warum das ein Core-Thema ist

Die Vorlage ist in `nibbly-main/` und wird von Site-Ownern zu `deploy.sh`
kopiert. Wer dem Setup-Hinweis folgt („Copy this file to deploy.sh, fill in
your FTP credentials, run it"), bekommt ohne weitere Hinweise ein
destruktives Verhalten — und merkt es erst, wenn der erste Datenverlust
passiert ist. Das widerspricht der Grundprämisse von Nibbly als
Flat-File-CMS: die JSON-Dateien sind die produktiven Daten, sie liegen am
Server, und der Editor schreibt direkt darauf.

### Empfehlung für Nibbly Core

Die Vorlage sollte ein **Drei-Phasen-Sync** sein, der bidirektional arbeitet
für Pfade, die der Admin schreibt. Beispiel-Skeleton:

```bash
# Phase 1: Pull — neuere Server-Inhalte holen (kein --delete)
mirror --only-newer content/ content/
mirror --only-newer assets/images/ assets/images/

# Phase 2: Push — Code mit --delete (Lokal ist Wahrheit für PHP/JS/CSS)
mirror --reverse --delete \
  --exclude-glob content/ \
  --exclude-glob assets/images/ \
  . .

# Phase 3: Push — Content/Asset-Bilder, nur falls lokal neuer (kein --delete)
mirror --reverse --only-newer content/ content/
mirror --reverse --only-newer assets/images/ assets/images/
```

Mit dieser Reihenfolge gilt durchgängig „neuere Datei gewinnt" für alle
Admin-geschriebenen Pfade, während Code weiterhin streng aus dem lokalen
Repo gespiegelt wird.

Zusätzliche Empfehlungen:

1. **OS-Müll global filtern.** Die Vorlage hat nur `--exclude-glob .DS_Store`
   im einen Mirror-Call. Sobald man mehrere Mirrors hat, vergisst man das.
   Eine Bash-Variable `OS_EXCLUDES='--exclude-glob .DS_Store --exclude-glob ._* …'`
   spart Pflegeaufwand. Die Setting `mirror:exclude-regex` als globale Option
   wirkt **nicht zuverlässig** in lftp (zumindest 4.9.x) — `--exclude-glob`
   pro Mirror-Call ist robuster.
2. **Setup-Hinweis im Header-Kommentar erweitern.** Aktuell steht da nur
   „uploads files". Es sollte explizit drinstehen: *„This script
   bidirectionally syncs `content/` and `assets/images/` (newer wins) to
   preserve admin edits made via the inline editor and image manager."*
3. **Optional: Lock-File-Mechanismus.** Wenn zwei Personen gleichzeitig
   deployen, kann die `--only-newer`-Logik problematische Race-Conditions
   erzeugen. Ein simples `touch .deploy.lock` am Anfang und Abbruch, falls
   das File jünger als 5 Minuten ist, würde das verhindern.

### Verifikation gegen aktuelle Vorlage

Die Vorlage in `nibbly-main/deploy.example.sh` (Stand 1.1.0, Zeilen 89–105)
hat exakt diesen Bug:

```bash
mirror --reverse --delete -v --no-perms \
  --exclude-glob .git/ \
  ...
  . .
```

Kein `--only-newer`, kein Pull-Schritt, keine Differenzierung zwischen Code
und Content. Wer das so übernimmt, verliert beim ersten Deploy alle
Admin-Änderungen seit dem letzten Push.

### Workaround in dieser Site

`deploy.sh` wurde manuell auf das oben skizzierte Drei-Phasen-Modell
umgebaut (Phase 1 Pull, Phase 2 Push Code, Phase 3 Push Content/Assets
nur falls neuer). Die Datei steht in `.gitignore` und muss bei einem
Nibbly-Update nicht zurückgespielt werden — die Korrektur sollte aber
in die Core-Vorlage einfließen, damit die nächste Site, die jemand auf
Basis dieser Vorlage aufsetzt, nicht in dieselbe Falle läuft.

---

## Thema: Migrations-Redirects sollten ein offizielles Core-Konzept bekommen

**Befund (2026-05-01):** Beim Ersatz der bisherigen WordPress-Installation
mussten die alten URLs aus der WordPress-Sitemap manuell in `.htaccess`
überführt werden. Besonders kritisch war die Tinnitus-Seite:

```apache
RewriteRule ^tcm/tinnitustherapie/?$ /tinnitus [R=301,L]
```

Zusätzlich gab es alte Detailseiten, die heute nur noch als Abschnitte
innerhalb neuer Sammelseiten existieren, z. B.:

```apache
RewriteRule ^allgemeinmedizin/fuehrerscheinuntersuchung-gemaess-.*$ /allgemeinmedizin#fuehrerschein [R=301,L,NE]
```

Das funktioniert, ist aber für Nibbly als Core-Thema relevant, weil solche
Migrationen bei kleinen CMS-Ablösen sehr häufig sind: WordPress hat alte
Sitemap-URLs, Nibbly hat neue Clean-URLs, teilweise mit Anker-Zielen. Wenn
dieser Schritt vergessen oder händisch schlecht gemacht wird, verliert die
Site unnötig Suchmaschinen-Rankings.

### Empfehlung für Nibbly Core

Ein optionales Redirect-Registry-Konzept einführen, z. B.
`content/redirects.json` oder `config/redirects.php`, das sowohl von der
Apache- `.htaccess`-Generierung als auch vom lokalen `router.php` gelesen
werden kann.

Vorschlag für JSON-Struktur:

```json
{
  "redirects": [
    {
      "from": "^/tcm/tinnitustherapie/?$",
      "to": "/tinnitus",
      "status": 301
    },
    {
      "from": "^/allgemeinmedizin/fuehrerscheinuntersuchung-gemaess-.*$",
      "to": "/allgemeinmedizin#fuehrerschein",
      "status": 301
    },
    {
      "from": "^/stress/lebensfeuer/?$",
      "status": 410
    }
  ]
}
```

Core-Vorteile:

- Eine Quelle für Produktion und lokalen Dev-Server.
- Redirects sind versionierbar und für Site-Owner lesbar.
- Fragment-Ziele (`#fuehrerschein`) können mit `NE`/No-Escape korrekt
  ausgegeben werden.
- 301, 302 und 410 können konsistent behandelt werden.
- Ein späteres Admin-UI könnte Redirects sichtbar machen, ohne `.htaccess`
  anfassen zu müssen.

### Ergänzendes Tooling

Ein kleines CLI-Tool wäre sinnvoll:

```bash
php cli/import-wordpress-sitemap.php https://example.at/wp-sitemap-posts-page-1.xml
```

Das Tool sollte die alten URLs auflisten, vorhandene Nibbly-Ziele vorschlagen
und eine Redirect-Tabelle als Draft erzeugen. Automatische Zuordnung nur dort,
wo Slugs eindeutig matchen; alles andere als `TODO` markieren.

---

## Problem: `nav: []` versteckt Seiten im Menü, macht sie aber weiterhin öffentlich erreichbar

**Befund (2026-05-01):** Die Datei `content/pages/de_fuehrerschein.json`
hatte `"nav": []` und erschien daher nicht in der Navigation. Trotzdem war
die Seite unter `/fuehrerschein` öffentlich erreichbar, weil die JSON-basierte
Front-Controller-Route jeden vorhandenen Page-Slug ausliefert.

In unserem Fall war das unerwünscht: Der Inhalt wurde inzwischen in
`/allgemeinmedizin#fuehrerschein` integriert. Die versteckte JSON-Seite
erzeugte dadurch eine zusätzliche, fachlich redundante URL.

### Warum das ein Core-Thema ist

Site-Owner interpretieren `"nav": []` intuitiv oft als „diese Seite ist
versteckt". Technisch heißt es aber nur „nicht in Menüs zeigen". Das ist
korrekt, aber missverständlich und kann bei Migrationen Duplicate-Content
oder veraltete Direktlinks erzeugen.

### Empfehlung für Nibbly Core

Die Page-Metadaten sollten eine explizite Routing-/Publikationsoption
bekommen, z. B.:

```json
{
  "nav": [],
  "published": false
}
```

oder granularer:

```json
{
  "nav": [],
  "route": {
    "enabled": false,
    "redirect": "/allgemeinmedizin#fuehrerschein",
    "status": 301
  }
}
```

Der Router bzw. die Front-Controller-Logik könnte dann:

- `published: false` mit 404 oder 410 beantworten,
- `route.redirect` mit 301/302 weiterleiten,
- Seiten weiterhin im Backend bearbeitbar lassen, ohne sie öffentlich
  direkt auszuliefern.

Mindestens sollte die Dokumentation klarer formulieren: `nav: []` entfernt
eine Seite nur aus Menüs; die URL bleibt öffentlich erreichbar.

---

## Thema: 410 Gone braucht eine gestaltete Core-Fehlerseite

**Befund (2026-05-01):** Für ersatzlos gestrichene Angebote ist `410 Gone`
SEO-seitig sinnvoller als ein irreführender 301-Redirect. Bei der Rathmayr-
Site betraf das die alten Stress-/Lebensfeuer-URLs. Ohne eigenes
`ErrorDocument 410` zeigt Apache jedoch eine rohe Standardseite, die nicht
zum restlichen Site-Design passt.

### Site-Workaround

Es wurde eine `410.php` analog zu `404.php` angelegt und in `.htaccess`
registriert:

```apache
ErrorDocument 410 /410.php
```

Die Seite verwendet dieselben `.error-page`-Klassen wie die 404-Seite und
zeigt eine neutrale deutschsprachige Meldung.

### Empfehlung für Nibbly Core

Nibbly sollte standardmäßig eine gestaltete `410.php` mitliefern und in der
Default- `.htaccess` registrieren:

```apache
ErrorDocument 404 /404.php
ErrorDocument 410 /410.php
```

Alternativ kann der Core eine generische `error.php?code=410`-Variante
nutzen, damit 404/410/500 nicht drei separate Templates duplizieren müssen.
Wichtig ist: Sobald Nibbly 410 als Redirect-/Routing-Option unterstützt,
muss es auch eine gebrandete 410-Ausgabe geben.

---

## Erledigt: 404-Sprachfallback respektiert `SITE_LANG_DEFAULT`

*Im Core erledigt (Commit `51b26eb`).* `404.php` fällt jetzt zuerst auf
`SITE_LANG_DEFAULT` zurück, dann erst auf `'en'`, und übernimmt einen
URL-Sprachpräfix nur, wenn dafür Übersetzungen existieren. Damit zeigt
eine deutschsprachige Site auch bei `/xx/foo` weiterhin deutsche
Fehlertexte statt englischer.

---

## Erledigt: `.error-page__code` ohne Gradient-Text-Default

*Im Core erledigt (Commit `414223f`).* Die Regel in `css/style.css`
verwendet jetzt einen einfachen `color: var(--color-primary-light, var(--color-primary))`
und kann von Sites mit einer normalen `color`-Regel überschrieben werden.
Sites, die den Gradient-Look wollen, können `.error-page__code--gradient`
zusätzlich vergeben.

---

## Thema: Automatisiertes Voll-Site-Backup mit gestaffelter Retention und Speicher-Budget

**Befund (2026-05-01):** Nibbly hat heute zwei Backup-Mechanismen, beide
ausschließlich event- bzw. on-demand-getrieben:

1. **Per-Page-JSON-Backups.** Bei jedem Save in der Admin-API wird die alte
   JSON-Version unter `backups/{lang}_{slug}_YYYY-MM-DD_HHMMSS.json` abgelegt;
   pro Seite werden max. 30 Versionen aufbewahrt (FIFO,
   [admin/api.php:69-80](admin/api.php#L69-L80)). Greift nur, wenn ein Admin
   tatsächlich speichert.
2. **Manuelles Site-Backup-ZIP** über das Backup-Tab im Dashboard. Erzeugt
   ein vollständiges ZIP der Site (ohne `node_modules`, `.git`, `screenshots`
   usw.), liefert es per One-Time-Token zum Download aus und löscht es nach
   dem Download wieder vom Server
   ([admin/api.php:2114-2222](admin/api.php#L2114-L2222),
   [admin/dashboard.php:1052-1123](admin/dashboard.php#L1052-L1123)).

Was fehlt:

- Kein zeitgesteuerter Voll-Site-Backup-Lauf (täglich oder mehrfach täglich).
- Keine CLI-Schnittstelle, die per Cron aufrufbar wäre.
- Keine Retention-Strategie über lange Zeiträume — sobald 30 Versionen einer
  Seite existieren, fällt die älteste raus, auch wenn sie der einzige Stand
  vor einer fehlerhaften Massenänderung wäre.
- Keine Übersicht oder Begrenzung des durch Backups belegten Speichers.

### Praxis-Motivation

Erfahrung aus WordPress-Wartung: Inhaltliche oder konfigurative Fehler
fallen oft erst Monate später auf — z. B. wenn ein Redakteur bemerkt, dass
seit einem halben Jahr ein Absatz fehlt, der ursprünglich da war. Ein
Backup, das nur die letzten 30 Edits oder das letzte ZIP vom Donnerstag
behält, hilft in solchen Fällen nicht. Brauchbar ist eine **gestaffelte**
Aufbewahrung, die nahe Vergangenheit fein abdeckt und ältere Stände grob
behält (Grandfather-Father-Son-Schema).

### Empfehlung für Nibbly Core

#### 1. Cron-fähige CLI-Schnittstelle

Neuer Befehl `cli/backup.php` analog zum vorhandenen
[cli/make.php](cli/make.php)-Stil (Shebang, `--key=value`-Argumente,
STDOUT/STDERR-Konventionen, Exit-Codes 0/1):

```bash
php cli/backup.php --action=run        # ein Backup-Lauf gemäß Schedule-Regeln
php cli/backup.php --action=prune      # Retention anwenden, alte Backups löschen
php cli/backup.php --action=status     # Anzahl + Größe + nächster geplanter Lauf
```

Beispiel-Cron für Plesk/cPanel/Shared-Hosting:

```cron
0 3 * * * cd /pfad/zur/site && php cli/backup.php --action=run >> backups/backup.log 2>&1
```

Implementierungsdetails:

- Lock-File (`backups/.backup.lock`) gegen parallele Läufe; Abbruch, falls
  jünger als die maximal erwartete Backup-Dauer (z. B. 30 Min).
- ZIP-Erzeugung in einen gemeinsamen Helper extrahieren (z. B.
  `includes/backup-helper.php`), den sowohl die On-Demand-API
  ([admin/api.php:2168-2213](admin/api.php#L2168-L2213)) als auch die CLI
  nutzen. Heute ist diese Logik in der API-Datei eingebettet und nur über
  den HTTP-Pfad erreichbar.
- Backup-Dateinamen mit Schema-Tag versehen, damit das Prune-Script
  Daily/Weekly/Monthly/Yearly-Backups unterscheiden kann, z. B.
  `site-backup-2026-05-01_030000-daily.zip`.

#### 2. Gestaffelte Retention (Grandfather-Father-Son)

Vorgeschlagener Default:

- **Daily:** die letzten 7 Tagessicherungen
- **Weekly:** 4 Wochensicherungen (das jeweils älteste Backup einer KW)
- **Monthly:** 12 Monatssicherungen
- **Yearly:** 3 Jahressicherungen

Konfigurierbar über einen neuen Top-Level-Key in
[content/settings.json](content/settings.json):

```json
{
  "backup": {
    "enabled": true,
    "schedule": "daily",
    "retention": { "daily": 7, "weekly": 4, "monthly": 12, "yearly": 3 },
    "storage_limit_mb": 2048,
    "destination": "backups/"
  }
}
```

Prune-Algorithmus:

1. Alle Backup-ZIPs scannen, nach Datum gruppieren.
2. Pro Bucket (Tag/Woche/Monat/Jahr) die jüngste Datei behalten, bis das
   konfigurierte Retention-Limit erreicht ist.
3. Alles, was in keinem Bucket markiert wurde, wird gelöscht.

Damit ergeben sich aus 7+4+12+3 = max. 26 dauerhaft vorgehaltenen Voll-
Backups einer Site. Bei einer typischen Nibbly-Site (≈ 50–200 MB)
realistisch 1–5 GB Backup-Speicher, je nach Asset-Volumen.

#### 3. Speicher-Budget und sichtbare Anzeige

Hartes Limit `storage_limit_mb`:

- Vor jedem Backup-Lauf: Pre-Check via `disk_free_space()` (analog zu
  [admin/api.php:2136-2139](admin/api.php#L2136-L2139)) **und** gegen das
  konfigurierte Site-Limit.
- Wird das Limit überschritten, löscht der Prune-Lauf zuerst die ältesten
  Backups oberhalb des Retention-Minimums; reicht das nicht, werden auch
  Backups innerhalb der Retention entfernt — mit deutlicher Warnung im Log
  und im Admin-UI, damit der Site-Owner das Limit erhöht oder Off-Site-
  Backups einrichtet.

Erweiterung des bestehenden Backup-Tabs
([admin/dashboard.php:1052-1123](admin/dashboard.php#L1052-L1123)) um:

- **Speicher-Anzeige** in MB/GB, live berechnet aus `du backups/`.
- **Backup-Übersicht:** Anzahl, ältestes/neuestes Datum, durchschnittliche
  Größe.
- **Retention-Konfiguration:** vier Felder für Daily/Weekly/Monthly/Yearly,
  Speicher-Limit-Feld, Schedule-Auswahl (täglich / 2× täglich / wöchentlich).
- **Schedule-Status:** letzter Lauf (mit Erfolg/Fehler), nächster geplanter
  Lauf.
- **Backup-Liste** mit Download/Restore/Delete-Buttons pro Datei. Die
  vorhandene `restore-site-backup`-Logik
  ([admin/api.php:2293](admin/api.php#L2293)) ist 1:1 wiederverwendbar — sie
  erwartet heute einen Upload, könnte aber genauso gut einen Pfad in
  `backups/` annehmen.

### Architekturentscheidung: echter Cron statt Pageview-Pseudo-Cron

Nibbly läuft ohne Background-Worker. Ein Pageview-getriggerter Pseudo-Cron
(„beim ersten Request des Tages mache ein Backup") ist auf Sites mit wenig
Traffic unzuverlässig, bremst beim ersten Request ungewollt aus, und kollidiert
schwer mit dem One-Time-Token-Flow des bestehenden Backup-Endpoints.

Empfohlen: echter System-Cron via CLI-PHP. Cron ist auf nahezu jedem
Shared-Hosting (Plesk, cPanel, DirectAdmin) verfügbar und in der Doku
einfach zu erklären. Für Sites ohne Cron-Zugang als **Notnagel**: Page-
View-Trigger mit klar dokumentierten Einschränkungen, gated über ein
Setting `backup.fallback_pageview_trigger: true`.

### Off-Site-Backup als Folgeschritt

Lokale Backups schützen nicht vor Server-Totalverlust (Hoster-Pleite,
gelöschte Webroot, Ransomware auf dem Server). Sinnvolle Erweiterung im
zweiten Schritt:

```bash
php cli/backup.php --action=run --upload-to=sftp://user@backup-host/path
php cli/backup.php --action=run --upload-to=s3://bucket/prefix
```

Nicht im Initial-Scope. Sollte aber in der Architektur des Helpers
mitgedacht werden, damit der Upload-Hook ohne Refactor nachgezogen werden
kann.

### Status

Auf der Live-Site nicht site-lokal nachgebaut, weil eine Implementierung
zwingend ins Core gehört: die neue CLI-Datei, der Helper-Refactor und die
UI-Erweiterung lassen sich nicht sauber gegen ein einzelnes Site-Repo
pflegen, ohne bei jedem Nibbly-Update überschrieben zu werden, und die
Settings-Schemaerweiterung muss vom Core selbst migriert werden.

Auf der Live-Site wird aktuell mit den vorhandenen Mitteln gearbeitet:
manuelle Site-Backups via Dashboard, per-Page-Backups (max. 30 Versionen)
bei jedem Save, plus die im Deploy-Abschnitt oben beschriebene
Drei-Phasen-Sync-Strategie, die Server-Stände vor Überschreibung schützt.
Das ist ein tragfähiger Notbetrieb, ersetzt aber kein historisches
Sicherheitsnetz für Spät-Fehler-Erkennung.
