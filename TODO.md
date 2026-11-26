# TODO

Abgeleitet aus [docs/PLAN.md](docs/PLAN.md) (P§ = Abschnitt im Plan). Erledigtes wandert nach `DONE.md`, Ideen und Zeitpunkte nach 1.0 stehen in [docs/BACKLOG.md](docs/BACKLOG.md).
Die Reihenfolge der Meilensteine ist verbindlich: M0 → M4 (M0 und M1 sind abgeschlossen, M2 ebenfalls, siehe [DONE.md](DONE.md)). Innerhalb eines Meilensteins ist die Listenreihenfolge die Ausführungsreihenfolge.
Task-IDs sind stabil. Jede Aufgabe liefert Tests, i18n (de/en), Dokumentation und Changelog-Eintrag (`pages/Changelog.md`, Unreleased) und erfüllt die Qualitäts-Gates (PHPStan Level 8, WPCS/PSR-12, >80 % Coverage je Sprache).

## M2 – Geodaten (P§16 Phase 2)


## M3 – Betrieb (P§16 Phase 3)

- [ ] M3-03: WP-CLI: `status`, `maps list`, `import`, `export`; Bestätigung/`--yes` bei destruktiven Befehlen, Exit-Codes, maschinenlesbare Ausgabe
- [ ] M3-04: Geocoding (nur Admin): Geocoder-Interface, Nominatim (nur per Aktion, kein Autocomplete, Identifikation, Rate-Begrenzung), selbst gehostete Instanz, Reverse-Geocoding, Bestätigung vor Übernahme
- [ ] M3-05: `POST /geocoding/search` capability-geschützt; Schlüssel nur aus `wp-config`, nie im Browser
- [ ] M3-06: SSRF-Schutz für serverseitige Requests: nur HTTP(S), Redirect-Prüfung, private Netze gesperrt, Admin-Allowlist; SSRF-Fixtures
- [ ] M3-07: Benchmarks 100 / 1.000 / 10.000 Punkte und große Tracks; Payload, Laufzeit, Speicher, Browser-Interaktion dokumentieren
- [ ] M3-08: Diagnose im Systemstatus (Provider-Erreichbarkeit, Limits, Schema)
- [ ] M3-09: Accessibility-Tests: axe über Admin-Routen und Frontend-Karte inkl. Standortliste (hell/dunkel), Tastaturbedienung
- [ ] M3-10: Security-Fixtures komplett: übergroße Geometrien, ungültige Koordinaten, Upload-/MIME-Prüfung, Pfadschutz
- [ ] M3-11: Lebenszyklus: Exporte vor Löschung, BackupIt-Kompatibilität der Tabellen prüfen, Deinstallationsverhalten testen

## M4 – Vorbereitung 1.0 (P§16 Phase 4)

- [ ] M4-01: Migration aus anderen Karten-Plugins: Zielplugins festlegen (z. B. Leaflet Map, WP Go Maps, MapPress), Importer, Importbericht, Tests mit Beispieldaten
- [ ] M4-02: Integrationshooks (`swmap_register_sources`, `swmap_map_published`, `swmap_layer_updated`, `swmap_import_completed`) dokumentieren, versionieren und mit Contract-Tests absichern
- [ ] M4-03: Dokumentation in `docs/` und `pages/`: Installation, Karteneditor, Provider, Datenschutz, Importformate, API, Entwicklung, Fehlerbehebung
- [ ] M4-04: Übersetzungen de/en vollständig und geprüft; Paritätstests grün
- [ ] M4-05: Hardening: Dependency-Audit, Lizenzhinweise (`THIRD-PARTY-NOTICES`), SonarQube-Findings triagieren
- [ ] M4-06: Vollständige E2E-Läufe auf dem Release-Kandidaten ohne Retries
- [ ] M4-07: Coverage-Nachweis >80 % (PHP und JS/TS getrennt), PHPStan Level 8 ohne Baseline
- [ ] M4-08: Release-ZIP prüfen (Struktur, Aktivierung, Multisite) und 1.0 freigeben

## Offen / separat zu klären

- [ ] X-01: Update-Mechanismus für GitHub-Releases konzipieren (eigener Prompt; Muster aus BackupIt/Gallery: signierte Manifeste, Ed25519) – bewusst nicht Teil dieses Plans
- [ ] X-02: Native-Speaker-Review der Übersetzungen
- [ ] X-03: Nach 1.0: Auswahl aus [docs/BACKLOG.md](docs/BACKLOG.md) (Google-Adapter, Routing, Standortportal, …) neu priorisieren
