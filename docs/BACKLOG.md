# SW Maps – BACKLOG.md

Ideen und Erweiterungen **nach 1.0**. Nichts davon ist Ziel oder Voraussetzung für die Releases bis 1.0 (siehe [PLAN.md](PLAN.md)). Keine künstlichen Mengenlimits, Lizenzstufen oder kostenpflichtigen Plugin-Funktionen; Kosten externer Dienste sind davon unabhängig. Vor jeder Umsetzung Schema, API und Tests neu entwerfen.

## Reihenfolge-Vorschlag (ehemals Phase 5–8)

| Block | Inhalt |
|---|---|
| Provider | Google-Maps-Adapter (`packages/renderer-google`), Vektortiles, WMS/WMTS, dynamische Datenquellen |
| Standortportal | Store Locator, Custom Fields, Listings, Popup- und Designvorlagen, Bildkarten |
| Community | Besucher-Marker, Moderation, Bewertungen |
| Optional | Live-Daten, datensparsame Kartenstatistik, Höhenprofile |
| Routing | siehe 4 |

## 0. Job-Queue für große Importe

Erst bei realem Bedarf (Importe, die die synchronen Limits sprengen, große Exporte, Synchronisationen):

- Persistente Queue nach BackupIt-Prinzipien (`swmap_jobs`: Zustände queued/running/completed/failed/cancelled, Leases, Checkpoints, Retry mit Backoff, Wiederaufnahme), WP-Cron als Standard, System-Cron und WP-CLI optional.
- Idempotente Import-Chunks, kooperativer Abbruch, Fortschrittsanzeige.
- Routen `/jobs/{id}` und `/jobs/{id}/cancel`, WP-CLI `jobs list` und `jobs run`, Systemstatus-Ansicht für Cron und Jobs.

## 0.1 Weitere gestrichene Punkte

- Kreise (Mittelpunkt und Radius) als Feature-Typ.
- Batch-Speichern im Editor.

## 1. Wiederverwendbare Layer

- Karten dürfen dieselben Layer referenzieren (Tabelle `map_layers`, ggf. Overrides pro Karte).
- Änderungen müssen als global wirksam erkennbar sein; Löschen referenzierter Layer verhindern oder explizite Auflösung verlangen.

## 2. Standortverzeichnis und Store Locator

- Adress- und Koordinatensuche für Besucher, optional explizit angeforderter Browserstandort, Radius in km/mi, Entfernungssortierung, Trefferliste. Voraussetzung ist eine öffentliche Geocoding-Lösung inklusive Datenschutz- und Missbrauchsbetrachtung (Geocoding ist bis 1.0 nur im Admin).
- Geodätische Distanz, Bounding-Box-Vorfilterung und exakte Radiusprüfung; serverseitige Pagination und Filter.
- Hierarchische Kategorien, Mehrfachauswahl, Textsuche und typisierte Custom Fields; Filter kombinierbar, Reset, leere Trefferzustände, optional erst nach Suche sichtbare Marker.
- Frei platzierbare Suchmaske, Karte und Trefferliste; Liste, Tabelle, Grid, Karussell; synchronisierte Auswahl und Zoom.
- Custom-Field-Schema mit Text, Zahl, Datum, Boolean, Auswahl, URL, Medienreferenz; Validierung, Importzuordnung, öffentliche/private Sichtbarkeit, indexierbare Filterwerte.

## 3. Darstellung und Design

- Wiederverwendbare Designs, Marker-Icons aus lokaler Bibliothek oder Mediathek, sichere Animationen, Kategorien-Stile, Feature-Overrides.
- Popup-Templates mit sicheren typisierten Platzhaltern, Bildern, Galerien, Links, Öffnungszeiten, Custom Fields. Video/Embeds nur mit Freigabe.
- Marker mit identischen Koordinaten per Spiderfying oder zugänglicher Auswahlliste erreichbar machen.
- Rechtecke, gewichtete Heatmap-Daten, optionale Heatmap aus Markerfeldern.
- Bildkarten (Gebäudepläne, Veranstaltungen, fiktive Welten) mit explizitem lokalem Koordinatenraum, nie als vorgetäuschte GPS-Koordinaten; geografisch verankerte Image-Overlays mit Transparenz.
- KML-Import mit sicheren Parsern, keine automatisch nachgeladenen NetworkLinks; versionierter Konfigurations- und Designexport.
- Höhenprofile; importierte Höhenwerte nicht als verifizierte Geländehöhen ausgeben.

## 4. Routing (nach 1.0)

- RoutingProvider-Vertrag getrennt vom Renderer: OSRM, Valhalla, GraphHopper oder Google als mögliche Adapter nach Prüfung von API und Nutzungsbedingungen.
- Start/Ziel aus Adresse, Marker oder optionalem Browserstandort; Zwischenstopps, Verkehrsmittel nach Providerfähigkeit, Distanz, Dauer, Routengeometrie, Weganweisungen.
- Keine eigene Routing-Engine; verständliche Fehler, externer Routenlink als Fallback. Standortdaten nur für die angeforderte Route verarbeiten.
- Navigation und Live-Ortung nur als explizit aktiviertes, autorisiertes Modul.

## 5. Besucherbeiträge und Bewertungen

- Pro Karte aktivierbares Formular für Marker-Vorschläge mit Standort, Text, Kategorie, Bild, URL und freigegebenen Custom Fields; Gast- oder Login-Pflicht konfigurierbar.
- Standardmäßig Moderation (pending/approved/rejected), Benachrichtigungen, Ablehnungsgrund, sichere Upload-Verarbeitung. Keine Veröffentlichung privater Kontaktdaten.
- Rate Limits, Spam-Schutz, Objektberechtigungen, Upload-Limits, MIME-Prüfung, Quoten. Datenschutzinformationen sowie Lösch-/Exportprozesse (WP Privacy Exporter/Eraser).
- Optionale Sternebewertungen mit Moderation, Missbrauchsschutz, aggregiertem Ergebnis und nachvollziehbarer Berechnung.

## 6. Dynamische Daten und Integrationen

- WordPress Posts/CPTs, ACF-Felder, WooCommerce-Standorte, mehrsprachige Inhalte und externe JSON/GeoJSON-Daten als optionale Adapter: Feldzuordnung, stabile externe IDs, Synchronisationsintervalle, Fehlerstatus, last-known-good-Daten.
- Remote-Fetches mit SSRF-Schutz, Host-Allowlist, Redirect-Prüfung, Größen-/Zeitlimits und geschützten Credentials; keine beliebigen URLs aus öffentlichen Requests.
- Live-Layer über autorisierte Datenquellen mit Zeitstempel, Aktualisierungsintervall, Ablauf und sichtbarem stale-Status; keine heimliche Ortung. Rechtsgrundlage und Aufbewahrung vorab definieren.
- Optionale aggregierte Kartenstatistik (Kartenaufrufe, Feature-Interaktionen, Standortsuche); standardmäßig aus, keine Speicherung exakter Positionen, IPs oder Suchadressen, definierte Löschfristen, keine externe Telemetrie.
- Optionale Page-Builder-Adapter.

## 7. Suite-Adapter

Adapter entstehen erst, wenn das Partner-Plugin sie braucht und dessen API geprüft ist; SW Maps liefert dafür nur Hooks (PLAN, Abschnitt 10).

- **SW Gallery:** Marker oder Standortseiten mit verknüpften Galerien; Geofotos optional aus ausdrücklich freigegebenen EXIF-GPS-Daten.
- **SW ImageOptimizer:** responsive Varianten für Markerbilder und Popups; Fallback auf WordPress-Bildgrößen.
- **SW Sliderman:** Slider als Inhalt einer Standortdetailansicht ohne dynamisch injizierte Skripte.
- **SW CloudCalendar:** veröffentlichte Veranstaltungen mit expliziten Koordinaten als dynamischer Layer; Zeitfilter, Link zum Termin.
- **SW Postlist:** gegenseitige Filterung von Beitragsliste und Karte.
- **SW Forms:** Standortvorschläge als moderierte Entwürfe.

## 8. Weitere Provider und Renderer

- Google-Maps-Adapter: Details in PLAN, Abschnitt 18; Renderer-Vertrag dann um Capabilities erweitern.
- Vektortiles, WMS/WMTS, MapTiler/Stadia-Presets nach Prüfung der Nutzungsbedingungen.

## Zusätzliche Persistenz und Akzeptanz (bei Umsetzung)

Schema-Erweiterungen für Felddefinitionen/-werte, Designvorlagen, Einreichungen, Bewertungen, synchronisierte Quellen und Metriken vorab entwerfen. Öffentliche APIs liefern ausschließlich veröffentlichte, ausdrücklich freigegebene Felder. Jedes Modul erhält Berechtigungs-, REST-, Migrations- und UI-Tests nach den Qualitätsvorgaben des PLAN.
