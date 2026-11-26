# ADR 0005: PMTiles nur als Raster-Archiv

Status: angenommen (M2-14).

## Kontext
Der Plan verlangt eine selbst hostbare Quelle ohne Tile-Server („eine Datei“). Die Bibliothek `pmtiles` (BSD-3-Clause, aktiv gepflegt, ≈ 5,5 KB gzip) liest Archive per HTTP-Range-Request. Das Bundle-Budget für den Frontend-Viewer (`bin/bundle-budget.json`) liegt bei 115 KB gzip; der Viewer belegt mit PMTiles-Anbindung 112,4 KB.

## Entscheidung
- Typ `pmtiles` unterstützt **Raster-Archive** (PNG, JPEG, WebP, AVIF) über eine `ol`-XYZ-Quelle mit eigener `tileLoadFunction`; die Anbindung ist kleiner als der Aufwand einer zusätzlichen Fremdbibliothek (`ol-pmtiles`).
- **Vektor-Archive (MVT)** werden nicht gerendert: Sie bräuchten `ol/layer/VectorTile`, einen MVT-Parser und Protomaps-Stile (≈ 50 KB gzip oder mehr) und würden das Budget sprengen. Der Viewer meldet ein Vektor-Archiv einmalig als Fehler (Fehlermeldung im Viewer, kein Absturz).
- Der Verbindungstest liest die ersten 8 Byte (Magic `PMTiles`, Version 3) per Range-Request; er folgt keinen privaten Netzen (`wp_safe_remote_get`).
- Datenschutz wie bei XYZ: Das Archiv-Host wird vor der Aktivierung als externer Request angezeigt; Consent-Sperre gilt unverändert.

## Folgen
- Der Server, der die Datei ausliefert, muss Range-Requests und CORS erlauben.
- Vektor-PMTiles bleiben ein Kandidat für einen eigenen, nachladbaren Chunk (siehe `docs/BACKLOG.md`), sobald das Budget oder ein zweiter Renderer es zulässt.
