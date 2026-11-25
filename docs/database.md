# Datenbankschema (M0-04)

Schema-Version: Option `swmap_db_version` (Ganzzahl), Migrationen in `packages/wordpress/src/Persistence/Migrations`. Pro Blog (`$wpdb->prefix`), bei Multisite je Blog bei Aktivierung bzw. `wp_initialize_site`. Zeichensatz `$wpdb->get_charset_collate()`, Engine InnoDB. Zeitstempel UTC (`DATETIME`).

## Tabellen
| Tabelle | Spalte | Typ | Hinweis |
|---|---|---|---|
| `swmap_maps` | id | BIGINT UNSIGNED AI PK | |
| | slug | VARCHAR(190) NOT NULL | UNIQUE (`slug`), leer nicht erlaubt (Default = `map-{id}` im Service) |
| | title | VARCHAR(255) NOT NULL | |
| | description | TEXT NOT NULL | |
| | status | VARCHAR(20) NOT NULL DEFAULT 'draft' | `draft`/`published`, Index `status` |
| | viewport_json | LONGTEXT NOT NULL | Startansicht, Zoomgrenzen, Bounds |
| | settings_json | LONGTEXT NOT NULL | Höhe, Controls, Interaktion, Provider-Ref |
| | created_by | BIGINT UNSIGNED NOT NULL | WP-User-ID |
| | created_at, updated_at | DATETIME NOT NULL | |
| `swmap_layers` | id | BIGINT UNSIGNED AI PK | |
| | map_id | BIGINT UNSIGNED NOT NULL | Index `(map_id, sort_order)` |
| | title | VARCHAR(255) NOT NULL | |
| | type | VARCHAR(20) NOT NULL DEFAULT 'features' | `features` (M1), später weitere |
| | status | VARCHAR(20) NOT NULL DEFAULT 'published' | `draft`/`published` |
| | sort_order | INT NOT NULL DEFAULT 0 | |
| | visible | TINYINT(1) NOT NULL DEFAULT 1 | |
| | style_json, source_config_json | LONGTEXT NOT NULL | |
| | created_at, updated_at | DATETIME NOT NULL | |
| `swmap_features` | id | BIGINT UNSIGNED AI PK | |
| | layer_id | BIGINT UNSIGNED NOT NULL | Index `(layer_id)` |
| | external_id | VARCHAR(190) NULL | UNIQUE `(layer_id, external_id)` (NULL mehrfach erlaubt) |
| | geometry_type | VARCHAR(20) NOT NULL | GeoJSON-Typ |
| | geometry_json | LONGTEXT NOT NULL | kanonisch, GeoJSON `[lon, lat]` |
| | bbox_min_lon, bbox_min_lat, bbox_max_lon, bbox_max_lat | DOUBLE NOT NULL | Index `(layer_id, bbox_min_lon, bbox_max_lon)` und `(bbox_min_lat, bbox_max_lat)` |
| | properties_json, style_json | LONGTEXT NOT NULL | |
| | updated_at | DATETIME NOT NULL | Cache-Invalidierung |
| `swmap_categories` | id | BIGINT UNSIGNED AI PK | |
| | slug | VARCHAR(190) NOT NULL | UNIQUE |
| | title | VARCHAR(255) NOT NULL | |
| | style_json | LONGTEXT NOT NULL | |
| `swmap_feature_categories` | feature_id, category_id | BIGINT UNSIGNED | PK `(feature_id, category_id)`, Index `(category_id)` |
| `swmap_providers` | id | BIGINT UNSIGNED AI PK | |
| | type | VARCHAR(20) NOT NULL | `xyz` (M1), `pmtiles` (M2) |
| | name | VARCHAR(190) NOT NULL | |
| | public_config_json | LONGTEXT NOT NULL | URL-Template, Attribution, Zoomgrenzen, öffentliche Tokens |
| | secret_constant | VARCHAR(64) NULL | nur Konstantenname (ADR 0004) |
| | enabled | TINYINT(1) NOT NULL DEFAULT 0 | |

## Löschregeln (im Service, keine Fremdschlüssel)
- Karte löschen → ihre Layer, deren Features und Kategorie-Zuordnungen löschen (eine Transaktion).
- Layer löschen → Features und Zuordnungen löschen.
- Feature löschen → Zuordnungen löschen.
- Kategorie löschen → nur Zuordnungen löschen. Provider löschen → Karten, die ihn referenzieren, fallen auf den Standardprovider zurück.
- Deinstallation löscht Tabellen und Optionen nur bei gesetzter Option `swmap_delete_data_on_uninstall`.

## Migrationen
Jede Migration hat eine Version und `up()`; `dbDelta`-kompatibles SQL. Reihenfolge wird erzwungen, bereits angewendete Versionen werden übersprungen, Fehler brechen ab und lassen `swmap_db_version` unverändert. Upgrade-Prüfung bei `plugins_loaded` (Versionsvergleich, günstig).
