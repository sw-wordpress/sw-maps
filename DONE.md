
- [x] M3-11: Lebenszyklus: Exporte vor Löschung, BackupIt-Kompatibilität der Tabellen prüfen, Deinstallationsverhalten testen
  - Ergebnis: Löschwarnung und Export-Hinweis in den Einstellungen, Export aller Karten (UI und CLI) vor dem Löschen; BackupIt-Kompatibilität geprüft (Präfix-Auswahl, InnoDB, keine eigenen Dateien, Optionen `swmap_*`) und in `docs/lifecycle.md` dokumentiert; Aktivierungs-/Deinstallationstests erweitert (`LifecycleTest`).
