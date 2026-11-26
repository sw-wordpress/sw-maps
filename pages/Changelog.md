# Changelog

## Unreleased

### Feature

- Karten, Layer und Standorte (Marker, Linien, Flächen) mit eigenen Tabellen, versionierten und multisitefähigen Schema-Migrationen, Löschregeln und Capabilities je Aufgabe.
- REST-API `sw-maps/v1` für Karten, Layer, Standorte, Anbieter, Einstellungen und Systemstatus (objektbezogene Prüfung, Pagination, stabile Fehlercodes).
- Karteneditor (Vue 3, Quasar, OpenLayers): Marker per Klick, Ziehen und Koordinaten, Linien/Flächen zeichnen, Layer, Startansicht, Vorschau Desktop/Mobil, explizites Speichern, Undo/Redo, Warnung bei ungespeicherten Änderungen, Medien-Symbole und -Bilder.
- Einbindung per Shortcode `[sw_map]`, PHP-API `swmap_render_map()` und dynamischem Block `sw-maps/map` (Editor-Vorschau); Daten als sicher kodiertes Inline-JSON, serverseitig gerenderte Standortliste.
- Responsiver OpenLayers-Viewer: Popups nur aus bereinigten Feldern, Zoom/Vollbild/Maßstab, dauerhaft sichtbare Quellenangabe, mehrere Karten pro Seite, Lazy-Initialisierung, Fehlerisolation, reduzierte Bewegung.
- OpenStreetMap vorkonfiguriert, weitere XYZ-Anbieter, Geheimnisse nur als `wp-config`-Konstante, Anzeige externer Requests vor Aktivierung, Verbindungstest.
- Datenschutz: WP Consent API (Kacheln erst nach Einwilligung), Click-to-load, Datenschutztext-Vorschlag; Entwürfe und nicht veröffentlichte Layer werden nie öffentlich ausgeliefert.
- Deutsche und englische Oberfläche (gettext, SPA-Kataloge, Block-Editor-Katalog).

- Oberfläche an SW BackupIt angelehnt: Navigationsleiste mit Einklappen und Dunkelmodus, Seitenköpfe, Karten, Kennzahlen-Kacheln, Statusanzeigen, responsiver Karteneditor.
- Import-Assistent im Karteneditor und REST `POST /imports`: GeoJSON, GPX und CSV mit Vorschau, Feldzuordnung, Validierungsbericht und den Modi neuer Layer, Ergänzen, Upsert nach externer ID und bestätigtes Ersetzen; sichere Parser mit Größen- und Anzahlgrenzen, XML ohne Entities/DTD; Streckenlänge (Haversine) und vereinfachte Darstellungsgeometrien im Core.

### Bugs

- Gleichzeitiges Anlegen von Karten mit gleichem Titel führt nicht mehr zu einem Serverfehler (Slug-Konflikt wird wiederholt).

### Other topics

- Repository-Grundgerüst nach SW-BackupIt-Vorbild: Composer (PSR-4, Mozart-Konfiguration), PHPStan Level 8, PHPCS (PSR-12/WPCS), PHPUnit 11, WordPress-Integrationstests (Einzelsite/Multisite), Vitest mit strikter Coverage-Prüfung (> 80 %), Playwright (WordPress in Docker, Mock-Modus), Makefile, Release-ZIP mit Verifikation, Bundle-Budget, GitHub Actions, SonarQube-Konfiguration.
- Dokumentation: Plan, ADRs, Datenbankschema, REST-Verträge, Hooks, MVP-Abnahme, Benutzerhandbuch.
