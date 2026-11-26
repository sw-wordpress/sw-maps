# DONE

Erledigte Aufgaben aus [TODO.md](TODO.md) (Foundation = M0, MVP = M1; Details der Abnahme: [docs/acceptance-mvp.md](docs/acceptance-mvp.md)).


## M0 – Foundation (P§16 Phase 0)


### Vorarbeiten und Entscheidungen

- [x] M0-01: BackupIt prüfen: `AGENTS.md`, `CODE-STYLE.md`, `composer.json`, `apps/admin/package.json`, `Makefile`, CI-Workflows, Build- und i18n-Skripte (P§2)
  - Ergebnis: `docs/dependencies.md` (Abgleich mit SW BackupIt: übernommen/nicht übernommen), `AGENTS.md`, `CODE-STYLE.md`.
- [x] M0-02: WordPress-Mindestversion und Dependency-Versionen mit BackupIt abgleichen; Supportmatrix MySQL/MariaDB übernehmen
  - Ergebnis: `docs/dependencies.md`: PHP ≥ 8.2, WP ≥ 6.4, Node/Vue/Quasar/Vite/PHPUnit/PHPStan wie BackupIt; DB-Matrix folgt WordPress (BackupIt nennt keine Versionen).
- [x] M0-03: OpenLayers-Version und PMTiles-Anbindung auf Lizenz (GPL-kompatibel), Wartung und Bundlegröße prüfen (P§18)
  - Ergebnis: `docs/dependencies.md`: OpenLayers 10 (BSD-2-Clause), gemessene Bundlegröße; PMTiles-Entscheidung bewusst nach M2-14 verschoben.
- [x] M0-04: Datenbankschema final dokumentieren: SQL-Typen, Indizes, Löschregeln, Migrationen (P§7)
  - Ergebnis: `docs/database.md`: Typen, Indizes, Löschregeln, Migrationen.
- [x] M0-05: Kurze ADRs in `docs/` anlegen: eigene Tabellen, ein Renderer bis 1.0, synchroner Import, Secrets in `wp-config`
  - Ergebnis: `docs/adr/0001..0004`.
- [x] M0-06: REST-Verträge dokumentieren: Argumente, Pagination, maximale Seitengröße, Fehlercodes, Antwortschemata (P§9)
  - Ergebnis: `docs/rest-api.md`.
- [x] M0-07: PHP-API (`swmap_render_map`, `swmap_register_source`) und Hook-Verträge finalisieren (P§9)
  - Ergebnis: `docs/hooks.md` (PHP-API, Hooks, Filter, Capabilities).

### Repository und Tooling

- [x] M0-08: Repository-Struktur laut P§3 anlegen (`apps/admin`, `packages/*`, `bin`, `docs`, `pages`, `languages`, `tests/*`, `e2e`)
  - Ergebnis: Struktur laut P§3 (ohne `packages/renderer-google`, das ist 1.0+).
- [x] M0-09: `AGENTS.md`, `CODE-STYLE.md`, `TODO.md`, `DONE.md`, `README.md` nach Referenzstruktur anlegen; Regel „Texte in der Oberfläche“ (P§6.1) in `AGENTS.md` übernehmen
  - Ergebnis: Regel „Texte in der Oberfläche“ in `AGENTS.md`.
- [x] M0-10: Composer-Projekt: PSR-4 (`SWMaps`), strict_types, PHP >= 8.2, Mozart-Prefixing wie BackupIt
  - Ergebnis: `composer.json` (PSR-4 `SWMaps\`, PHP ≥ 8.2, Mozart-Konfiguration; `bin/build-zip.sh` führt Mozart aus, sobald Laufzeitpakete existieren – aktuell keine).
- [x] M0-11: PHPCS (PSR-12 im Core, WPCS im WP-Adapter) und PHPStan Level 8 ohne Baseline einrichten
  - Ergebnis: `phpcs.xml.dist` (PSR-12 Core), `phpcs-wordpress.xml.dist` (WPCS Adapter), `phpstan.neon.dist` Level 8 ohne Baseline.
- [x] M0-12: Plugin-Bootstrap `sw-maps.php`, `uninstall.php` (Löschen nur bei gesetzter Option), Namenskonventionen (P§2.1)
  - Ergebnis: `sw-maps.php`, `uninstall.php` (Löschen nur mit Opt-in), Präfixe laut P§2.1.
- [x] M0-13: Admin-App: Vue 3, Quasar 2 (ohne Quasar CLI), TypeScript, Vite, Pinia, Vue Router, Vue I18n; ESLint, Prettier, vue-tsc
  - Ergebnis: `apps/admin` (Vue 3, Quasar 2 ohne CLI, TypeScript, Vite, Pinia, Router, I18n; ESLint, Prettier, vue-tsc).
- [x] M0-14: `packages/map-contracts` (TypeScript-Datenverträge) und `packages/renderer-openlayers` als leeres Gerüst mit separatem Vite-Build inkl. CSS
  - Ergebnis: `packages/map-contracts`, `packages/renderer-openlayers` (separater Vite-Build inkl. CSS nach `assets/frontend`).
- [x] M0-15: Makefile (`build`, `dist`, `release`), reproduzierbarer Build mit Lockfiles, Release-ZIP ohne Node/Composer auf dem Zielhosting
  - Ergebnis: `Makefile` (`build`, `dist`, `release`, `check`), `bin/build-zip.sh`, `bin/verify-zip.sh`, Lockfiles; Release-ZIP ohne Node/Composer-Bedarf auf dem Zielhosting.
- [x] M0-16: i18n-Infrastruktur: gettext in `languages/`, SPA-Kataloge in `apps/admin/src/i18n/`, `bin/i18n.sh`, automatische Paritätsprüfung (de/en)
  - Ergebnis: `bin/i18n.sh`, `languages/` (pot/po/mo + Block-JSON), SPA-Kataloge de/en, Paritätstests (`I18nTest`, `config.test.ts`).

### Testumgebungen und CI (P§14.1)

- [x] M0-17: PHPUnit 11 (Unit/Integration) mit Coverage-Check-Skript (>80 %, PHP und JS/TS getrennt)
  - Ergebnis: PHPUnit 11, `tests/coverage/{merge,check}.php`, `bin/coverage-php.sh` (PHP ≈ 89 %).
- [x] M0-18: `tests/wp/` als eigenes Composer-Projekt (wp-phpunit, WordPress-Core), Einzelsite und Multisite
  - Ergebnis: `tests/wp/` (wp-phpunit, Einzelsite `phpunit.xml.dist`, Multisite `phpunit-ms.xml.dist`).
- [x] M0-19: `bin/test-db.sh` (Wegwerf-MariaDB) und MariaDB-Service in CI
  - Ergebnis: `bin/test-db.sh`; MariaDB-Service in `.github/workflows/tests.yml`.
- [x] M0-20: Vitest mit hartem Coverage-Skript (>80 %)
  - Ergebnis: Vitest je Paket mit hartem Gate `scripts/check-coverage.mjs` (> 80 %); `bin/test-js.sh`.
- [x] M0-21: `tests/e2e-env/` (Docker Compose) und `bin/test-e2e.sh`; Playwright in `e2e/` mit 3 Workern, 2 Retries und Mock-Modus ohne WordPress
  - Ergebnis: `tests/e2e-env/` (Docker Compose), `bin/test-e2e.sh`, `e2e/` (3 Worker, 2 Retries, Mock-Modus `playwright.mock.config.ts`).
- [x] M0-22: GitHub Actions: Tests auf Pull Requests, Dependency-Audits, Lizenzhinweise, SonarQube
  - Ergebnis: `.github/workflows/{tests,security-audit,release}.yml`, `sonar-project.properties`. In dieser Umgebung nicht ausführbar (GitHub), Skripte lokal geprüft.
- [x] M0-23: CI-Messung der komprimierten Bundlegrößen mit Budget (P§18)
  - Ergebnis: `bin/check-bundle-size.mjs` + `bin/bundle-budget.json` (in `make build`/CI).

### Schema und Prototyp

- [x] M0-24: Schema-Migrationen (versioniert, multisite-fähig) für `swmap_maps`, `swmap_layers`, `swmap_features`, `swmap_categories`, `swmap_feature_categories`, `swmap_providers`; Migrationstests
  - Ergebnis: `Persistence/Schema.php`, `SchemaTest`, `MultisiteTest`.
- [x] M0-25: Renderer-Vertrag (mount, update, setView, getView, fitBounds, on, destroy) in `map-contracts`
  - Ergebnis: `packages/map-contracts` (`MapRenderer`, `EditableRenderer`).
- [x] M0-26: OpenLayers-Prototyp: Viewer und einfacher Editor (Marker setzen, Linie/Polygon zeichnen) in der Admin-App
  - Ergebnis: Viewer und Editor (Marker, Linie, Fläche) in der Admin-App; Tests in Renderer-Paket und E2E.

## M1 – MVP (P§16 Phase 1, P§17)


### Backend und Datenhaltung

- [x] M1-01: Core-Domain und Geo: `Map`, `Layer`, `Feature`, `Geometry`, `Coordinate`, `Bounds`, `GeometryValidator` (nur ±180° / Mercator-Grenzen, ungültige Koordinaten ablehnen)
  - Ergebnis: `packages/core/src/{Domain,Geo}` (inkl. `GeometryValidator`, Grenzen ±180° / Mercator).
- [x] M1-02: wpdb-Repositories für Karten, Layer, Features (alle SQL über `prepare()`), Löschregeln
  - Ergebnis: `Persistence/Wpdb*` (alle SQL über `prepare()`/Formate), Löschregeln im Service; tolerante Hydration.
- [x] M1-03: Application Services (`MapService`, `LayerService`, `FeatureService`); keine Logik in Controllern
  - Ergebnis: `Application/{Map,Layer,Feature,Provider}Service`.
- [x] M1-04: Capabilities getrennt für Bearbeiten, Veröffentlichen, Layer, Importe, Provider/System; Zuordnung bei Aktivierung dokumentieren (P§12)
  - Ergebnis: `Capabilities.php`, Zuordnung in `docs/hooks.md`, `LifecycleTest`.
- [x] M1-05: REST `sw-maps/v1`: `/maps`, `/maps/{id}`, `/maps/{id}/layers`, `/layers/{id}`, `/layers/{id}/features`, `/features/{id}` mit `permission_callback` und objektbezogener Prüfung
  - Ergebnis: `Api/*Controller` mit `permission_callback` + objektbezogener Prüfung (`Access`, Filter `swmap_can_edit_map`).
- [x] M1-06: Provider-Konfiguration: OSM-Raster vorkonfiguriert und aktiv, weitere XYZ-Provider, `secret_constant` (Name einer `wp-config`-Konstante), Attribution (P§4.5)
  - Ergebnis: `Provider`, `ProviderService`, OSM-Seed; Geheimnis nur als Konstantenname.
- [x] M1-07: Provider-Endpunkte inkl. Verbindungstest; Anzeige der externen Requests vor Aktivierung
  - Ergebnis: `/providers`, `/providers/{id}/test`; externe Hosts im Admin vor Aktivierung.

### Einbindung und Frontend

- [x] M1-08: Shortcode `[sw_map id=""]` und PHP-API `swmap_render_map()`
  - Ergebnis: `Integration/Shortcode.php`, `functions.php` (`swmap_render_map`).
- [x] M1-09: Dynamischer Gutenberg-Block `sw-maps/map` mit Kartenauswahl und Vorschau im Editor
  - Ergebnis: `blocks/map` (dynamisch, Auswahl, serverseitige Editor-Vorschau).
- [x] M1-10: Serverseitiges Rendering: HTML-Container mit sicher kodiertem Inline-JSON; Standortliste als serverseitiges HTML (P§5.1)
  - Ergebnis: `Integration/Renderer.php` (hex-kodiertes Inline-JSON, serverseitige Liste).
- [x] M1-11: OpenLayers-Viewer: Marker, Popups, Zoom/Vollbild/Maßstab, Attribution dauerhaft sichtbar auch im Vollbild
  - Ergebnis: `packages/renderer-openlayers` (Marker, Popups, Zoom/Vollbild/Maßstab, dauerhafte Attribution).
- [x] M1-12: Popups nur aus strukturierten Feldern mit serverseitig bereinigtem Inhalt; XSS-Tests inkl. eingebettetem JSON
  - Ergebnis: `popup.ts` (nur DOM-Text), serverseitige Bereinigung; XSS-Tests PHP/Vitest/E2E.
- [x] M1-13: Mehrere unabhängige Karten pro Seite, Assets nur auf Seiten mit Karten, ein gemeinsames Bundle, Fehlerisolation
  - Ergebnis: Ein Bundle, Assets nur bei Karte, Fehlerisolation (`initAll`).
- [x] M1-14: Lazy Initialization (IntersectionObserver), verständliche Fehlermeldung und Fallback bei Kartenfehlern
  - Ergebnis: `viewer.ts` (IntersectionObserver, Fehlermeldung, Fallback).
- [x] M1-15: Entwürfe nur mit Berechtigung in der Vorschau; nicht veröffentlichte Inhalte nie öffentlich
  - Ergebnis: `Renderer::render` + `Access::can_preview_map`.

### Datenschutz und Barrierefreiheit

- [x] M1-16: WP Consent API: Tile-Requests erst nach Zustimmung; Kompatibilitätstest mit gängigen Consent-Plugins
  - Ergebnis: `Integration/Consent.php`, `consent.ts` (fail closed); getestet mit dem echten wp-consent-api-Plugin (E2E, Opt-in); konkrete Banner-Plugins sind bewusst nicht im Umfang.
- [x] M1-17: Optional Click-to-load mit lokalem Platzhalter; Standortliste bleibt nutzbar
  - Ergebnis: Click-to-load mit lokalem Platzhalter.
- [x] M1-18: Datenschutz-Platzhalter und Privacy-Policy-Text (`wp_add_privacy_policy_content`)
  - Ergebnis: `Integration/Privacy.php`.
- [x] M1-19: Standortliste als verbindlicher barrierearmer Zugang, mit der Karte synchronisiert; Tastatur, Fokus, reduzierte Bewegung, mobile-first
  - Ergebnis: Server-HTML-Liste, Sync, Tastatur/Fokus, `prefers-reduced-motion`, mobile-first.

### Admin-Oberfläche

- [x] M1-20: Ansichten: Dashboard, Karten (Liste, Duplizieren, Einbindungscode), Anbieter, Einstellungen (Defaults, Berechtigungen, Limits, Deinstallationsverhalten), Systemstatus (DB-Schema, Umgebung)
  - Ergebnis: Dashboard, Karten (Liste, Duplizieren, Einbindungscode), Anbieter, Einstellungen, Systemstatus.
- [x] M1-21: Karteneditor: Layer-/Feature-Leiste, Karte, Eigenschaftsleiste; Marker per Klick, Drag-and-drop und Koordinateneingabe
  - Ergebnis: Editor mit Layer-/Feature-Leiste, Karte, Eigenschaftsleiste.
- [x] M1-22: Startansicht (Mittelpunkt/Zoom, Auto-Bounds), Zoomgrenzen, Höhe/Seitenverhältnis, Scroll-Zoom/Touch/Drag getrennt konfigurierbar, Vorschau Desktop/Mobil
  - Ergebnis: Startansicht, Zoomgrenzen, Höhe/Seitenverhältnis, getrennte Interaktionsoptionen, Vorschau.
- [x] M1-23: Explizites Speichern, Warnung bei ungespeicherten Änderungen, Undo/Redo für lokale Änderungen (kein Locking, letzter Speichervorgang gilt)
  - Ergebnis: Explizites Speichern (Diff), Warnung, Undo/Redo.
- [x] M1-24: Titel, Kurzbeschreibung, Adresse, Bild, Link, Icons (lokal und sichere Rasterbilder aus der Mediathek) für Marker
  - Ergebnis: Titel, Beschreibung, Adresse, Link, Symbole (lokal + Mediathek), Bild.
- [x] M1-25: Texte nach der Regel aus P§6.1 prüfen (de/en)
  - Ergebnis: Prüfung der Oberflächentexte nach P§6.1 (nur entscheidungsrelevante Hinweise: Anbieter-Datenschutz/Kosten, Platzhalter-Hinweise); de/en-Parität per Test.

### MVP-Abnahme

- [x] M1-26: Playwright: Karte erstellen, Marker verschieben, veröffentlichen, per Gutenberg und Shortcode einbinden, mehrere Karten, Mobilansicht, Tastatur
  - Ergebnis: `e2e/tests/{admin-editor,frontend}.spec.ts`.
- [x] M1-27: Netzwerktests: keine externen Requests vor Consent, Seite ohne Karte lädt kein Bundle, mit gemockten Tiles (CI nie von öffentlichen OSM-Diensten abhängig)
  - Ergebnis: `e2e/tests/privacy.spec.ts` (gemockte Tiles, keine externen Requests vor Consent, kein Bundle ohne Karte).
- [x] M1-28: Security-Fixtures: XSS, IDOR, private Layer, Entwürfe über öffentliche REST nicht abrufbar
  - Ergebnis: `RenderingTest`, `RestApiTest`, `e2e/tests/security.spec.ts`.
- [x] M1-29: Multisite-, Aktivierungs-, Upgrade- und Deinstallationstests
  - Ergebnis: `SchemaTest`, `MultisiteTest`, `LifecycleTest`.
- [x] M1-30: Akzeptanzkriterien 1–9 aus P§17 einzeln nachweisen; Release-ZIP auf Testinstallation ohne Build-Tools installieren
  - Ergebnis: `docs/acceptance-mvp.md`, `make dist`, `bin/test-zip-install.sh`.

## M2 – Geodaten (P§16 Phase 2)

- [x] M2-01: Mehrere Layer pro Karte (Titel, Sichtbarkeit, Reihenfolge, Stil); Basiskarte und Overlay getrennt; Zoombegrenzung pro Layer
  - Ergebnis: Layer-Reihenfolge (hoch/runter, bestimmt die Zeichenreihenfolge), Layer-Stil und Zoombegrenzung pro Layer (`minZoom`/`maxZoom`, auch für Cluster); Basiskarte (Anbieter) und Overlays (Layer) sind getrennte Konzepte. Tests: `model.test.ts`, `LayerPanel`, `filters.test.ts`.
- [x] M2-02: Linien und Polygone zeichnen und bearbeiten (Stützpunkte verschieben/ergänzen/entfernen); Multi-Geometrien; Polygonlöcher beim Import erhalten und im Editor testen
  - Ergebnis: Das Auswahlwerkzeug verschiebt, ergänzt (Ziehen auf der Linie) und entfernt (Alt+Klick) Stützpunkte; Polygonlöcher und Multi-Geometrien bleiben durch Import und Bearbeitung erhalten (`renderer.test.ts`, `ImportersTest`, e2e). Neue Multi-Geometrien entstehen über „Teil hinzufügen“ im Standortformular (Punkt/Linie/Fläche wird als weiterer Teil angehängt).
- [x] M2-03: Stile: Farbe, Deckkraft, Linienstärke, Füllung, Strichmuster; Stilregeln pro Layer
  - Ergebnis: Farbe, Deckkraft, Linienstärke, Füllung, Strichmuster je Standort und Layer; Stilregeln pro Layer (`style.rules`: Kategorie → Stil, höchstens 10, validiert; Reihenfolge Standort > Regel > Kategorie > Layer), bearbeitbar im Layer-Panel.
- [x] M2-04: Kategorien und Tags mit Filter, Legende und individueller Gestaltung (nicht nur per Farbe unterscheidbar)
  - Ergebnis: Seitenweite Kategorien (REST `/categories`, Verwaltung im Editor), Zuordnung je Standort, Filter-Legende mit Symbol und Farbe im Viewer, Kategorie-Stil als Standard für Farbe und Symbol. Tags als eigenes Konzept gibt es nicht (Kategorien decken den Fall ab).
- [x] M2-05: Marker-Clustering (klickbar, auflösbar)
  - Ergebnis: Clustering per Karteneinstellung (`cluster`): Gruppen mit Anzahl, Klick zoomt auf die Marker. Lassen sich die Marker einer Gruppe durch Zoomen nicht trennen (gleiche Stelle oder höchster Zoom), öffnet der Klick eine Auswahlliste der Mitglieder, auch ohne Standortliste (AJAX-Modus).
- [x] M2-06: Synchronisierte Standortliste mit Suche und Filter
  - Ergebnis: Serverseitig gerenderte Liste mit Suche und Kategoriefilter, Liste und Karte werden gemeinsam gefiltert (`filters.ts`).
- [x] M2-07: Popup, Tooltip oder Detail-Seitenleiste; strukturierte Zusatzfelder
  - Ergebnis: Popup mit strukturierten Zusatzfeldern (Bezeichnung/Wert, bis 10, reiner Text), auch aus CSV-Spalten importierbar. Zusätzlich Hover-Tooltip mit dem Titel (Zeigergeräte). Eine Detail-Seitenleiste gibt es nicht.
- [x] M2-08: Streckenlänge sphärisch (Haversine), Unit-Tests
  - Ergebnis: Core `DistanceCalculator` (Haversine, Radius 6371008,8 m) mit Unit-Tests; identische Berechnung in `map-contracts` (`lineLengthMeters`), Anzeige im Editor. Die REST-API liefert `length_m` (nur lesend).
- [x] M2-09: Importer GeoJSON (RFC 7946; GeometryCollection ablehnen oder zerlegen), GPX 1.0/1.1 (Segmente, Routen, Wegpunkte), CSV (Trennzeichen/Dezimalformat explizit)
  - Ergebnis: `GeoJsonImporter` (GeometryCollection wird zerlegt, Teile erhalten `#n` an der externen ID), `GpxImporter` (1.0/1.1; Wegpunkte, Routen, Tracks als (Multi-)Linie je Segment), `CsvImporter` (Trennzeichen und Dezimaltrenner explizit). Tests: `ImportersTest`.
- [x] M2-10: Sichere Parser: XML ohne Entities/DTD/Netzwerk; Limits für Dateigröße, Feature-/Koordinatenanzahl, Verschachtelung; XXE-Fixtures
  - Ergebnis: Limits (`ImportLimits`: 5 MiB, Features je Layer, 200 000 Koordinaten, JSON-Tiefe 32); XML mit `LIBXML_NONET`, ohne Entities, DOCTYPE wird abgelehnt (XXE-, Entity-Bomb- und Netzwerk-Fixtures im Test).
- [x] M2-11: Import-Assistent: Vorschau, Feldzuordnung, Validierungsbericht, Fehlerliste; Modi neuer Layer, Ergänzen, Upsert (externe ID), bestätigtes Ersetzen
  - Ergebnis: Import-Assistent als Dialog im Editor: Datei, Format, Modus, Feldzuordnung, Zusatzfelder, Vorschau, Hinweise/Fehler (übersetzt über Codes), Bestätigung für Ersetzen. Tests: `import-dialog.test.ts`, e2e.
- [x] M2-12: Synchroner Import in Transaktion bzw. Staging; Ablehnung vor dem Schreiben bei Limit-Überschreitung; kein stiller Teilimport; Hinweis auf neu zu verknüpfende Medien im Importbericht
  - Ergebnis: `ImportService`: eine Transaktion, Ablehnung vor dem Schreiben bei Limit-Überschreitung, kein stiller Teilimport (ungültige Einträge nur mit `skip_invalid`), Hinweis `media_relink_hint`, Upsert behält Bild und Symbol.
- [x] M2-13: `POST /imports` (validieren und ausführen)
  - Ergebnis: `POST /imports` (`dry_run` validiert, sonst ausführen); Doku in `docs/rest-api.md`; WP-Integrationstest.
- [x] M2-14: PMTiles-/Protomaps-Provider
  - Ergebnis: Typ `pmtiles` (Raster) mit Verbindungstest und Range-Request-Quelle; Vektor-Archive bewusst nicht ([ADR 0005](docs/adr/0005-pmtiles-raster.md)).
- [x] M2-15: Optionaler AJAX-Modus pro Karte: `/public/maps/{id}/features` mit bbox/zoom/filter, begrenzte Seitengröße, Abbrechen/Entprellen beim Verschieben
  - Ergebnis: Einstellung `ajax`: öffentlicher Endpunkt `/public/maps/{id}/features` (bbox, Zoom, Kategorien, Seitengröße ≤ 500), Client mit Debounce (250 ms) und Abbruch; Tests: `ajax.test.ts`, `RestApiTest`, e2e.
- [x] M2-16: Vereinfachte Geometrien für große Tracks als Darstellungsvariante (kanonische Geometrie bleibt unverändert)
  - Ergebnis: `Simplifier` (Douglas-Peucker, Endpunkte bleiben) im öffentlichen Payload ab 500 Koordinaten (`swmap_simplify_min_points`, `swmap_simplify_tolerance`); gespeicherte Geometrie unverändert (WP-Test).
- [x] M2-17: Playwright: Track importieren, Filter, Clustering; Tests für alle Importformate inkl. Fehlerfälle
  - Ergebnis: `e2e/tests/m2-geodata.spec.ts`: Import GeoJSON (Polygonloch bleibt erhalten, Upsert per externer ID), GPX-Track, CSV mit Semikolon/Dezimalkomma, Fehlerfälle (XXE-Datei, ungültige Einträge blockieren bis zum Überspringen), Kategorie-Filter, Suche, Clustering (Klick zoomt), AJAX-Modus (nur sichtbarer Ausschnitt, nichts inline).
