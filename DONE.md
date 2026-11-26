
- [x] M3-08: Diagnose im Systemstatus (Provider-Erreichbarkeit, Limits, Schema)
  - Ergebnis: `/status` liefert `schema_ok` und `limits` (Standorte je Layer, Importgrenzen, Seitengröße, PHP-Speicher/Laufzeit/POST-Größe); `POST /status/providers` prüft die Erreichbarkeit aller aktivierten Anbieter nur auf Anforderung (`ProviderDiagnostics`, gleicher geschützter Client wie der Verbindungstest). Anzeige in der Statusseite und in `wp sw-maps status`.
