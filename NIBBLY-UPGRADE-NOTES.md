# Nibbly-Erkenntnisse aus dem Ordination-Rathmayr-Betrieb

Diese Notiz dokumentiert Befunde aus dem Live-Einsatz von Nibbly auf der
Ordination-Rathmayr-Site, die für eine **spätere Nibbly-Verbesserung im Core**
relevant sind. Sie ist gedacht als Input für eine separate Session, in der das
Nibbly-Basissystem entsprechend nachgeschärft wird.

Site: ordination-rathmayr.at
Aktuelle Nibbly-Version: 1.1.0 (Update durchgeführt 2026-04-28)

---

## Release 1.2.0: Backup, Remote-Ziele und Dashboard-Routing

Version: 1.2.0
Datum: 2026-05-06

Diese Release-Notiz dokumentiert die Dateien, die sich gegenüber 1.1.0 geändert
haben. Sie ist bewusst dateibezogen gehalten, damit bestehende Installationen
vor einem Update prüfen können, ob lokale Anpassungen mit Core-Dateien
kollidieren.

### Funktionale Änderungen

- Automatisierte Voll-Site-Backups via `cli/backup.php --action=run`.
- Gestaffelte Retention für tägliche, wöchentliche, monatliche und jährliche
  Backups.
- Speicherlimit für lokale nicht-manuelle Backup-ZIPs.
- Remote-Backup-Ziele: Dropbox, Google Drive, Microsoft OneDrive, FTP/FTPS,
  SFTP/SCP, S3-kompatibler Speicher und WebDAV.
- Browserbasierte OAuth-Verbindung für Dropbox, Google Drive und Microsoft
  OneDrive über einen konfigurierbaren Nibbly Auth-Broker. Diese drei Anbieter
  sind im UI als Beta markiert, weil Provider-Freigaben die Nutzung für fremde
  Benutzer einschränken können.
- Remote-Dateien können, soweit vom Ziel unterstützt, angezeigt, lokal
  importiert und vom Remote-Speicher gelöscht werden.
- Lokale Backup-Liste wurde im Dashboard weiter nach oben gezogen; Scheduled
  Backup Settings, Remote-Ziele und Cron-Hilfe wurden neu strukturiert.
- Dashboard nutzt saubere `/admin/dashboard`-URLs mit Hash-Routen für Bereiche
  und Settings-Subtabs.
- Settings-Tab `password` wurde zu `my-account`.

### Geänderte Core-Dateien

- `.gitignore`
- `AI-AGENT-GUIDE.md`
- `README.md`
- `CHANGELOG.md`
- `NIBBLY-UPGRADE-NOTES.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/index.php`
- `admin/setup.php`
- `admin/style.css`
- `admin/lang/cs.json`
- `admin/lang/de.json`
- `admin/lang/en.json`
- `admin/lang/es.json`
- `admin/lang/fr.json`
- `admin/lang/it.json`
- `admin/lang/pl.json`
- `admin/lang/pt.json`
- `admin/lang/tr.json`
- `architecture.md`
- `cli/README.md`
- `cli/backup.php`
- `css/nibbly-admin-components.css`
- `includes/backup-helper.php`

### Update-Konflikt-Risiko

- **Hoch:** `admin/dashboard.php`, `admin/api.php`,
  `includes/backup-helper.php`, `admin/style.css`, `cli/backup.php`.
  Installationen mit lokalen Änderungen in diesen Dateien sollten vor dem
  Update einen Diff prüfen.
- **Mittel:** `admin/lang/*.json`, `admin/config.example.php`, `admin/setup.php`,
  `admin/index.php`, `css/nibbly-admin-components.css`.
- **Niedrig:** Dokumentationsdateien und `.gitignore`.

### Hinweise für bestehende Installationen

- Bestehende `admin/config.php`-Dateien werden nicht automatisch überschrieben.
  Wenn die angezeigte Version aktualisiert werden soll, muss
  `NIBBLY_VERSION` dort manuell auf `1.2.0` gesetzt oder die Konfiguration neu
  erzeugt werden.
- Für automatische Backups muss serverseitig ein Cronjob eingerichtet werden,
  der `cli/backup.php --action=run` ausführt.
- FTP/FTPS benötigt die PHP-FTP-Erweiterung.
- SFTP/SCP benötigt die PHP-Erweiterung `ssh2`.
- Dropbox, Google Drive und Microsoft OneDrive hängen bei der komfortablen
  Browser-Verbindung vom Freigabestatus der jeweiligen OAuth-App ab. Für
  produktive Installationen ohne diese Freigaben sollten FTP/FTPS, SFTP/SCP,
  S3 oder WebDAV bevorzugt werden.

---

## Erledigt: `inline-editor.css` überschreibt nicht mehr die Settings-Variablen

*Im Core erledigt (Commit `a2c5954`).* Der konkurrierende `:root`-Block
in `css/inline-editor.css` wurde entfernt; jeder `var(--editor-*)`-Aufruf
führt seinen Default jetzt im zweiten Argument mit. Die Settings-basierte
Variablen-Injektion aus `includes/header.php` (gerendert spät via
`includes/footer.php`) ist damit verbindlich. Der frühere Site-Workaround
(doppelte Injektion nach dem `<link>`-Tag) ist obsolet — die Site kann ihn
beim nächsten Update zurückbauen.

---

## Teilweise erledigt: Reload bei strukturellen Änderungen

Drei Folgebugs im Reload-Pfad sind im Core gefixt:

- **`beforeUnloadGuard` beim programmatischen Reload** (Commit `f998941`):
  `saveStructuralChange()` entfernt den Guard jetzt vor `location.reload()`,
  sodass der Browser keinen „Seite verlassen?"-Dialog mehr zeigt.
- **Toolbar-Race nach Reload** (Commit `561c92e`): Der Init-`Promise.all().then()`-
  Block awaitet jetzt `showAdminBar()`, bevor `enterEditMode()` aus dem
  Auto-Restore die Toolbar umschaltet. Damit ist die Bar garantiert im DOM,
  bevor sie referenziert wird.
- **Cache-Busting für Editor-Assets** (Commit `9a0af13`): JS/CSS werden
  mit `?v=<filemtime>` eingebunden, sodass Hotfixes ohne Hard-Reload
  beim Admin ankommen.

**Aufgeschoben:** Der eigentliche Reload-Mechanismus bei Add/Delete von
Listen-Items und Sections bleibt bestehen. Mit den drei obigen Fixes ist
die UX deutlich entschärft (kein Browser-Dialog, Toolbar zeigt nach Reload
korrekt den Edit-Modus, Hotfixes greifen sofort), und der Reload selbst
fühlt sich nicht mehr nach „Bruch im Editor-Flow" an. Ein vollständiger
Refactor (Server-seitiges Partial-Re-Render via API, Iframe-Sandbox, oder
Optimistic DOM-Insertion) ist realistisch ein Tagesprojekt mit nicht-
trivialen Risiken (Template-Varianz, verlorener Editor-State innerhalb
der Liste) und wartet auf einen späteren Zeitpunkt — sollte die UX-Wirkung
nach den drei Folgebug-Fixes weiterhin als störend empfunden werden.

---

## Erledigt: Hover-Toolbar überdeckt nicht mehr kleine Items

*Im Core erledigt (Commit `808d205`).* Beide Overlays (`.editable-overlay`
auf Sections, `.editable-list-overlay` auf Listen-Items) sind jetzt mit
`bottom: calc(100% - 2px)` oberhalb des Elements verankert. Die 2 px
Überlappung dient als Hover-Brücke, und ein zusätzlicher Selektor
`.editable-overlay:hover` hält die Toolbar sichtbar, wenn der Cursor
auf sie wechselt. Damit funktioniert die Toolbar auch auf schmalen
Trust-Badges, Single-Cards und Info-Box-Rows ohne den Klickbereich
darunter zu blockieren.

---

## Erledigt: `data-hidden` auch außerhalb des Edit-Modus abgeblendet

*Im Core erledigt (Commit `51efae5`).* Die `[data-hidden="true"]`-Opacity-
Regel in `css/inline-editor.css` wurde zusätzlich an `body.has-admin-bar`
gekoppelt, sodass eingeloggte Admins versteckte Elemente konsistent
abgeblendet sehen — unabhängig vom Edit-Modus. Der frühere Site-Workaround
ist damit obsolet.

---

## Erledigt: Deploy-Vorlage zerstört keine Server-Daten mehr

*Im Core erledigt (Commit `09be3d3`).* `deploy.example.sh` läuft jetzt in
drei Phasen: Phase 1 holt neuere admin-geschriebene Dateien (`content/`,
`assets/images/`) vom Server zurück, Phase 2 pusht Code mit `--delete`
unter Ausschluss admin-geschriebener Pfade, Phase 3 pusht admin-geschriebene
Pfade nur falls lokal neuer und ohne `--delete`. OS-Müll und Code-Excludes
sind in Bash-Variablen ausgelagert, sodass sie konsistent über alle
Mirror-Calls gelten. Header-Kommentar erklärt explizit den Daten-
Erhaltungs-Vertrag.

---

## Erledigt: Migrations-Redirect-Registry

*Im Core erledigt (Commit `a2abd97`).* `content/redirects.json` ist
jetzt die zentrale Quelle für URL→URL-Mappings. Sowohl `route.php`
(Apache-Pfad) als auch `router.php` (Dev-Server) lesen dieselbe Datei
über `includes/redirect-helper.php`. Unterstützt 301, 302 und 410 mit
Backreferences und Anker-Zielen. Eine Beispiel-Datei liegt in
`content/redirects.example.json`; die Live-Datei ist gitignored.
Doku in CLAUDE.md im Abschnitt „Migration Redirects".

**Aufgeschoben (späterer Schritt):** Das ergänzende CLI-Tool
`cli/import-wordpress-sitemap.php`, das eine WP-Sitemap parst und
einen Redirect-Tabellen-Draft erzeugt, ist bewusst nicht in diesem
Schritt umgesetzt — die manuelle Pflege der `redirects.json` reicht
für den Anfang, das Tool ist eine Ergonomie-Erweiterung für später.

---

## Erledigt (Doku): `nav: []` versteckt nur aus Menüs, URL bleibt erreichbar

*Im Core erledigt als Doku-Klarstellung (Commit `29ba5fd`).* CLAUDE.md
weist im Nav-Abschnitt jetzt explizit darauf hin, dass `nav: []` keine
Routing-Wirkung hat, und nennt die richtigen Werkzeuge für ungewollte
URLs (JSON löschen, 301 in `.htaccess`, oder `410.php` für ersatzlos
entfernte Inhalte).

Bewusst **nicht umgesetzt** wurde der weitergehende Vorschlag, ein
`published: false`- oder `route.redirect`-Feld im Page-JSON einzuführen
— das überschneidet sich mit dem geplanten Migrations-Redirect-Konzept
(siehe Abschnitt unten) und sollte erst dort konsistent gelöst werden.

---

## Erledigt: 410 Gone hat eine gebrandete Core-Fehlerseite

*Im Core erledigt (Commit `318b635`).* Es gibt jetzt eine generische
`error.php`, parametrisiert auf `$errorCode`, und schlanke Wrapper
`404.php` und `410.php`, die den Renderer einbinden. Übersetzungen für
404 und 410 sind in de/en/es vorhanden. Default-`.htaccess` registriert
beide ErrorDocuments. Neue Codes (z. B. 503 Maintenance) lassen sich
mit einem Translations-Block plus 5-Zeilen-Wrapper hinzufügen.

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

## Erledigt: Automatisiertes Voll-Site-Backup mit gestaffelter Retention

*Im Core erledigt (Commits `4ed4b16`, `f9eff6f`, `3e0007f`, `02cbe0a`,
`ed378cb`).* Vollständiges Backup-System mit allen drei ursprünglich
geforderten Komponenten:

- **CLI** (`cli/backup.php`) für Cron-Setup mit `--action=run|prune|status|list`,
  Lock-File gegen parallele Läufe, definierten Exit-Codes.
- **Gestaffelte Retention** (Grandfather-Father-Son): Default 7/4/12/3
  für daily/weekly/monthly/yearly. Tier-Picker füllt höhere Slots
  automatisch, wenn deren Bucket frei ist — ein nächtlicher Cron reicht
  also für alle Tiers.
- **Speicher-Budget** mit hartem MB-Limit. Manuelle Backups sind von
  der Storage-Eviction ausgenommen, damit „Backup vor riskanter
  Änderung" nicht weggeräumt wird.
- **Dashboard-UI** im Backup-Tab: Status-Banner mit Cron-Health-Warnung
  (Warnung, wenn Cron seit ≥2 Tagen nicht lief), Retention-Form,
  Cron-Setup-Hilfe mit Copy-Button und automatisch eingesetztem
  absoluten Site-Pfad, Backup-Liste mit Download/Restore/Delete pro
  Eintrag.
- **Helper** (`includes/backup-helper.php`) als gemeinsamer Code-Pfad
  für CLI und API — keine Logik-Duplizierung mehr.

Doku in CLAUDE.md unter „Site Backups". Strings in en.json + de.json.

**Aufgeschoben (späterer Schritt):** Off-Site-Upload-Hook (SFTP/S3)
für den Schutz vor Server-Totalverlust. Architektur ist so gebaut,
dass das ohne Refactor nachziehbar ist — `backupRun()` müsste nur
einen Post-Run-Hook bekommen, der die frische Datei wegspiegelt.
