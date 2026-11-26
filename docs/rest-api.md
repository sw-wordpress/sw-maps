# REST-API `sw-maps/v1` (M0-06)

Authentifizierung: Cookie + REST-Nonce (`X-WP-Nonce`). Alle Endpunkte haben einen `permission_callback`; objektbezogene Prüfung im Service. Öffentliche Endpunkte (`/public/...`, M2) liefern nur freigegebene Felder.

## Konventionen
- JSON, UTF-8. IDs sind Ganzzahlen. Zeiten ISO 8601 UTC.
- Pagination: `page` (>= 1, Standard 1), `per_page` (1..100, Standard 20; Features 1..500, Standard 100). Header `X-WP-Total`, `X-WP-TotalPages`.
- Fehler: `{ "code": "swmap_…", "message": "…", "data": { "status": 4xx, "details"?: [...] } }`.

| Code | HTTP | Bedeutung |
|---|---|---|
| `swmap_invalid_param` | 400 | Validierung fehlgeschlagen (`details` = Liste Feld/Meldung) |
| `swmap_invalid_geometry` | 400 | Ungültige Koordinaten/Geometrie |
| `rest_forbidden` | 401/403 | keine Berechtigung |
| `swmap_not_found` | 404 | Objekt nicht gefunden |
| `swmap_conflict` | 409 | z. B. Slug bereits vergeben |
| `swmap_limit_exceeded` | 413 | Limit überschritten |

## Endpunkte (M1)
| Methode | Route | Capability | Antwort |
|---|---|---|---|
| GET | `/maps` (`search`, `status`, `page`, `per_page`) | `swmap_edit_maps` | Liste `Map` |
| POST | `/maps` (`title`, `description?`, `slug?`) | `swmap_edit_maps` | `Map` (201) |
| GET/PATCH/DELETE | `/maps/{id}` | lesen/ändern: `swmap_edit_maps`; Status `published` setzen: `swmap_publish_maps` | `Map` / 204 |
| POST | `/maps/{id}/duplicate` | `swmap_edit_maps` | `Map` (201) |
| GET/POST | `/maps/{id}/layers` | `swmap_manage_layers` | Liste/`Layer` |
| GET/PATCH/DELETE | `/layers/{id}` | `swmap_manage_layers` | `Layer` / 204 |
| GET/POST | `/layers/{id}/features` (`page`, `per_page`) | `swmap_edit_maps` | Liste/`Feature` |
| GET/PATCH/DELETE | `/features/{id}` | `swmap_edit_maps` | `Feature` / 204 |
| GET | `/providers` | `swmap_edit_maps` (Karteneditoren erhalten nur `id, type, name, public_config, enabled`; mit `swmap_manage_system` zusätzlich `secret_constant`, `secret_defined`, `external_requests`) | Liste `Provider` |
| POST | `/providers`, GET/PATCH/DELETE `/providers/{id}` | `swmap_manage_system` | `Provider` |
| POST | `/providers/{id}/test` | `swmap_manage_system` | `{ ok, status, message }` |
| GET/PATCH | `/settings` | `swmap_manage_system` | `Settings` |
| GET | `/status` | `swmap_manage_system` | Systemstatus |

## Import (M2)
`POST /imports` (`swmap_manage_imports` plus Bearbeitungsrecht an der Karte `map_id`). Body: `map_id`, `format` (`geojson`|`gpx`|`csv`), `content` (Dateitext), `mode` (`new_layer` Standard, `append`, `upsert`, `replace`), `layer_id` (außer `new_layer`), `layer_title?`, `mapping?` (Ziel → Quellspalte; Ziele `title`, `description`, `address`, `link`, `external_id`), `options?` (CSV: `delimiter`, `decimal`, `lon_column`, `lat_column`), `dry_run?`, `skip_invalid?`, `confirm_replace?`.
- `dry_run` validiert und liefert den Bericht (Vorschau, Spalten, Vorschlag der Feldzuordnung, Fehler- und Warnliste) ohne zu schreiben.
- Ausführen schreibt in einer Transaktion. Ungültige Einträge lehnen den Import ab, außer `skip_invalid` ist gesetzt; `replace` verlangt `confirm_replace`. Der Upsert überschreibt nur Felder, die die Datei liefert (Bild und Symbol bleiben).
- Dateiweite Ablehnung (Größe, Anzahl Features/Koordinaten, Verschachtelung, DOCTYPE/Entities, kaputtes Format): HTTP 422, Code `swmap_import_rejected`, `data.reason` (`too_large`, `too_many_features`, `too_many_coordinates`, `too_deep`, `malformed`, `forbidden`, `unsupported`, `invalid_option`). Vor der Ablehnung wird nichts geschrieben.
- Bericht: `mode`, `layer_id`, `total`, `created`, `updated`, `skipped`, `error_count`, `errors[]`, `warnings[]`, `columns`, `mapping`, `media_relink_hint`, `imported`, `preview[]`. Medien (Bilder, Symbole) werden nicht importiert und müssen neu verknüpft werden.
- Parser: GeoJSON (GeometryCollections werden zerlegt), GPX 1.0/1.1 (Wegpunkte, Routen, Tracks mit Segmenten als MultiLineString), CSV mit Kopfzeile. XML ohne Entities, DTD und Netzwerkzugriff. Limits: 5 MiB, `max_features_per_layer` Features, 200 000 Koordinaten, Tiefe 32.

## Schemas
`Map`: `id, slug, title, description, status, viewport{center[lon,lat], zoom, minZoom, maxZoom, bounds?, autoBounds}, settings{height, aspectRatio?, scrollZoom, touch, drag, fullscreen, scale, provider_id}, layer_count, feature_count, created_at, updated_at`.
`Layer`: `id, map_id, title, type, status, sort_order, visible, style, created_at, updated_at`.
`Feature`: `id, layer_id, external_id?, geometry{type, coordinates}, properties{title, description, address, image_id, link, icon}, style, updated_at`.
`Provider`: `id, type, name, public_config{urlTemplate, attribution, minZoom, maxZoom, tileSize}, secret_constant?, secret_defined(bool), enabled`.
Die TypeScript-Verträge stehen in `packages/map-contracts`; sie sind die maßgebliche Quelle.
