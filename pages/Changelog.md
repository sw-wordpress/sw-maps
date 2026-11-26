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
- Marker-Clustering im Viewer (pro Karte abschaltbar): Gruppen mit Anzahl, Klick zoomt auf die enthaltenen Marker; Linien und Flächen werden nicht gruppiert.
- Kategorien für Standorte (REST `/categories`): Standardfarbe und -symbol pro Kategorie, Filter-Legende und Suche im Viewer, die Liste und Karte gemeinsam filtern.
- Stile im Editor: Linienstärke, Strichmuster, Deckkraft und Flächenfüllung; Kategorien zuweisen und verwalten; Kaskade Layer → Kategorie → Standort für Farbe und Symbol.
- Layer: Reihenfolge ändern (bestimmt die Zeichenreihenfolge), Layer-Stil (Farbe, Stärke, Muster, Deckkraft) und Zoombegrenzung pro Layer; Basiskarte (Anbieter) und Overlays (Layer) sind getrennt.
- Optionaler AJAX-Modus pro Karte (öffentlicher Endpunkt `/public/maps/{id}/features` mit bbox, Zoom und Kategoriefilter, begrenzte Seitengröße, entprellt und abbrechbar); große Linien und Flächen werden für die Ausgabe vereinfacht (gespeicherte Geometrie unverändert; Filter `swmap_simplify_min_points`, `swmap_simplify_tolerance`).
- PMTiles-Anbieter für Raster-Archive (Verbindungstest, externer Host wird vor der Aktivierung angezeigt); Zusatzfelder (Bezeichnung/Wert) für Standorte, auch aus CSV-Spalten; Streckenlänge (Haversine) im Editor.
- Stilregeln pro Layer (nach Kategorie), Multi-Geometrien per „Teil hinzufügen“, Hover-Tooltip im Viewer.
- Gruppen, die sich nicht durch Zoomen trennen lassen, öffnen eine Auswahlliste der Standorte; REST liefert `length_m` für Linien.
- Export: GeoJSON, CSV (Punkte) und versionierte Plugin-Datei (ohne Zugangsdaten und Medien) je Karte oder für alle Karten; Plugin-Datei importieren (neue Entwurfskarten, Kategorien per Slug).
- SSRF-Schutz für serverseitige Anfragen (Verbindungstests): nur http(s), private und reservierte Netze gesperrt (auch per Redirect), Admin-Allowlist `allowed_hosts` in den Einstellungen (REST).
- Geocoding (Nominatim, nur Admin/Editor per Aktion): Koordinaten zur Adresse und Adresse zur Position mit Bestätigung vor der Übernahme; Server, Kontakt und Allowlist in den Einstellungen, Schlüssel nur aus `wp-config.php`.
- WP-CLI: `wp sw-maps status`, `maps list`, `import` und `export` (Exit-Codes, Rückfragen, JSON-Ausgabe), siehe `docs/cli.md`.
- Diagnose im Systemstatus: Schemazustand, wirksame Grenzen und Erreichbarkeit der aktivierten Anbieter (auf Knopfdruck).
- Löschwarnung mit Export-Hinweis bei „Daten beim Deinstallieren löschen“.
- Barrierefreiheit: Kontrast des Sprunglinks im Dunkelmodus und der Zusatzzeilen, ARIA-Rolle der Standortlisten; automatische axe-Prüfung aller Admin-Routen und der Frontend-Karte (hell/dunkel).
- Große Tracks werden deutlich schneller für die Ausgabe vereinfacht; die Suche im Viewer ist entprellt.

### Bugs

- Gleichzeitiges Anlegen von Karten mit gleichem Titel führt nicht mehr zu einem Serverfehler (Slug-Konflikt wird wiederholt).

### Other topics

- Sicherheits-Fixtures (übergroße/ungültige Geometrien, feindliche Importdateien, Pfadschutz) und Lebenszyklus-Tests (BackupIt-Kompatibilität); `docs/lifecycle.md`.
- Repository-Grundgerüst nach SW-BackupIt-Vorbild: Composer (PSR-4, Mozart-Konfiguration), PHPStan Level 8, PHPCS (PSR-12/WPCS), PHPUnit 11, WordPress-Integrationstests (Einzelsite/Multisite), Vitest mit strikter Coverage-Prüfung (> 80 %), Playwright (WordPress in Docker, Mock-Modus), Makefile, Release-ZIP mit Verifikation, Bundle-Budget, GitHub Actions, SonarQube-Konfiguration.
- Dokumentation: Plan, ADRs, Datenbankschema, REST-Verträge, Hooks, MVP-Abnahme, Benutzerhandbuch.
