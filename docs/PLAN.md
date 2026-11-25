# SW Maps – PLAN.md

Status: Konzept / Implementierungsgrundlage
Plugin-Slug: `sw-maps`
Namespace: `SWMaps`
Lizenz: GPL-2.0-or-later

Verschobene Themen (nach 1.0 bzw. Ideen) stehen in [BACKLOG.md](BACKLOG.md). Sie sind **kein** Ziel dieses Plans.

## 1. Zielsetzung

SW Maps ist ein eigenständiges Open-Source-WordPress-Plugin zur Erstellung, Verwaltung und Darstellung interaktiver geografischer Karten. Zielgruppe sind insbesondere Vereine, Gemeinden, touristische Einrichtungen und kleine Unternehmen.

- Karten ohne Programmierkenntnisse erstellen und in Beiträge, Seiten und Templates einbinden.
- Standorte, Wanderwege, Veranstaltungsorte, Filialen und geografische Projekte darstellen.
- Marker, Linien, Flächen, GPX-Tracks und GeoJSON-Daten verwalten.
- Keine verpflichtenden Cloud-Dienste, API-Schlüssel, Premium-Funktionen oder Telemetrie. OpenStreetMap ist ab Installation ohne Schlüssel nutzbar.
- Freie Wahl des Kartenanbieters; vollständig selbst gehosteter Kartenbetrieb muss möglich sein.
- Optionale Integration in andere SW-Plugins ausschließlich über stabile Hooks (siehe Abschnitt 10).
- Keine Abhängigkeit von anderen SW-Plugins.
- Die generelle UI soll sich an anderen sw-* Plugins orientieren
- Die Editor UI soll sich speziell an sw-gallery und sw-sliderman orientieren, welche den Fokus auf effiziente Darstellung legen

## 2. Technischer Referenzrahmen

Verbindliche Architekturvorlage: [SW BackupIt](https://github.com/sw-wordpress/sw-backupit). Vor Implementierung die aktuellen `AGENTS.md`, `CODE-STYLE.md`, `composer.json`, `apps/admin/package.json`, `Makefile`, CI-Workflows sowie Build- und i18n-Skripte prüfen. Struktur und Qualitätsvorgaben übernehmen, Backup-/Restore-spezifische Logik nicht kopieren. WordPress-Mindestversion und konkrete Dependency-Versionen mit dem Referenzprojekt abgleichen.

| Bereich | Technologie |
|---|---|
| Backend | PHP >= 8.2, strict_types, Composer, PSR-4 |
| Core | Frameworkunabhängig, PSR-12, typisierte DTOs, Enums |
| WP-Adapter | WPCS, WP REST API, WP-CLI |
| Admin | Vue 3, Quasar 2, TypeScript, Vite, Pinia, Vue Router, Vue I18n; kein Quasar CLI |
| Kartenrenderer | **OpenLayers** (Viewer und Editor); Google Maps als optionaler, getrennter Adapter nach 1.0 |
| Geodaten | GeoJSON nach RFC 7946, GPX 1.0/1.1, CSV |
| Frontend | TypeScript, ohne Quasar/Vue-Laufzeitpflicht |
| Tooling | Node.js gemäß BackupIt-Engine-Constraints, ESLint, Prettier, vue-tsc |
| Tests | PHPUnit 11, Vitest, WordPress-Integrationstests, Playwright |
| Qualität | PHPStan 2 Level 8, PHPCS/WPCS, SonarQube, >80 % Zeilenabdeckung je Sprache |
| Build | Makefile, GitHub Actions, installierbares ZIP, isolierte Composer-Abhängigkeiten (Mozart-Strategie wie BackupIt) |
| Dokumentation | docs/, pages/, GitHub Pages nach Referenzprojekt |

Alle Abhängigkeiten müssen GPL-kompatibel sein; keine kommerziellen Editor-Erweiterungen voraussetzen. PHP >= 8.2 ist bewusste Entscheidung.

### 2.1 Namenskonventionen

Die Präfixe unterscheiden sich bewusst je nach Kontext:

| Kontext | Präfix / Name |
|---|---|
| Plugin-Slug, WP-CLI, Textdomain | `sw-maps` |
| PHP-Namespace | `SWMaps` |
| Datenbanktabellen | `{$wpdb->prefix}swmap_*` |
| PHP-Funktionen, Hooks, Optionen | `swmap_*` |
| Shortcode | `sw_map` |
| REST-Namespace | `sw-maps/v1` |
| Gutenberg-Block | `sw-maps/map` |

## 3. Repository-Architektur

```text
sw-maps/
  apps/admin/                 # Vue SPA, Karteneditor
  packages/map-contracts/     # TypeScript-Datenverträge (einziges geteiltes Paket)
  packages/renderer-openlayers/  # OpenLayers-Renderer inkl. Frontend-Bundle (Viewer, Lifecycle, Registrierung)
  packages/renderer-google/   # später: Google-Adapter inkl. eigenem Frontend-Bundle
  packages/core/src/          # Domain, Geo, Import, Contracts (PHP)
  packages/wordpress/src/     # WP hooks, REST, persistence, blocks, CLI (PHP)
  assets/js/                  # generierte Bundles
  bin/                        # Build-, i18n- und Entwicklungsskripte
  docs/                       # PLAN.md, BACKLOG.md, technische Dokumentation, ADRs
  pages/                      # Benutzerhandbuch, Changelog.md
  languages/
  tests/Unit/
  tests/Integration/
  tests/E2e/
  tests/wp/                   # eigenes Composer-Projekt für die WordPress-Testsuite
  tests/e2e-env/              # Docker-Compose-Wegwerf-WordPress für Playwright
  e2e/                        # Playwright (eigene package.json)
  .github/workflows/
  sw-maps.php
  uninstall.php
  composer.json
  Makefile
  AGENTS.md
  CODE-STYLE.md
  TODO.md
  DONE.md
  README.md
```

Abhängigkeiten zeigen nach innen: WordPress -> Core. Der Core kennt keine WordPress-Funktionen, HTTP-Endpunkte oder globalen WordPress-Zustände. REST-Controller delegieren an Application Services. Admin und Renderer teilen nur die typisierten Kartenverträge, nicht die vollständige SPA. Gutenberg-Integration über die nativen WordPress-Block-APIs.

Jedes Renderer-Paket bringt sein eigenes Frontend-Bundle mit; es gibt kein separates allgemeines Frontend-Paket.

## 4. Fachlicher Funktionsumfang

### 4.1 Kartenverwaltung

- Beliebig viele Karten mit Titel, Beschreibung, Status (Entwurf/veröffentlicht) und optionalem Slug.
- Karte duplizieren, exportieren, importieren und löschen.
- Startansicht: Mittelpunkt und Zoom, automatische Bounds oder manuell gesetzter Ausschnitt.
- Minimale/maximale Zoomstufe, optionale geografische Begrenzung.
- Konfigurierbare Höhe, Seitenverhältnis, Vollbild, Zoomkontrollen, Maßstab, Legende, Layer-Auswahl.
- Scroll-Zoom, Touch-Gesten und Drag-Verhalten getrennt konfigurierbar; kein Scroll-Trapping als Standard.
- Mehrere unabhängige Karten auf einer Seite.
- Vorschau im Editor mit Desktop- und Mobilansicht.

### 4.2 Marker und Points of Interest

- Marker per Klick, Drag-and-drop, Koordinateneingabe oder optionaler Adresssuche (nur im Admin) erstellen.
- Titel, Kurzbeschreibung, Adresse, Bild, Link und strukturierte Zusatzfelder.
- Farben, lokale Icons, eigene sichere Rasterbild-Icons aus der Mediathek.
- Kategorien und Tags mit Filterung, Legende und individueller Gestaltung.
- Popup, Tooltip oder optionale Detail-Seitenleiste.
- Marker-Clustering bei großen Punktmengen; Cluster anklickbar und auflösbar.
- Optional synchronisierte Standortliste mit Suche und Filterung.
- Externe Routenplaner nur als einfacher konfigurierbarer Link im Popup; Routing selbst ist Thema nach 1.0 (BACKLOG).

### 4.3 Linien, Routen und Flächen

- Geometrien Point, MultiPoint, LineString, MultiLineString, Polygon und MultiPolygon unterstützen; GeometryCollection zunächst explizit ablehnen oder in validierte Einzelfeatures zerlegen.
- Linien und Polygone zeichnen, Stützpunkte verschieben, ergänzen und entfernen.
- Farbe, Deckkraft, Linienstärke, Füllung und Strichmuster konfigurieren.
- Polygonlöcher beim Import erhalten; Editor-Unterstützung separat testen.
- GPX-Tracks mit mehreren Segmenten, Routen und Wegpunkten importieren.
- Streckenlänge sphärisch (Haversine) berechnen; keine planare Berechnung in Web-Mercator.
- Höhenprofile, Navigation und Live-Ortung siehe BACKLOG.

### 4.4 Layer und Datenquellen

- Jeder Layer gehört zu genau einer Karte, mit Titel, Sichtbarkeit, Reihenfolge und Stil.
- Basiskarten und fachliche Overlay-Layer getrennt modellieren.
- Manuell gepflegte Features, lokale GeoJSON-/GPX-/CSV-Importe.
- Datenquellen über stabile IDs registrieren; später externe API- und WordPress-Datenquellen ergänzen.
- Layer bei Bedarf auf bestimmte Zoomstufen begrenzen.
- Wiederverwendbare, kartenübergreifende Layer: siehe BACKLOG.

### 4.5 Kartenanbieter und Datenschutz

- **OpenStreetMap-Raster ist vorkonfiguriert und standardmäßig aktiv**; es ist kein API-Schlüssel nötig. Attribution und Nutzungsrichtlinien werden beachtet.
- OpenStreetMap ist eine freie Geodatenbasis; öffentliche Tile-Server sind keine unbegrenzte kostenlose Hosting-Infrastruktur. Nutzungsrichtlinien, Attribution und verfügbare Kapazität beachten; der Admin wird auf Alternativen hingewiesen.
- Raster-XYZ-Tiles mit URL-Template, Attribution, Zoomgrenzen und optionalen öffentlichen Client-Schlüsseln.
- **Protomaps/PMTiles** als selbst hostbare Quelle (eine Datei, kein Tile-Server) unterstützen; Bibliothek (z. B. für OpenLayers) vorher auf Lizenz, Wartung und Bundlegröße prüfen.
- Eigene Tile-Server und lokale Kartenquellen unterstützen.
- Keine automatischen Tile-Downloads, Prefetches, Offline-Massendownloads oder Proxy-Caches gegen Providerbedingungen.
- Anbieter-Konfigurationen zentral verwalten; keine beliebigen JavaScript-Ausdrücke in Templates.
- Attribution dauerhaft sichtbar, auch im Vollbild.
- **WP Consent API wird verwendet.** Externe Requests (Tiles, Bilder) respektieren die Consent-Entscheidung. Zusätzlich optional „Click-to-load“: lokaler Platzhalter und zugängliche Standortliste, bis der Besucher aktiv wird. Kompatibilität mit gängigen Consent-Plugins testen, Privacy-Policy-Text über `wp_add_privacy_policy_content` bereitstellen.
- Externe Requests vor Aktivierung eines Providers im Admin transparent anzeigen.
- Keine Behauptung pauschaler DSGVO-Konformität.
- Alle JS-, CSS-, Icon- und Font-Ressourcen des Plugins werden lokal ausgeliefert. Ausnahme ist ein vom Betreiber bewusst aktivierter externer Anbieter (z. B. Google Maps), dessen Skripte naturgemäß nicht lokal sein können; dies wird im Admin kenntlich gemacht.

### 4.6 Geocoding

- Geocoding wird **ausschließlich im Admin** (Editor) verwendet; es gibt keine öffentliche Geocoding-Schnittstelle.
- Austauschbares Geocoder-Interface mit Such- und optionaler Reverse-Geocoding-Funktion.
- Geocoding optional; manuelle Koordinaten funktionieren immer.
- Öffentlichen Nominatim-Dienst nur nach expliziter Konfiguration und gemäß dessen Nutzungsrichtlinien nutzen: kein Autocomplete gegen öffentliche Instanz, Suche nur per Aktion, begrenzte Rate, Identifikation, zulässiges Caching.
- Selbst gehostetes Nominatim und weitere kompatible Anbieter ermöglichen.
- Suchergebnisse vor Übernahme bestätigen; keine ungeprüfte automatische Standortzuordnung.
- Geocoding-Schlüssel liegen serverseitig (wp-config) und gehen nie an den Browser.

### 4.7 Import und Export

- GeoJSON, GPX und CSV mit Vorschau, Feldzuordnung, Validierungsbericht und Fehlerliste.
- CSV: Titel, Latitude, Longitude, Beschreibung, Kategorie, optionale stabile externe ID; Trennzeichen und Dezimalformat explizit behandeln.
- Import-Modi: neuer Layer, Ergänzen, Upsert anhand stabiler externer ID, ausdrücklich bestätigtes Ersetzen.
- Fehlerhafte Zeilen nicht stillschweigend überspringen; Teilimport muss als solcher ausgewiesen werden.
- GeoJSON-Export für Features und Layer; CSV für Punktdaten; GPX-Export als spätere Erweiterung.
- Plugin-eigener versionierter JSON-Export für Karte, Styles, Layer und Einstellungen ohne Zugangsdaten.
- **Import läuft synchron** innerhalb eines Requests, begrenzt durch konfigurierbare Limits (Dateigröße, Feature-Anzahl, Laufzeit). Überschreitet eine Datei die Limits, wird der Import vor Beginn mit verständlicher Meldung abgelehnt. Es wird erst nach erfolgreicher Validierung geschrieben. Eine Job-Queue für große Importe steht im BACKLOG.
- Medien (Marker-Bilder etc.) sind nicht Teil des Exports; Verweise müssen nach einem Import auf einer anderen Site neu verknüpft werden. Das wird in der Dokumentation und im Importbericht ausgewiesen.
- XML ohne externe Entitäten, DTD oder Netzwerkzugriffe parsen. Dateigröße, Feature-Anzahl, Koordinatenanzahl und Verschachtelung begrenzen.
- Migration aus anderen Karten-Plugins: siehe Phase 4 (vor 1.0).

## 5. Einbindung und Frontend

### 5.1 Gutenberg, Shortcodes und Datenauslieferung

- Nativer dynamischer Block `sw-maps/map`: Karte auswählen, Vorschau und erlaubte Darstellungsoptionen überschreiben.
- Shortcode `[sw_map id="123"]` für Classic Editor, Widgets und kompatible Page Builder.
- PHP-API für Themes und andere Plugins.
- **Standard: Karte und Daten werden direkt als HTML-Container mit eingebettetem JSON ausgeliefert** und im JS gerendert. Die zugängliche Standortliste wird serverseitig als HTML erzeugt.
- **Optional pro Karte: Laden der Features per AJAX/REST** (sinnvoll bei großen Karten, viewportbezogen und paginiert).
- Nur veröffentlichte Karten und freigegebene Layer öffentlich ausliefern; Entwürfe ausschließlich mit passender Berechtigung in Vorschau.
- Assets nur auf Seiten mit Karten laden; mehrere Instanzen teilen ein Bundle.
- Lazy Initialization mittels IntersectionObserver, sofern sinnvoll; sichtbare Karte und zugängliche Standortliste bleiben auch bei Initialisierungsfehlern nutzbar.
- HTML-Popups ausschließlich aus strukturierten Feldern und serverseitig bereinigtem Inhalt erzeugen; keine Ausführung importierter Skripte.

### 5.2 Responsive und barrierearme Darstellung

- Mobile-first, Touch-Bedienung, kein horizontaler Seitenüberlauf.
- Tastaturbedienbare Steuerelemente, verständliche Labels, sichtbarer Fokus.
- **Die textuelle Standort-/Routenliste ist der verbindliche barrierearme Zugang.** OpenLayers rendert Marker im Canvas; sie sind nicht einzeln fokussierbar. Die Liste muss daher vollständig und mit der Karte synchronisiert sein.
- Kategorien nicht ausschließlich durch Farben unterscheiden.
- Nutzerpräferenz für reduzierte Bewegung berücksichtigen.
- Optionaler Geolocation-Button erst nach bewusster Nutzeraktion; Browserposition standardmäßig nicht an den Server senden.
- Kartenfehler mit verständlicher Meldung und Fallback anzeigen.

## 6. Administration (Vue 3 + Quasar)

| Ansicht | Funktionen |
|---|---|
| Dashboard | Karten, Layer, Feature-Anzahl, Provider-Diagnose |
| Karten | Suche, Filter, Status, Duplizieren, Bearbeiten, Einbindungscode |
| Karteneditor | Kartenfläche, Layer-Baum, Feature-Liste, Eigenschaften, Vorschau |
| Layer | Layer der Karte, Stilregeln, Datenquellen |
| Import/Export | Assistent, Feldzuordnung, Vorschau, Fehler, Importbericht |
| Anbieter | Basiskarten, Geocoder, Attribution, Datenschutz, Verbindungstest |
| Einstellungen | Defaults, Berechtigungen, Ressourcenlimits, Deinstallationsverhalten |
| Systemstatus | Datenbankschema, Versions-/Umgebungsdiagnose |

Editor: linke Layer-/Feature-Leiste, zentrale Karte, rechte Eigenschaftsleiste. Änderungen explizit speichern, ungespeicherte Änderungen beim Verlassen warnen. Undo/Redo für lokale Geometrieänderungen. Es gibt weder Revisionen noch Locking; der letzte Speichervorgang gilt.

### 6.1 Texte in der Oberfläche

Regel aus SW Gallery (`AGENTS.md`, „Texte in der WordPress-Oberfläche“), wird wörtlich in die `AGENTS.md` von SW Maps übernommen und gilt für Admin-SPA, Block-Editor und Frontend-Bedienelemente:

- Zeige nur Informationen, die bei einer Entscheidung, Aktion oder Fehlerbehebung helfen. Zusatztexte entfallen, wenn ein Status, eine Beschriftung oder der Kontext dieselbe Information bereits vermittelt. Technische Hintergründe gehören in die Dokumentation.
- Folge: keine erklärenden Untertitel unter selbsterklärenden Feldern, keine Hinweise, die den Feldnamen wiederholen, keine Texte zu Standardverhalten. Ausnahmen sind Datenschutz-, Kosten- und Sicherheitshinweise (z. B. externe Requests eines Providers), die der Plan ausdrücklich verlangt.
- Änderungen an sichtbaren Texten aktualisieren alle Sprachen (Deutsch und Englisch) im selben Arbeitsschritt.

## 7. Datenmodell

Eigene Tabellen mit `$wpdb->prefix` und versionierten Schema-Migrationen mit klaren Indizes. Begründung: Geodaten sind keine Post-Inhalte; Revisionen, Permalinks und Post-Metadaten werden nicht benötigt, Features brauchen eigene Indizes und Abfragen. Keine Pflicht zu PostGIS, MySQL Spatial Extensions oder externem Datenbankserver. WordPress-kompatible MySQL-/MariaDB-Versionen gemäß BackupIt-Supportmatrix.

| Entität / Tabelle (Suffix) | Wesentliche Felder |
|---|---|
| `swmap_maps` | id, slug, title, description, status, viewport_json, settings_json, created_by, created_at, updated_at |
| `swmap_layers` | id, map_id, title, type, status, sort_order, visible, style_json, source_config_json, created_at, updated_at |
| `swmap_features` | id, layer_id, external_id, geometry_type, geometry_json, bbox_min_lon, bbox_min_lat, bbox_max_lon, bbox_max_lat, properties_json, style_json, updated_at |
| `swmap_categories` | id, slug, title, style_json |
| `swmap_feature_categories` | feature_id, category_id |
| `swmap_providers` | id, type, name, public_config_json, secret_constant, enabled |

`secret_constant` enthält nur den **Namen** einer PHP-Konstante in der `wp-config.php`; der Schlüssel selbst wird nie in der Datenbank gespeichert.

Tabellen- und Feldnamen sind Vorschläge; endgültige SQL-Typen, Indizes, Löschregeln und Migrationen vor Implementierung dokumentieren. Eindeutige externe IDs pro Layer, Indizes für Layer, Status und Bounding-Box-Abfragen. Geometrien gelten im Bereich ±180° Länge / Web-Mercator-Grenzen; Geometrien darüber hinaus werden beim Import abgelehnt. Koordinaten intern WGS84, GeoJSON-Reihenfolge `[longitude, latitude]`. JSON-Eingaben gegen typisierte Schemas validieren. Cache-Invalidierung über `updated_at`.

## 8. Core-Architektur

```text
Core/
  Domain/           Map, Layer, Feature, Geometry, Provider
  Geo/              Coordinate, Bounds, GeometryValidator, DistanceCalculator
  Application/      MapService, LayerService, FeatureService, ImportService
  Import/           GeoJsonParser, GpxParser, CsvParser, Exporter
  Contracts/        Repositories, Geocoder, Clock, Logger
WordPress/
  Bootstrap/        Hook- und Blockregistrierung
  Persistence/      wpdb-Repositories, Migrationen
  Api/              REST-Controller, Berechtigungen
  Integration/      Gutenberg, Shortcode, Consent API
  Providers/        HTTP-Geocoder und Provider-Konfiguration
  Cli/              WP-CLI-Kommandos
```

Domain-Objekte und Status als typisierte Klassen/Enums. Keine Datenbankabfragen im Renderer, keine Businesslogik in REST-Controllern. Netzwerk und Dateisystem über abstrahierte Adapter.

## 9. API und Erweiterbarkeit

REST-Namespace: `sw-maps/v1`. Admin-Endpunkte authentifiziert und mit objektbezogener Capability-Prüfung. Öffentliche Endpunkte (nur für den optionalen AJAX-Modus) geben ausschließlich freigegebene Darstellungsdaten zurück; keine Rohimporte, privaten Felder, Benutzerinformationen oder Zugangsdaten.

| Methode | Route | Zweck |
|---|---|---|
| GET/POST | `/maps` | Karten auflisten/anlegen |
| GET/PATCH/DELETE | `/maps/{id}` | Karte verwalten |
| GET/POST | `/maps/{id}/layers` | Layer der Karte verwalten |
| GET/PATCH/DELETE | `/layers/{id}` | Layer bearbeiten |
| GET/POST | `/layers/{id}/features` | Features paginiert abfragen/anlegen |
| GET/PATCH/DELETE | `/features/{id}` | Feature bearbeiten |
| POST | `/imports` | Import validieren (Vorschau) und synchron ausführen |
| GET | `/public/maps/{id}/features` | Optionaler AJAX-Modus: freigegebene Features, optional bbox/zoom/filter, begrenzte Seitengröße |
| POST | `/geocoding/search` | Adresssuche, nur Admin (Capability-geschützt) |

REST-Argumente, Pagination, maximale Seitengröße, Fehlercodes und Antwortschemata dokumentieren. Provider-Verwaltung und Importvorschau erhalten eigene Endpunkte. Öffentliche Antworten cachebar nach `updated_at`, sofern keine personenbezogenen Inhalte enthalten sind; Statuswechsel und Löschung invalidieren Cache.

Vorgeschlagene PHP-API (Verträge vor Implementierung finalisieren):

```php
swmap_render_map( int $map_id, array $options = array() ): string;
swmap_register_source( string $id, MapSourceInterface $source ): void;
```

WordPress-Hooks mit stabilen Datenverträgen, z. B. `swmap_register_sources`, `swmap_map_published`, `swmap_layer_updated`, `swmap_import_completed`. Events erst nach konsistentem Commit auslösen; Fehler in Drittanbieter-Hooks protokollieren und dürfen abgeschlossene Importe nicht rückwirkend beschädigen. Keine öffentlichen APIs ohne dokumentierte Versionierung.

## 10. Integration anderer SW-Plugins

- SW Maps stellt **nur stabile, dokumentierte Hooks und Datenverträge** bereit (Abschnitt 9). Adapter für SW Gallery, SW ImageOptimizer, SW Sliderman, SW CloudCalendar, SW Postlist und SW Forms entstehen erst, wenn das jeweilige Partner-Plugin sie benötigt und dessen API geprüft ist (Details im BACKLOG).
- Keine direkten Zugriffe auf fremde Plugin-Tabellen, keine Pflicht zur Installation eines gemeinsamen Suite-Core.
- Datenschutzregel für spätere Adapter: keine automatische Veröffentlichung privater GPS-Metadaten (z. B. EXIF).

## 11. Import-Verarbeitung und Skalierung

Import und Export laufen **synchron** im Request; es gibt bis 1.0 keine Job-Queue und keine Hintergrundverarbeitung (Konzept dafür im BACKLOG).

- Der Import wird in einer Datenbanktransaktion bzw. über Staging ausgeführt: Bei Fehlern bleibt der vorhandene Layer intakt.
- Bei Ersetzen vorhandener Layer zuerst Validierung, danach atomarer Wechsel.
- Wiederholter Import mit stabiler externer ID (Upsert) erzeugt keine Duplikate.
- Laufzeit-, Speicher-, Dateigrößen- und Feature-Limits konfigurierbar innerhalb sicherer Grenzen; Überschreitung führt zu einer klaren Ablehnung vor dem Schreiben, nie zu einem stillen Teilimport.
- Kleine Karten vollständig inline; größere Karten viewportbezogen und paginiert über den optionalen AJAX-Modus; Requests beim Verschieben abbrechen oder entprellen.
- Marker-Clustering, sinnvolle Zoomgrenzen, vereinfachte Geometrien (insbesondere für große Tracks) als zusätzliche Darstellungsvarianten; kanonische Geometrie unverändert erhalten.
- Benchmark-Ziele: 100, 1.000 und 10.000 Punktfeatures sowie große Tracks; Messung von Payload, Serverlaufzeit, Speicher und Browser-Interaktion dokumentieren. Keine unbelegten Performance-Garantien.

## 12. Sicherheit und Datenschutz

- Eingaben validieren, Ausgaben kontextbezogen escapen, SQL über `$wpdb->prepare()` bzw. sichere WP-Methoden.
- REST-`permission_callback`, Capability Checks, WordPress-REST-Nonce für Cookie-Authentifizierung; Nonces ersetzen keine Autorisierung.
- Capabilities getrennt für Kartenbearbeitung, Veröffentlichung, Layer, Importe und Provider-/Systemkonfiguration; Zuordnung bei Aktivierung dokumentieren.
- Keine öffentlich abrufbaren Entwürfe, privaten Layer oder privaten Feature-Eigenschaften; öffentliche Ausgabe über explizite Feld-Allowlist.
- Pfadschutz bei Upload, temporären Dateien und Export; MIME und tatsächlichen Inhalt prüfen; keine ausführbaren Dateien oder ungeprüften SVGs.
- Schutz vor XSS in Popup, Tooltip, Icon, URL, Attribution, importierten Eigenschaften, eingebettetem JSON (sicheres Encoding im HTML) und Admin-Vorschau.
- SSRF-Schutz bei serverseitigen HTTP-Aufrufen (Geocoder, spätere Remote-Quellen): nur HTTP(S), geprüfte Zieladressen, Redirects erneut validieren, private/reservierte Netze standardmäßig sperren, explizite Admin-Allowlist für selbst gehostete interne Anbieter mit dokumentiertem Risiko.
- Keine Geheimnisse in Logs, Exporten, Datenbank oder öffentlichen REST-Antworten; Secrets liegen in der `wp-config.php`. Öffentliche Browser-Tokens klar von geheimen Server-Credentials unterscheiden.
- Koordinaten auf endliche Zahlen und zulässige Werte prüfen; Geometriekomplexität und Importressourcen begrenzen; ungültige Polygone melden statt unbemerkt reparieren.
- Externe Quellen nicht ohne administrative Freigabe abrufen. Keine Telemetrie.
- Multisite-Isolation je Blog; keine blogübergreifenden Datenlecks. Aktivierung, Upgrade und Deinstallation testen.

## 13. Lebenszyklus und WP-CLI

Deaktivierung erhält Daten. Deinstallation löscht Daten nur bei zuvor ausdrücklich gesetzter Option; keine fremden Attachments löschen. Exporte vor Löschung ermöglichen. BackupIt-kompatible Sicherung von Datenbanktabellen und Upload-Artefakten prüfen.

Vorgeschlagene Kommandos:

```text
wp sw-maps status
wp sw-maps maps list
wp sw-maps import <file> --format=geojson --layer=<id>
wp sw-maps export <map-id> --format=json --output=<file>
```

Destruktive CLI-Operationen erfordern explizite Bestätigung bzw. dokumentiertes `--yes`; korrekte Exit-Codes und maschinenlesbare Ausgabe unterstützen.

## 14. Tests und Qualitätsanforderungen

- PHPUnit: Koordinaten, Bounds, Distanz, GeoJSON/GPX/CSV-Parser, Validierung, Stilregeln, Importlimits und Idempotenz (Upsert).
- WordPress-Integration: Migrationen, REST-Autorisierung, Shortcodes, Blockrendering, Veröffentlichung, Consent-API-Verhalten, Multisite, Deinstallation.
- Vitest: Stores, API-Clients, Kartenverträge, Editor-Operationen, Filter, Fehlerzustände, i18n-Parität.
- Playwright: Karte erstellen, Marker verschieben, Track importieren, veröffentlichen, Gutenberg einbinden, mehrere Karten, Mobilansicht, Tastatur, externe Requests vor Freigabe. Playwright deckt auch den Renderer-Code im echten Browser ab; Sonderfälle werden bei konkretem Bedarf gelöst.
- Security-Fixtures: XSS-Payloads, XXE, SSRF/Redirects, übergroße Geometrien, ungültige Koordinaten, IDOR und private Layer.
- Test-Tiles und Geocoder lokal mocken; CI darf nicht von öffentlichen OSM-Diensten abhängen.
- >80 % Zeilenabdeckung des produktiven PHP- und JS/TS-Codes jeweils separat; keine künstlichen Ausschlüsse. PHPStan Level 8 ohne Baseline; WPCS im WP-Adapter, PSR-12 im Core.
- Jede funktionale Änderung erhält Tests, Dokumentation und Changelog-Eintrag. Keine Platzhalterimplementierungen als fertige Funktionen deklarieren.

### 14.1 Testumgebungen

Vorlage: PHP- und WordPress-Tests nach SW BackupIt, Browser-/E2E-Tests nach SW Gallery (`tests/e2e-env`, `bin/test-e2e.sh`). Tests laufen **ausschließlich gegen Wegwerf-Umgebungen**, nie gegen die Dev-Site oder deren Datenbank.

| Schicht | Umsetzung | Aufruf (Vorschlag) |
|---|---|---|
| PHP Unit/Integration | PHPUnit 11 ohne WordPress | `composer test` |
| WordPress-Integration | `tests/wp/` als eigenes Composer-Projekt (`wp-phpunit`, WordPress-Core als Dev-Abhängigkeit, eigene PHPUnit-Version laut WP-Testsuite); Plugin per Path-Repository eingebunden, Laden über `muplugins_loaded` | `composer test:wp` |
| Multisite | Gleiche Umgebung mit `phpunit-ms.xml.dist` | `composer test:wp:ms` |
| Datenbank | Private Wegwerf-MariaDB auf eigenem Port (`bin/test-db.sh start|stop|status`), eigene Test-Datenbank, Prefix `wptests_`; in CI ein MariaDB-Service. Tests mit Datenbankbedarf überspringen sich, wenn der Dienst fehlt. | `bin/test-db.sh` |
| Frontend (Vitest) | In `apps/admin` und den Renderer-Paketen; Coverage-Gate mit hartem Skript-Check (>80 %), nicht nur Vitest-Schwellenwert | `npm run test:coverage` |
| Browser (Playwright) | `e2e/` mit eigener `package.json`, läuft gegen ein Wegwerf-WordPress in Docker Compose (`tests/e2e-env/`); zusätzlich ein Mock-Modus ohne WordPress (Admin-App mit simuliertem REST) für schnelle Läufe | `bin/test-e2e.sh [--keep] [--headed] [-- <args>]` |
| Release-Build | Echtes Build-ZIP prüfen (Struktur, Vollständigkeit, Aktivierung) | `tests/release/` |
| Qualität | PHPCS (Core PSR-12, Adapter WPCS), PHPStan Level 8, ESLint, vue-tsc, Coverage-Merge mit hartem Check (PHP und JS/TS getrennt >80 %) | `composer check`, `npm run lint` |

Playwright-Vorgaben (Erfahrung aus SW Gallery unter WSL2/Docker):
- Höchstens **3 Worker** und **2 Retries** (Chromium bricht Requests mit `net::ERR_NETWORK_CHANGED` ab, wenn der Host ein Netzwerkinterface ändert); ein Fehler nach beiden Wiederholungen gilt als echt.
- Netzwerkprüfungen (Seite ohne Karte lädt kein Bundle, keine externen Requests vor Consent, keine Google-Ressourcen bei OSM) laufen hier, mit lokal gemockten Tile-Servern.
- Barrierefreiheit: axe über alle Admin-Routen und die Frontend-Karte inklusive Standortliste, Hell/Dunkel; Befunde lassen den Test fehlschlagen.
- Performance-Budgets (komprimierte Bundlegrößen, geladene Assets) werden im Build bzw. in E2E geprüft.

Voraussetzungen auf der Entwicklungsmaschine: PHP >= 8.2 mit Sodium, Zip, mysqli, gd; Composer 2; Node.js laut `.nvmrc`; MariaDB (lokal oder Docker); Docker mit Compose-Plugin; Chromium-Systembibliotheken (`npx playwright install-deps chromium`). CI-Browserjobs nutzen Ubuntu 24.04. Tests laufen in CI auf Pull Requests; der Release-Workflow führt keine Testsuite aus.

## 15. Build, Release und Dokumentation

- Build-/Release-Struktur, Makefile-Ziele, GitHub Actions, Dependency-Prefixing und Qualitäts-Gates aus SW BackupIt übernehmen und anpassen.
- Release-ZIP enthält gebaute Admin-/Frontend-/Block-Assets und Runtime-Abhängigkeiten; kein Node.js oder Composer auf Zielhosting nötig.
- Reproduzierbarer Build mit Lockfiles; Dependency-Audits und Lizenzhinweise.
- PHP-gettext-Kataloge in `languages/`, SPA-Kataloge in `apps/admin/src/i18n/`, Frontend-/Block-Übersetzungen nach WP-Konvention. Deutsch und Englisch initial, weitere Sprachen gemäß BackupIt-Konvention; Parität automatisiert prüfen.
- **Vertriebsweg: bis auf Weiteres ausschließlich GitHub** (Release-ZIP über GitHub Releases). Konzept für Updates (Update-Mechanismus) wird später separat festgelegt und ist nicht Teil dieses Plans.
- Dokumentation in `docs/` und `pages/`: Installation, Karteneditor, Provider, Datenschutz, Importformate, API, Entwicklung und Fehlerbehebung.
- `pages/Changelog.md` bei jeder Änderung unter Unreleased (Feature, Bugs, Other topics) aktualisieren.
- `AGENTS.md`, `CODE-STYLE.md`, `TODO.md`, `DONE.md` nach Referenzstruktur anlegen; Tests und Coverage vor Abschluss ausführen und Ergebnis nennen.

## 16. Implementierungsphasen

| Phase | Lieferumfang |
|---|---|
| 0 – Foundation | Monorepo, Composer, Vue/Quasar, CI, Schema-Migrationen, Tests, i18n, Kartenverträge, OpenLayers-Prototyp |
| 1 – MVP | Kartenverwaltung, manuelle Marker, OSM-Standard und weitere Raster-Provider, Popup, Gutenberg, Shortcode, responsiver OpenLayers-Viewer mit Inline-JSON, WP Consent API, Datenschutz-Platzhalter |
| 2 – Geodaten | Layer, Kategorien, Filter, Clustering, Linien/Polygone, GeoJSON/GPX/CSV (synchroner Import mit Limits), PMTiles-Provider, optionaler AJAX-Modus |
| 3 – Betrieb | Export, WP-CLI, Geocoding (Admin), größere Datenmengen, Diagnose, Accessibility- und Security-Tests |
| 4 – Vorbereitung 1.0 | **Migration aus anderen Karten-Plugins**, Integrationshooks dokumentieren und stabilisieren, Dokumentation, Hardening |

Alles danach (u. a. Google-Adapter, Routing, Standortportal, Community, weitere Provider) steht im [BACKLOG](BACKLOG.md). Phasen sind Vorschläge, keine Verpflichtung.

MVP soll klein, installierbar und vollständig getestet sein.

## 17. Akzeptanzkriterien für den MVP

1. Administrator kann ohne externe API-Schlüssel eine Karte mit vorkonfiguriertem OpenStreetMap anlegen und veröffentlichen; bei selbst gehostetem Tile-Server ohne externe Netzrequests darstellen.
2. Marker können im Editor angelegt, verschoben, gespeichert und mit sicheren Popups angezeigt werden.
3. Gutenberg und Shortcode zeigen dieselbe veröffentlichte Karte; mehrere Karten pro Seite funktionieren unabhängig; die Karte funktioniert ohne zusätzlichen Datenrequest.
4. Attribution und Kartenanbieter-Einstellungen bleiben sichtbar und konfigurierbar.
5. Externe Kartenrequests respektieren die WP Consent API und können per Click-to-load blockiert werden; Standortliste bleibt nutzbar.
6. Entwürfe und nicht freigegebene Inhalte sind über öffentliche REST-Endpunkte nicht abrufbar.
7. Plugin funktioniert ohne andere SW-Plugins; Deaktivierung beschädigt keine fremden Inhalte.
8. Release-ZIP ist auf einer unterstützten WordPress-Installation ohne Build-Tools installierbar.
9. Qualitäts-Gates einschließlich PHPStan Level 8 und >80 % Coverage je Sprache sind nachweislich erfüllt.

## 18. Renderer- und Provider-Architektur (verbindlich)

Renderer, Basiskartenprovider und Geocoder sind getrennte Verträge. GeoJSON/WGS84 bleibt die kanonische geografische Darstellung.

- **OpenLayers ist der einzige Renderer bis 1.0** (Viewer und Editor). Der Renderer liegt in `packages/renderer-openlayers` inklusive Frontend-Bundle.
- Der Renderer-Vertrag bleibt minimal: mount, update, setView, getView, fitBounds, on, destroy. Er wird erst erweitert, wenn ein zweiter Renderer konkret umgesetzt wird.
- **Google Maps** ist ein optionaler, vollständig getrennter Adapter (`packages/renderer-google`) nach 1.0: offizielle Maps JavaScript API, Betreiber-Key mit Domain- und API-Restriktionen, Script erst nach konfigurierter Freigabe und Consent. Keine unerlaubte Google-Tile-Nutzung, Abrechnung und Nutzungsbedingungen dokumentieren. Kein stiller Wechsel zu einem kostenpflichtigen Anbieter.
- Getrennte Vite-Chunks einschließlich CSS; keine Admin-Abhängigkeiten im Frontend-Bundle. Loader pro Seite deduplizieren, Fehler isolieren, Ressourcen bei destroy freigeben.
- Presets für OSM, Protomaps/PMTiles und eigene Server; MapTiler, Stadia u. ä. nur nach Prüfung aktueller Nutzungsbedingungen, Key-Anforderungen und Lizenz. Provider nicht mit Renderern gleichsetzen.
- Browser-Netzwerktests (Playwright): Seite ohne Karte lädt kein Kartenbundle; Seite mit OSM lädt keine Google-Ressourcen; Bundlegrößen komprimiert im CI messen und Regressionen begrenzen.

## 19. Bewusste Abgrenzung und offene Entscheidungen

- Kein eigener globaler Tile-Server, keine eigene Geodatenbasis und keine Routing-Engine.
- Kein vollständiges GIS und kein Ersatz für QGIS oder OpenStreetMap-Editoren.
- SEO-Funktionen (strukturierte Daten, Standortseiten) sind nicht Aufgabe dieses Plugins.
- Keine Revisionen und kein Locking von Karten; kein Batch-Speichern im Editor.
- Keine Kreise als eigener Feature-Typ.
- Update-Mechanismus: offen, wird später separat konzipiert.
- Keine obligatorische Browser-Geolocation.
- Keine beliebigen benutzerdefinierten Skripte in Karten oder Popups.
- Konkrete Bibliotheken (OpenLayers-Version, PMTiles-Anbindung) vor Implementierung auf Lizenz, Wartung und Bundlegröße prüfen.
- Providerbedingungen vor Veröffentlichung der jeweiligen Presets verifizieren: https://operations.osmfoundation.org/policies/tiles/ und https://operations.osmfoundation.org/policies/nominatim/.
- GeoJSON-Referenz: https://www.rfc-editor.org/rfc/rfc7946.
- BackupIt-Buildkonventionen vor Implementierung prüfen.
- Die hier definierten Tabellen, REST-Routen und Präfixe sind Architekturvorschläge, keine bereits implementierten Schnittstellen.
