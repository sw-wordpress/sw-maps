# Anbieter und Datenschutz

## Anbieter
Unter *Anbieter* werden Rasterkacheln (XYZ) konfiguriert: URL mit `{z}/{x}/{y}`, Quellenangabe (Text, Links als `[Label](https://…)`), Zoomgrenzen, Kachelgröße, optionaler öffentlicher Schlüssel (`{key}` in der URL). Geheime Schlüssel gehören als Konstante in die `wp-config.php`; im Anbieter steht nur der **Name** der Konstante (muss mit `SWMAP_` beginnen, z. B. `SWMAP_TILES_KEY`; Kernkonstanten wie `DB_PASSWORD` sind nicht erlaubt). Der Schlüssel selbst wird nie gespeichert, exportiert oder an den Browser gesendet.

Vor dem Aktivieren zeigt das Plugin, welche Server die Browser der Besucher kontaktieren. *Verbindung testen* ruft serverseitig eine Kachel ab.

Die öffentlichen OpenStreetMap-Kachelserver sind keine unbegrenzte Hosting-Infrastruktur: [Nutzungsrichtlinie](https://operations.osmfoundation.org/policies/tiles/) beachten oder einen eigenen Kachelserver eintragen.

PMTiles: Typ *PMTiles-Archiv (Raster)* liest eine `.pmtiles`-Datei per Range-Request von einem eigenen Server (Range und CORS müssen erlaubt sein); Vektor-Archive werden nicht dargestellt ([ADR 0005](../docs/adr/0005-pmtiles-raster.md)). Der Host wird vor der Aktivierung als externer Request angezeigt.

## Geocoding
Im Standortformular suchen *Koordinaten finden* und *Adresse finden* über Nominatim. Es gibt keine Autovervollständigung: Eine Anfrage entsteht nur nach Klick, und das Ergebnis wird erst nach Bestätigung übernommen. Der Text bzw. die Position verlässt dabei den Server in Richtung Geocoding-Dienst (Einstellungen → Geocoding; der öffentliche Server verlangt einen Kontakt, der im User-Agent steht, und erlaubt eine Anfrage pro Sekunde). Ein eigener Server in einem privaten Netz muss unter „Erlaubte Hosts“ eingetragen werden (SSRF-Schutz). Ein Schlüssel gehört in `wp-config.php` (`define( 'SWMAP_GEOCODER_KEY', '…' );`), nie in die Einstellungen.

## Einwilligung
- Ist das Plugin **WP Consent API** aktiv, lädt die Karte Kacheln erst nach Einwilligung in der Kategorie `marketing` (Filter `swmap_consent_category`). Ohne Einwilligung zeigt die Karte einen Hinweis; die Standortliste bleibt nutzbar. Fehlt das Consent-Skript, wird nichts geladen.
- **Click-to-load** (Einstellung pro Karte, Standard in *Einstellungen*): Platzhalter ohne externe Anfrage, bis der Besucher „Karte laden“ wählt. Click-to-load ersetzt keine fehlende Einwilligung.
- Ein Datenschutztext-Vorschlag steht im WordPress-Datenschutz-Hilfetext (*Einstellungen → Datenschutz → Hilfe zum Datenschutztext*).

Keine pauschale DSGVO-Zusicherung: Konfiguration und Texte sind zu prüfen.
