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

## Umsetzung der sechs Änderungsvorschläge

Die sechs Vorschläge wurden im anschließenden Core-Durchlauf umgesetzt:

| Bereich | Neuer Stand | Nachweis |
| --- | --- | --- |
| Bearbeitungskonflikte | Revision je Seite/Einstellung, HTTP 409/428, Vergleich und Download eigener Änderungen; atomare Fallback-Ergänzung. | Zwei unabhängige HTTP-Clients, zwei Browser-Tabs und 24 parallele Fallbacks. |
| Fachliche Module | Zwölf API-Handler und 17 Dashboard-Scriptfragmente; stabile URLs und gemeinsame Methoden-/CSRF-/Rollenregeln. | Bestehende Integrations- und Copilot-Suites, direkte Browserausführung. |
| CI | Workflow für PHP 8.4/8.5 und Chromium; Setup-, SMTP-, Upload- und Seitenabläufe ergänzt. Alle drei Checks sind für `main` verpflichtend. | 18 Offline-Suites auch mit beiden PHP-Versionen in GitHub erfolgreich; Chromium einschließlich 120 responsiver Ansichten und Zwei-Tab-Konflikt erfolgreich. |
| KI-Zustand | Gemeinsame Reservierung und Verbrauchsbuchung, persistente Kie-IDs, Wiederaufnahme, Systemstatus-Abgleich und gemeinsamer Modellkatalog. | 24 konkurrierende Reservierungen lassen exakt drei Anfragen bei drei Cent Budget zu; ein Kie-Auftrag überlebt einen Prozesswechsel ohne erneute Einreichung. |
| Analytics | Tagesdateien, historische Monatsarchive ohne Besucher-Hashes und kurzlebiger Abfragecache; automatische Migration. | 730 Tage behalten 5.110 Aufrufe; täglicher Schreibzugriff auf 8.501 statt 185.430 Byte. |
| Wartung und Sprache | Systemstatus für Erweiterungen, Speicher, Sicherungen, Aufträge; Analytics-Zustände „deaktiviert“, „leer“, „Fehler“; SEO-/Dialogtexte über i18n. | Browser- und HTTP-Prüfungen. Neue ausführliche Texte auf Deutsch und Englisch; andere Sprachen verwenden den vorhandenen englischen Fallback. |

Zusätzlich wurden Dashboard und alle Einstellungsbereiche bei 360, 390, 768,
1024 und 1440 Pixeln geprüft: 120 Ansichten einschließlich aktivierter KI und
umfangreicher Backup-Felder. Die Formulare nutzen die verfügbare Panelbreite.
Eine kompakte Auswahl ersetzt auf schmalen Displays die lange Einstellungs-
navigation. Farbwerte, Aktionen, Remote-Ziele, lange Menünamen und Uploads bleiben bedienbar.
Die neue Konfliktansicht wurde ebenfalls bei 360 Pixeln geprüft.

Bei der Umsetzung bestätigte weitere Fehler sind behoben: Teiländerungen an
Einstellungen setzten unbeteiligte Gruppen auf Standardwerte zurück; versteckte
Selects vergrößerten unsichtbar die Seite; Setup hatte keinen CSRF-Schutz;
unklare KI-Antworten konnten automatisch eine zweite kostenpflichtige Anfrage
verursachen. Seitenbackups unterstützen eindeutige Dateinamen einschließlich
Vorschau und Wiederherstellung.

Die Architektur bleibt bewusst dateibasiert. Die Modulaufteilung führt kein
Framework ein; die Scriptfragmente teilen vorerst weiterhin denselben JavaScript-
Gültigkeitsbereich. KI-Grenzen bleiben lokale Schätzgrenzen. Unklare Einreichungen
ohne Anbieter-ID werden nicht automatisch erneut gestartet. Ob ein Anbieter
wirklich Geld berechnet hat, muss dort geprüft werden.

## Prüfung und verbleibende Grenzen

- `python3 tests/run-smoke.py`: 18 Offline-Suites; Syntaxprüfung aller kopierten
  PHP- und JavaScript-Dateien sowie Translation-/Fixture-JSON. Enthält zusätzlich Revisionen, KI-Reservierung/Wiederaufnahme, Analytics-Migration sowie Setup-, Medien- und SMTP-Abläufe.
- `python3 tests/browser-check.py`: optionaler Playwright-/Chromium-Test für
  Login, acht Dashboard-Bereiche, deaktivierte KI, Diagrammskala, verschachtelte
  Seite, mobilen Admin-Abstand und Besucheransicht; keine JavaScript-Ausnahmen.
- Separater lokaler Apache-2.4-/PHP-FPM-Test für Startseite, Sprach- und Unterseiten,
  Zugriffssperren, Anmeldung und authentifizierte API.
- Lokale Laufzeit: PHP 8.5.4 und Node 22.17.1; zusätzlich PHP 8.4 und PHP 8.5
  auf Ubuntu 24.04 in GitHub CI erfolgreich geprüft. PHP 8.1–8.3 wurden nicht
  ausgeführt. Für produktive Installationen sollte eine
  [weiterhin unterstützte PHP-Version](https://www.php.net/supported-versions.php)
  eingesetzt werden.
- Kie wurde gegen einen lokalen Mock geprüft. Reale Antworten, Preise,
  Bilddatei-Downloads und sämtliche Anbieterfunktionen sind damit nicht zertifiziert.
  Die Routen orientieren sich an der offiziellen
  [Kie-Chat-Dokumentation](https://docs.kie.ai/market/chat/gpt-5-6-luna) und
  [Kie-Bild-Dokumentation](https://docs.kie.ai/market/gpt/gpt-image-2-text-to-image).
- SMTP wurde lokal gegen einen Mock, Setup in einer frischen Testkopie geprüft.
  Reale Mailserver, Remote-Speicher, OAuth-Verbindungen, andere Browser und
  unterschiedliche Hostingplattformen wurden nicht live geprüft. Die vorhandene Website wurde nicht neu gestaltet.
- Ein Restore kann normale I/O-Fehler zurücknehmen. Prozessabbruch, Stromausfall
  oder gleichzeitig laufende andere Schreibvorgänge sind keine atomare
  Gesamttransaktion der Website. Vollrestores erzeugen vorher eine Sicherung;
  größere Installationen sollten zusätzlich eine Wartungsphase und geübte
  Wiederanlaufprozedur vorsehen.

Details zur technischen Umsetzung stehen in [architecture.md](architecture.md),
der Arbeits- und CI-Stand in [SYSTEM-IMPLEMENTATION.md](SYSTEM-IMPLEMENTATION.md).
