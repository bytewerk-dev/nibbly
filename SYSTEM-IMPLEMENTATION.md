# Umsetzung der Systemprüfung

Beauftragt: alle sechs Vorschläge aus `SYSTEM-REVIEW.md`, zusätzlich responsive
Dashboard-Formulare mit besonderem Augenmerk auf Backup.

- [x] API-Handler und Dashboard-Code fachlich aufteilen, URLs und Datenformat behalten.
- [x] Seiten/Einstellungen mit Revisionen, HTTP 409 und Vergleich/Neuladen schützen;
      automatische Fallbacks transaktional ergänzen.
- [ ] CI für PHP 8.4/8.5 und Chromium, zusätzliche Integrationsabläufe,
      erfolgreiche Prüfungen im bestehenden Main-Regelwerk verlangen.
- [x] KI-Reservierungen, dauerhafte Anbieter-Aufträge, Wiederaufnahme,
      zentraler Modell-/Fähigkeitenkatalog und klare Budgetanzeige.
- [x] Analytics in Tagesdateien und historische Zusammenfassungen aufteilen,
      vorhandene Daten erhalten und Zugriffskosten prüfen.
- [x] Sprache und Leer-/Fehlerzustände vereinheitlichen, Systemstatus ergänzen.
- [x] Dashboard einschließlich umfangreicher Backup-Formulare bei 360, 390,
      768, 1024 und 1440 Pixeln prüfen und korrigieren.
- [ ] Gesamttests, Changelog, aktualisierter Bericht und Commit/PR.

Arbeitsweise: Core-Entwicklung auf `codex/system-audit`, isolierte Testinstallationen,
keine bezahlten Anbieteraufrufe oder echten Mail-/Cloud-Transfers. Vorhandene
Produktionsdateien und Zugangsdaten bleiben außerhalb der Testfixtures.


Lokal nachgewiesen: 18 Offline-Suites, Zwei-Tab-Konflikttest und 120 responsive
Ansichten. Der Kie-Mock nimmt genau einen Anbieterauftrag an; nach einem
Polling-Fehler wird dessen ID in einem neuen PHP-Prozess weiterverwendet.
Bei 24 gleichzeitigen Reservierungen und einem Budget von drei Cent werden
exakt drei Anfragen zugelassen. Eine 730-Tage-Statistik mit 185.430 Byte benötigt
für den aktuellen Schreibzugriff nur eine Tagesdatei mit 8.501 Byte.

Noch abzuschließen: letzter Browserlauf mit eingeblendeten SMTP-Feldern,
GitHub-CI, Pflichtchecks im vorhandenen Main-Regelwerk sowie Commit/PR.
