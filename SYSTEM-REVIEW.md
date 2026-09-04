# Nibbly – Systemprüfung vom 4. September 2026

Nibbly hat eine überzeugende Grundlage für kleine bis mittlere Inhaltswebsites:
portable Dateien, geringe Betriebsanforderungen und eine klare Trennung zwischen
Website und CMS. Der größte Verbesserungsbedarf liegt bei der Verlässlichkeit
gemeinsam genutzter Zustände, der Größe der zentralen Dateien und der Absicherung
von Änderungen durch automatisierte Tests.

Die bestätigten Fehler aus dieser Prüfung sind im Code behoben und im
[Changelog](CHANGELOG.md) unter „Unreleased“ dokumentiert. Die zuvor offenen
Änderungen wurden zuerst als `7efed4d` auf `codex/system-audit` gesichert.

## Umfang und Aussagekraft

Untersucht wurden Login und Benutzerverwaltung, Berechtigungen und Sessions,
öffentliche Formulare, Inhaltsdarstellung, Nachrichten, Medien und Backups,
Routing und SEO, CLI, Analytics sowie KI-Gateway und Bildaufträge. Die Prüfung
verbindet Quellcodeanalyse mit isolierten HTTP-, Parallelitäts- und Browsertests.
Sie ist keine Zusicherung, dass jeder Ausführungspfad fehlerfrei ist.

Tests verwenden temporäre Installationen mit eigenen Testkonten. Lokale
Kundeninhalte und Zugangsdaten wurden dafür nicht kopiert. Es gab keine bezahlten
KI-Anfragen, echten Mailversand, Remote-Backup-Uploads oder Produktionsänderungen.

## Stärken

| Bereich | Stärke und Nutzen |
| --- | --- |
| Installation und Betrieb | PHP und Dateien reichen aus; Datenbank, Composer und Node sind für den Websitebetrieb nicht erforderlich. Umzug und Einsicht in Inhalte bleiben einfach. |
| Website-Anpassung | Eigene Templates, CSS und Erweiterungspunkte erlauben die Übernahme bestehender Gestaltung. Das CMS verlangt kein vollständiges Neuaufsetzen einer Website. |
| Inhaltsmodell | JSON als gemeinsame Quelle, verschachtelte Pfade und wiederverwendbare Editable-Helper verbinden freie Layouts mit strukturierten Inhalten. |
| Redaktion | Dashboard und visueller Editor nutzen dasselbe Inhaltsmodell. Nachrichten, Formulare, Medien, SEO und Mehrsprachigkeit sind bereits integriert. |
| KI-Architektur | Anbieterkommunikation erfolgt zentral auf dem Server. Der Copilot besitzt Berechtigungsprüfungen, signierte Vorschläge, Bestätigungsschritte, Audit-Einträge und Undo-Funktionen. |
| Sicherung | Lokale Vollsicherungen, Aufbewahrungsregeln, CLI/Cron und mehrere Remote-Ziele sind vorhanden. Ein Restore ist tatsächlich über die API getestet. |
| Weiterentwicklung | Gemeinsame Design-Tokens und neun Dashboard-Sprachen schaffen eine brauchbare Basis für konsistente Erweiterungen. |

## Bestätigte und behobene Fehler

P1 bezeichnet hier Sicherheits- oder Datenverlustrisiken, P2 Funktionsfehler und
P3 kleinere Darstellungs- oder Dokumentationsfehler.

| Priorität | Vorher | Korrektur / Nachweis |
| --- | --- | --- |
| P1 | Eine manipulierte alte Nachrichten-ID konnte außerhalb von `content/news` liegende JSON-Dateien adressieren. | ID-Validierung; HTTP-Test schützt die Benutzerdatei gegen `../users`. |
| P1 | Gelöschte, zurückgestufte oder mit neuem Passwort versehene Konten konnten alte Sessionrechte behalten. | Gemeinsame Sessionprüfung liest Konto und Rolle erneut; Passwort-Fingerabdruck widerruft alte Sitzungen. HTTP-Tests für alle drei Fälle. |
| P1 | Passwort-Reset und Benutzeränderungen bestanden aus getrennten Lese-/Schreibschritten. | Atomare Kontoänderungen und Tokenverbrauch; Paralleltests für Passwortänderung neben Login, einmaligen Reset und letzten Administrator. |
| P1 | Kodierte Script-URLs und aktives HTML konnten die einfache Rich-Text-Filterung umgehen. | DOM-basierte Positivliste für Elemente, Attribute und Textstile; Filterung auch beim Rendern vorhandener Nachrichten. Branding wird in HTML-Attributen escaped. |
| P1 | Parallele Formularaufrufe konnten Tokens und Nachrichten verlieren oder Limits gleichzeitig passieren. | Transaktionen und Reservierungen; 24 parallele Prozesse, genau eine Tokenannahme und maximal drei zugelassene Formularanfragen. |
| P1 | Content-Restore löschte vorhandene Seiten vor dem erfolgreichen Auslesen aller Dateien; Schreibfehler konnten trotzdem Erfolg melden. | Vollständiges Staging mit Größen-, CRC- und JSON-Prüfung, Zielprüfung und Rücknahme gewöhnlicher Schreibfehler. Fehler am Ziel lassen neue Inhalte erhalten. |
| P1 | Die HTTP-Sperre für Papierkörbe traf echte `images-trash`-Pfade nicht zuverlässig. | Schutzregeln für Papierkörbe, versteckte Dateien, Tests und CLI in Apache und Entwicklungsrouter geprüft. |
| P2 | Zwei Bild-Worker konnten denselben kostenpflichtigen Auftrag starten. | Atomare Übernahme eines Auftrags; unter 24 konkurrierenden Prozessen erhält genau einer den Auftrag. |
| P2 | KI-Verbrauch, Bildhistorie und Seitenaufrufe konnten bei parallelen Schreibzugriffen Einträge verlieren. | Gemeinsame JSON-Transaktionen und atomare Dateiersetzung; Paralleltests erhalten alle 24 Einträge/Zähler. |
| P2 | Ein Formular ohne erfolgreiche Zustellung und ohne lokale Speicherung meldete Erfolg. | Fehlerantwort mit HTTP 503; lokal gespeicherte Nachrichten und fehlgeschlagener Versand werden unterschieden. |
| P2 | Nachrichten konnten bei Slug-Kollision überschrieben oder bei fehlgeschlagenem Umbenennen gelöscht werden. | Kollisionsprüfung und Speichern vor Löschen; HTTP-Tests erhalten die ursprüngliche Nachricht. |
| P2 | Kie-Streaming nutzte einen falschen Endpunkt, und GPT-Anfragen verloren das konfigurierte Ausgabelimit. | Ein gepufferter Aufruf über den passenden Adapter; GPT-Ausgabelimit wird übermittelt. Lokaler Mock prüft GPT, Claude und Gemini. |
| P2 | Kie ignorierte das gewünschte Hoch-/Querformat; Kürzung konnte UTF-8-Prompts und Historie beschädigen. | Seitenverhältnis aus Bildmaßen und Unicode-sichere Längengrenzen; Tests mit Umlauten, Eurozeichen und ungültigem UTF-8. |
| P2 | Restore übersprang unterstützte Videos und Dokumente. Zwei Backups in derselben Sekunde konnten sich ersetzen. | Erweiterte Dateitypen, eindeutige Dateinamen und geprüfter Sicherungsabschluss; Roundtrip für MP4, PDF, XLSX und FLAC. |
| P2 | Das Entfernen freigegebener Lockdateien konnte wartende Prozesse auf unterschiedliche Locks verteilen. | Lockdateien behalten ihren Dateisystemeintrag und werden aus Backups ausgeschlossen. |
| P2 | Sprach-Startseiten und Unterseiten mit abschließendem Slash konnten falsch geroutet werden oder CSS falsch auflösen. | Gemeinsamer Frontcontroller und korrekte relative Pfade; HTTP- und Apache-Tests. |
| P2 | Eine geänderte private Seiten-Passphrase widerrief zuvor erteilte Freigaben nicht. | Freigaben werden an den aktuellen Passwort-Hash gebunden. |
| P2 | CLI-Werkzeuge verlangten den Entwicklungsrouter, obwohl dieser in produktiven Kopien fehlt. | Prüfung des produktiven Frontcontrollers; Test ohne `router.php`. |
| P3 | Die mobile Admin-Leiste überlagerte den Seitenkopf; niedrige Statistikwerte erzeugten doppelte Achsenbeschriftungen. | Gemessene Leistenhöhe und ganzzahlige Diagrammintervalle; Chromium prüft beide Fälle. |
| P3 | Dokumentation versprach PHP 7.4, obwohl der Code PHP-8-Funktionen bis einschließlich `array_is_list()` nutzt. Veraltete Tests erwarteten eine frühere Akzentfarbe. | Dokumentiertes Sprachminimum PHP 8.1, Empfehlung einer unterstützten Version, aktualisierte Tests. Unnötige unter PHP 8.5 veraltete Ressourcenaufrufe entfernt. |

Nach Installation dieser Änderungen ist einmaliges erneutes Anmelden erforderlich.
Bestehende Konten und Passwörter bleiben erhalten. Rich Text erlaubt bewusst nur
die dokumentierten Inhaltselemente und Textstile; ausführbare oder freie Layout-
Attribute gehören in Website-Templates.

## Schwächen und Änderungsvorschläge

### 1. Seiten und Einstellungen gegen Bearbeitungskonflikte schützen

**Priorität: hoch. Aufwand: mittel.** Die jetzt eingeführten Transaktionen schützen
die häufig gemeinsam beschriebenen Register. Vollständige Seiten- und
Einstellungssnapshots haben weiterhin keine durchgehende Versionsprüfung.
`lastModified` dient hauptsächlich als Metadatum; zwei geöffnete Editoren können
auf unterschiedlichen Ausgangsständen arbeiten.

Vorschlag: Beim Laden eine Revision bzw. einen Inhaltshash zurückgeben, beim
Speichern vergleichen und bei Abweichung HTTP 409 liefern. Der Editor zeigt
„Inzwischen geändert“ mit Vergleich und Neuladen. Gemeinsam verwendete Speicher-
operationen schrittweise auf `json-store.php` umstellen, einschließlich automatischer
Fallback-Ergänzungen. Die JSON-Dateien bleiben dabei das Speicherformat.

### 2. API und Dashboard in fachliche Module zerlegen

**Priorität: hoch. Aufwand: mittel bis groß, in kleinen Schritten.**
`admin/dashboard.php` enthält rund 14.200 Zeilen, `admin/api.php` rund 7.650;
PHP, HTML, JavaScript, Berechtigungen und Fachlogik liegen eng beieinander.
Das erschwert die Prüfung einzelner Änderungen und begünstigt doppelte Regeln.

Vorschlag: Zuerst Benutzerverwaltung, Backup und Medien als getrennte Handler
mit einheitlichen Eingabe-, Fehler- und Berechtigungsverträgen extrahieren.
Dashboard-JavaScript danach nach denselben Bereichen aufteilen. Bestehende
URLs, JSON-Strukturen und Website-Erweiterungspunkte beibehalten. Ein Wechsel
des Frameworks oder eine Datenbankmigration ist dafür nicht nötig.

### 3. Die neue Testsuite vor jedem Merge automatisch ausführen

**Priorität: hoch. Aufwand: klein bis mittel.** Ein wiederholbarer lokaler Runner
ist jetzt vorhanden. Im geprüften Repository existiert noch kein CI-Workflow.
Einige ältere Tests suchen lediglich bestimmte Zeichenketten im Quelltext;
sie sind als Schutz vor Funktionsverlust nützlich, ersetzen aber keine Ausführung.

Vorschlag: Pull Requests auf PHP 8.4 und 8.5 prüfen, HTTP- und Paralleltests als
Pflichtchecks ausführen und einen kleinen Chromium-Lauf hinzufügen. Danach
zusätzliche Abläufe für Upload, Seitenbearbeitung, Setup und SMTP ergänzen.
Die Branch-Protection sollte erfolgreiche Tests vor dem Merge verlangen.

### 4. KI-Budget und Aufträge als reservierbaren Zustand behandeln

**Priorität: mittel bis hoch. Aufwand: mittel.** Das Gateway prüft Kosten vor
dem Aufruf anhand konfigurierter Schätzpreise und verbucht den Verbrauch danach.
Auch mit einem korrekten Verbrauchszähler ist diese Prüfung keine harte
anbieterseitige Kostenbegrenzung. Gleichzeitige unterschiedliche Anfragen haben
keine gemeinsame Budgetreservierung. Kie-Aufträge können beim Anbieter weiterlaufen,
wenn der lokale Request abbricht.

Vorschlag: Anfrage-ID, Budgetreservierung, Anbieter-Task-ID und Abschlussstatus
persistieren. Polling nach Neustart fortsetzen und Reservierungen nachvollziehbar
auflösen. In der Oberfläche Schätzung, lokale Grenze und tatsächlichen
Anbieterverbrauch klar benennen. Modelllisten und Fähigkeiten an einer Stelle
pflegen und mit Adaptertests absichern.

### 5. Analytics nach Zeiträumen aufteilen

**Priorität: mittel. Aufwand: mittel.** Jeder Seitenaufruf verarbeitet derzeit
das gemeinsame Analytics-Dokument. Mit wachsender Historie steigen Lese-,
Schreib- und Sperrkosten. Der Paralleltest prüft korrekte Zähler, ist jedoch
kein Lasttest für große Websites.

Vorschlag: Aktuelle Tagesdaten separat speichern, ältere Zeiträume aggregieren
und historische Zusammenfassungen zwischenspeichern. Vor einer größeren
Umstellung Dateigröße, Antwortzeiten und gleichzeitige Anfragen messen.

### 6. Sprache, Leerzustände und Wartungsinformationen vereinheitlichen

**Priorität: mittel. Aufwand: klein bis mittel.** Dashboard, SEO-Hinweise und
Editor enthalten noch gemischte deutsche/englische und technische Meldungen.
Die vorhandenen neun Sprachdateien werden nicht von allen Oberflächen gleich
konsequent genutzt. Ein Diagramm mit null Aufrufen erklärt beispielsweise nicht,
ob Analytics deaktiviert ist oder noch keine Daten vorliegen.

Vorschlag: Alle sichtbaren Meldungen durch dieselbe Übersetzungsschicht führen,
technische Details nur bei Bedarf einblenden und „deaktiviert“, „noch keine Daten“
und „fehlgeschlagen“ als unterschiedliche Zustände gestalten. Ergänzend eine
Systemstatus-Seite für PHP-Erweiterungen, Schreibrechte, letzte erfolgreiche
Sicherung und fehlgeschlagene Hintergrundaufträge anbieten.

## Prüfung und verbleibende Grenzen

- `python3 tests/run-smoke.py`: 13 Offline-Suites; Syntaxprüfung aller kopierten
  PHP- und JavaScript-Dateien sowie Translation-/Fixture-JSON. Enthält die acht
  bisherigen Suiten und fünf neue Suiten für Sicherheit, HTTP, Speicherung,
  Benutzer und KI-Anbieter.
- `python3 tests/browser-check.py`: optionaler Playwright-/Chromium-Test für
  Login, acht Dashboard-Bereiche, deaktivierte KI, Diagrammskala, verschachtelte
  Seite, mobilen Admin-Abstand und Besucheransicht; keine JavaScript-Ausnahmen.
- Separater lokaler Apache-2.4-/PHP-FPM-Test für Startseite, Sprach- und Unterseiten,
  Zugriffssperren, Anmeldung und authentifizierte API.
- Lokale Laufzeit: PHP 8.5.4 und Node 22.17.1. Ältere PHP-Versionen wurden nicht
  ausgeführt. Für produktive Installationen sollte eine
  [weiterhin unterstützte PHP-Version](https://www.php.net/supported-versions.php)
  eingesetzt werden.
- Kie wurde gegen einen lokalen Mock geprüft. Reale Antworten, Preise,
  Bilddatei-Downloads und sämtliche Anbieterfunktionen sind damit nicht zertifiziert.
  Die Routen orientieren sich an der offiziellen
  [Kie-Chat-Dokumentation](https://docs.kie.ai/market/chat/gpt-5-6-luna) und
  [Kie-Bild-Dokumentation](https://docs.kie.ai/market/gpt/gpt-image-2-text-to-image).
- SMTP, Remote-Speicher, OAuth-Verbindungen, andere Browser und ein vollständiger
  Setup-/Upgrade-Durchlauf auf unterschiedlichen Hostingplattformen wurden nicht
  live geprüft. Die vorhandene Website wurde nicht neu gestaltet.
- Ein Restore kann normale I/O-Fehler zurücknehmen. Prozessabbruch, Stromausfall
  oder gleichzeitig laufende andere Schreibvorgänge sind keine atomare
  Gesamttransaktion der Website. Vollrestores erzeugen vorher eine Sicherung;
  größere Installationen sollten zusätzlich eine Wartungsphase und geübte
  Wiederanlaufprozedur vorsehen.

Empfohlene Reihenfolge: zuerst CI und Konflikterkennung für Inhalte, anschließend
die fachliche Aufteilung von API/Dashboard, danach KI-Auftragsverwaltung und
Analytics-Speicher. Das stärkt die vorhandene Architektur, ohne ihren einfachen
Betrieb aufzugeben.
