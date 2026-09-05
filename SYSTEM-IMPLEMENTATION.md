# Umsetzung der Systemprüfung

Beauftragt: alle sechs Vorschläge aus `SYSTEM-REVIEW.md`, zusätzlich responsive
Dashboard-Formulare mit besonderem Augenmerk auf Backup.

- [x] API-Handler und Dashboard-Code fachlich aufteilen, URLs und Datenformat behalten.
- [x] Seiten/Einstellungen mit Revisionen, HTTP 409 und Vergleich/Neuladen schützen;
      automatische Fallbacks transaktional ergänzen.
- [x] CI für PHP 8.4/8.5 und Chromium, zusätzliche Integrationsabläufe,
      erfolgreiche Prüfungen im bestehenden Main-Regelwerk verlangen.
- [x] KI-Reservierungen, dauerhafte Anbieter-Aufträge, Wiederaufnahme,
      zentraler Modell-/Fähigkeitenkatalog und klare Budgetanzeige.
- [x] Analytics in Tagesdateien und historische Zusammenfassungen aufteilen,
      vorhandene Daten erhalten und Zugriffskosten prüfen.
- [x] Sprache und Leer-/Fehlerzustände vereinheitlichen, Systemstatus ergänzen.
- [x] Dashboard einschließlich umfangreicher Backup-Formulare bei 360, 390,
      768, 1024 und 1440 Pixeln prüfen und korrigieren.
- [x] Gesamttests, Changelog, aktualisierter Bericht und Commit/PR.

Arbeitsweise: Core-Entwicklung auf `codex/system-audit`, isolierte Testinstallationen,
keine bezahlten Anbieteraufrufe oder echten Mail-/Cloud-Transfers. Vorhandene
Produktionsdateien und Zugangsdaten bleiben außerhalb der Testfixtures.


Lokal nachgewiesen: 18 Offline-Suites, Zwei-Tab-Konflikttest und 120 responsive
Ansichten. Der Kie-Mock nimmt genau einen Anbieterauftrag an; nach einem
Polling-Fehler wird dessen ID in einem neuen PHP-Prozess weiterverwendet.
Bei 24 gleichzeitigen Reservierungen und einem Budget von drei Cent werden
exakt drei Anfragen zugelassen. Eine 730-Tage-Statistik mit 185.430 Byte benötigt
für den aktuellen Schreibzugriff nur eine Tagesdatei mit 8.501 Byte.

Abgeschlossen am 4. September 2026: 120 responsive Ansichten einschließlich
eingeblendeter SMTP-Felder und langer Menünamen, zusätzlich visueller Vergleich
der Backup-Ansichten auf Smartphone und Tablet. Der Linux-Lauf deckte einen
weiteren Überlauf der Menüsortierung auf; Beschriftung und Pfad stehen in schmalen
Panels jetzt untereinander, die Sortieraktionen bleiben daneben erreichbar.

Die Änderungen sind auf `codex/system-audit` committet und gepusht:
`913b13b` enthält die sechs Umsetzungspakete, `b46a577` die abschließende
Layoutkorrektur. [PR #40](https://github.com/bytewerk-dev/nibbly/pull/40) ist als
Entwurf geöffnet und nicht in `main` zusammengeführt.

[GitHub-CI-Lauf 33923008987](https://github.com/bytewerk-dev/nibbly/actions/runs/33923008987)
prüft `b46a577` erfolgreich mit PHP 8.4, PHP 8.5 und Chromium auf Ubuntu 24.04.
Die Browser-Screenshots und Layoutprotokolle werden sieben Tage als Artefakt
aufbewahrt. Das bestehende aktive
[Main-Regelwerk](https://github.com/bytewerk-dev/nibbly/rules/14480439) verlangt
alle drei GitHub-Actions-Prüfungen und einen aktuellen Branch vor dem Merge.
Der vorhandene PR-, Lösch- und Force-Push-Schutz bleibt erhalten.
