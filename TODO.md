# TODO

Abgeleitet aus [docs/PLAN.md](docs/PLAN.md) (P§ = Abschnitt im Plan). Erledigtes wandert nach `DONE.md`, Ideen und Zeitpunkte nach 1.0 stehen in [docs/BACKLOG.md](docs/BACKLOG.md).
Die Reihenfolge der Meilensteine ist verbindlich: M0 → M4. Innerhalb eines Meilensteins ist die Listenreihenfolge die Ausführungsreihenfolge.
Task-IDs sind stabil. Jede Aufgabe liefert Tests, i18n (de/en), Dokumentation und Changelog-Eintrag (`pages/Changelog.md`, Unreleased) und erfüllt die Qualitäts-Gates (PHPStan Level 8, WPCS/PSR-12, >80 % Coverage je Sprache).

## M0 – Foundation (P§16 Phase 0)

### Vorarbeiten und Entscheidungen
- [ ] M0-01: BackupIt prüfen: `AGENTS.md`, `CODE-STYLE.md`, `composer.json`, `apps/admin/package.json`, `Makefile`, CI-Workflows, Build- und i18n-Skripte (P§2)
- [ ] M0-02: WordPress-Mindestversion und Dependency-Versionen mit BackupIt abgleichen; Supportmatrix MySQL/MariaDB übernehmen
- [ ] M0-03: OpenLayers-Version und PMTiles-Anbindung auf Lizenz (GPL-kompatibel), Wartung und Bundlegröße prüfen (P§18)
- [ ] M0-04: Datenbankschema final dokumentieren: SQL-Typen, Indizes, Löschregeln, Migrationen (P§7)
- [ ] M0-05: Kurze ADRs in `docs/` anlegen: eigene Tabellen, ein Renderer bis 1.0, synchroner Import, Secrets in `wp-config`
- [ ] M0-06: REST-Verträge dokumentieren: Argumente, Pagination, maximale Seitengröße, Fehlercodes, Antwortschemata (P§9)
- [ ] M0-07: PHP-API (`swmap_render_map`, `swmap_register_source`) und Hook-Verträge finalisieren (P§9)

### Repository und Tooling
- [ ] M0-08: Repository-Struktur laut P§3 anlegen (`apps/admin`, `packages/*`, `bin`, `docs`, `pages`, `languages`, `tests/*`, `e2e`)
- [ ] M0-09: `AGENTS.md`, `CODE-STYLE.md`, `TODO.md`, `DONE.md`, `README.md` nach Referenzstruktur anlegen; Regel „Texte in der Oberfläche“ (P§6.1) in `AGENTS.md` übernehmen
- [ ] M0-10: Composer-Projekt: PSR-4 (`SWMaps`), strict_types, PHP >= 8.2, Mozart-Prefixing wie BackupIt
- [ ] M0-11: PHPCS (PSR-12 im Core, WPCS im WP-Adapter) und PHPStan Level 8 ohne Baseline einrichten
- [ ] M0-12: Plugin-Bootstrap `sw-maps.php`, `uninstall.php` (Löschen nur bei gesetzter Option), Namenskonventionen (P§2.1)
- [ ] M0-13: Admin-App: Vue 3, Quasar 2 (ohne Quasar CLI), TypeScript, Vite, Pinia, Vue Router, Vue I18n; ESLint, Prettier, vue-tsc
- [ ] M0-14: `packages/map-contracts` (TypeScript-Datenverträge) und `packages/renderer-openlayers` als leeres Gerüst mit separatem Vite-Build inkl. CSS
- [ ] M0-15: Makefile (`build`, `dist`, `release`), reproduzierbarer Build mit Lockfiles, Release-ZIP ohne Node/Composer auf dem Zielhosting
- [ ] M0-16: i18n-Infrastruktur: gettext in `languages/`, SPA-Kataloge in `apps/admin/src/i18n/`, `bin/i18n.sh`, automatische Paritätsprüfung (de/en)

### Testumgebungen und CI (P§14.1)
- [ ] M0-17: PHPUnit 11 (Unit/Integration) mit Coverage-Check-Skript (>80 %, PHP und JS/TS getrennt)
- [ ] M0-18: `tests/wp/` als eigenes Composer-Projekt (wp-phpunit, WordPress-Core), Einzelsite und Multisite
- [ ] M0-19: `bin/test-db.sh` (Wegwerf-MariaDB) und MariaDB-Service in CI
- [ ] M0-20: Vitest mit hartem Coverage-Skript (>80 %)
- [ ] M0-21: `tests/e2e-env/` (Docker Compose) und `bin/test-e2e.sh`; Playwright in `e2e/` mit 3 Workern, 2 Retries und Mock-Modus ohne WordPress
- [ ] M0-22: GitHub Actions: Tests auf Pull Requests, Dependency-Audits, Lizenzhinweise, SonarQube
- [ ] M0-23: CI-Messung der komprimierten Bundlegrößen mit Budget (P§18)

### Schema und Prototyp
- [ ] M0-24: Schema-Migrationen (versioniert, multisite-fähig) für `swmap_maps`, `swmap_layers`, `swmap_features`, `swmap_categories`, `swmap_feature_categories`, `swmap_providers`; Migrationstests
- [ ] M0-25: Renderer-Vertrag (mount, update, setView, getView, fitBounds, on, destroy) in `map-contracts`
- [ ] M0-26: OpenLayers-Prototyp: Viewer und einfacher Editor (Marker setzen, Linie/Polygon zeichnen) in der Admin-App

## M1 – MVP (P§16 Phase 1, P§17)

### Backend und Datenhaltung
- [ ] M1-01: Core-Domain und Geo: `Map`, `Layer`, `Feature`, `Geometry`, `Coordinate`, `Bounds`, `GeometryValidator` (nur ±180° / Mercator-Grenzen, ungültige Koordinaten ablehnen)
- [ ] M1-02: wpdb-Repositories für Karten, Layer, Features (alle SQL über `prepare()`), Löschregeln
- [ ] M1-03: Application Services (`MapService`, `LayerService`, `FeatureService`); keine Logik in Controllern
- [ ] M1-04: Capabilities getrennt für Bearbeiten, Veröffentlichen, Layer, Importe, Provider/System; Zuordnung bei Aktivierung dokumentieren (P§12)
- [ ] M1-05: REST `sw-maps/v1`: `/maps`, `/maps/{id}`, `/maps/{id}/layers`, `/layers/{id}`, `/layers/{id}/features`, `/features/{id}` mit `permission_callback` und objektbezogener Prüfung
- [ ] M1-06: Provider-Konfiguration: OSM-Raster vorkonfiguriert und aktiv, weitere XYZ-Provider, `secret_constant` (Name einer `wp-config`-Konstante), Attribution (P§4.5)
- [ ] M1-07: Provider-Endpunkte inkl. Verbindungstest; Anzeige der externen Requests vor Aktivierung

### Einbindung und Frontend
- [ ] M1-08: Shortcode `[sw_map id=""]` und PHP-API `swmap_render_map()`
- [ ] M1-09: Dynamischer Gutenberg-Block `sw-maps/map` mit Kartenauswahl und Vorschau im Editor
- [ ] M1-10: Serverseitiges Rendering: HTML-Container mit sicher kodiertem Inline-JSON; Standortliste als serverseitiges HTML (P§5.1)
- [ ] M1-11: OpenLayers-Viewer: Marker, Popups, Zoom/Vollbild/Maßstab, Attribution dauerhaft sichtbar auch im Vollbild
- [ ] M1-12: Popups nur aus strukturierten Feldern mit serverseitig bereinigtem Inhalt; XSS-Tests inkl. eingebettetem JSON
- [ ] M1-13: Mehrere unabhängige Karten pro Seite, Assets nur auf Seiten mit Karten, ein gemeinsames Bundle, Fehlerisolation
- [ ] M1-14: Lazy Initialization (IntersectionObserver), verständliche Fehlermeldung und Fallback bei Kartenfehlern
- [ ] M1-15: Entwürfe nur mit Berechtigung in der Vorschau; nicht veröffentlichte Inhalte nie öffentlich

### Datenschutz und Barrierefreiheit
- [ ] M1-16: WP Consent API: Tile-Requests erst nach Zustimmung; Kompatibilitätstest mit gängigen Consent-Plugins
- [ ] M1-17: Optional Click-to-load mit lokalem Platzhalter; Standortliste bleibt nutzbar
- [ ] M1-18: Datenschutz-Platzhalter und Privacy-Policy-Text (`wp_add_privacy_policy_content`)
- [ ] M1-19: Standortliste als verbindlicher barrierearmer Zugang, mit der Karte synchronisiert; Tastatur, Fokus, reduzierte Bewegung, mobile-first

### Admin-Oberfläche
- [ ] M1-20: Ansichten: Dashboard, Karten (Liste, Duplizieren, Einbindungscode), Anbieter, Einstellungen (Defaults, Berechtigungen, Limits, Deinstallationsverhalten), Systemstatus (DB-Schema, Umgebung)
- [ ] M1-21: Karteneditor: Layer-/Feature-Leiste, Karte, Eigenschaftsleiste; Marker per Klick, Drag-and-drop und Koordinateneingabe
- [ ] M1-22: Startansicht (Mittelpunkt/Zoom, Auto-Bounds), Zoomgrenzen, Höhe/Seitenverhältnis, Scroll-Zoom/Touch/Drag getrennt konfigurierbar, Vorschau Desktop/Mobil
- [ ] M1-23: Explizites Speichern, Warnung bei ungespeicherten Änderungen, Undo/Redo für lokale Änderungen (kein Locking, letzter Speichervorgang gilt)
- [ ] M1-24: Titel, Kurzbeschreibung, Adresse, Bild, Link, Icons (lokal und sichere Rasterbilder aus der Mediathek) für Marker
- [ ] M1-25: Texte nach der Regel aus P§6.1 prüfen (de/en)

### MVP-Abnahme
- [ ] M1-26: Playwright: Karte erstellen, Marker verschieben, veröffentlichen, per Gutenberg und Shortcode einbinden, mehrere Karten, Mobilansicht, Tastatur
- [ ] M1-27: Netzwerktests: keine externen Requests vor Consent, Seite ohne Karte lädt kein Bundle, mit gemockten Tiles (CI nie von öffentlichen OSM-Diensten abhängig)
- [ ] M1-28: Security-Fixtures: XSS, IDOR, private Layer, Entwürfe über öffentliche REST nicht abrufbar
- [ ] M1-29: Multisite-, Aktivierungs-, Upgrade- und Deinstallationstests
- [ ] M1-30: Akzeptanzkriterien 1–9 aus P§17 einzeln nachweisen; Release-ZIP auf Testinstallation ohne Build-Tools installieren

## M2 – Geodaten (P§16 Phase 2)

- [ ] M2-01: Mehrere Layer pro Karte (Titel, Sichtbarkeit, Reihenfolge, Stil); Basiskarte und Overlay getrennt; Zoombegrenzung pro Layer
- [ ] M2-02: Linien und Polygone zeichnen und bearbeiten (Stützpunkte verschieben/ergänzen/entfernen); Multi-Geometrien; Polygonlöcher beim Import erhalten und im Editor testen
- [ ] M2-03: Stile: Farbe, Deckkraft, Linienstärke, Füllung, Strichmuster; Stilregeln pro Layer
- [ ] M2-04: Kategorien und Tags mit Filter, Legende und individueller Gestaltung (nicht nur per Farbe unterscheidbar)
- [ ] M2-05: Marker-Clustering (klickbar, auflösbar)
- [ ] M2-06: Synchronisierte Standortliste mit Suche und Filter
- [ ] M2-07: Popup, Tooltip oder Detail-Seitenleiste; strukturierte Zusatzfelder
- [ ] M2-08: Streckenlänge sphärisch (Haversine), Unit-Tests
- [ ] M2-09: Importer GeoJSON (RFC 7946; GeometryCollection ablehnen oder zerlegen), GPX 1.0/1.1 (Segmente, Routen, Wegpunkte), CSV (Trennzeichen/Dezimalformat explizit)
- [ ] M2-10: Sichere Parser: XML ohne Entities/DTD/Netzwerk; Limits für Dateigröße, Feature-/Koordinatenanzahl, Verschachtelung; XXE-Fixtures
- [ ] M2-11: Import-Assistent: Vorschau, Feldzuordnung, Validierungsbericht, Fehlerliste; Modi neuer Layer, Ergänzen, Upsert (externe ID), bestätigtes Ersetzen
- [ ] M2-12: Synchroner Import in Transaktion bzw. Staging; Ablehnung vor dem Schreiben bei Limit-Überschreitung; kein stiller Teilimport; Hinweis auf neu zu verknüpfende Medien im Importbericht
- [ ] M2-13: `POST /imports` (validieren und ausführen)
- [ ] M2-14: PMTiles-/Protomaps-Provider
- [ ] M2-15: Optionaler AJAX-Modus pro Karte: `/public/maps/{id}/features` mit bbox/zoom/filter, begrenzte Seitengröße, Abbrechen/Entprellen beim Verschieben
- [ ] M2-16: Vereinfachte Geometrien für große Tracks als Darstellungsvariante (kanonische Geometrie bleibt unverändert)
- [ ] M2-17: Playwright: Track importieren, Filter, Clustering; Tests für alle Importformate inkl. Fehlerfälle

## M3 – Betrieb (P§16 Phase 3)

- [ ] M3-01: Export: GeoJSON (Features/Layer), CSV (Punkte), versionierter Plugin-JSON-Export (ohne Zugangsdaten und ohne Medien)
- [ ] M3-02: Karte duplizieren, exportieren und importieren (Plugin-JSON)
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
