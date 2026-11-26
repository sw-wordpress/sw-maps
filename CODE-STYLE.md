# CODE-STYLE.md – sw-maps

Verbindlich für das gesamte Repository. Konfliktreihenfolge: Sicherheit und Produktplan (`docs/PLAN.md`) → diese Datei → ADRs (`docs/adr/`) → offizielle Standards. Abweichungen eng begrenzen, begründen und gezielt unterdrücken, nie einen ganzen Standard abschalten.

| Pfad | Stil | Tooling |
|---|---|---|
| `packages/core/`, `tests/` | PSR-12, PSR-4, camelCase | PHPCS, PHPStan |
| `packages/wordpress/`, `sw-maps.php`, `uninstall.php` | WordPress Coding Standards (Tabs, snake_case-Funktionen/Hooks) | PHPCS (`phpcs-wordpress.xml.dist`) |
| `apps/admin/`, `packages/*` (TS) | Vue 3 `<script setup lang="ts">`, TypeScript strict, Prettier | ESLint, vue-tsc, Prettier |

## PHP
- PHP >= 8.2, `declare(strict_types=1);` in jeder eigenen Datei, Composer/PSR-4 (`SWMaps\Core\`, `SWMaps\WordPress\`).
- Namenspräfixe laut `docs/PLAN.md` §2.1: Funktionen/Hooks/Optionen `swmap_*`, Tabellen `{$wpdb->prefix}swmap_*`, Konstanten `SWMAPS_*`, Textdomain `sw-maps`.
- PSR-4-Klassendateien im WP-Adapter dürfen PascalCase heißen (dokumentierte WPCS-Ausnahme).
- Domain-Objekte unveränderlich (`readonly`), Zustände als Enums, DTOs typisiert; keine assoziativen Arrays als Verträge im Core.
- Kein `unserialize` ohne `allowed_classes => false`; kein `eval`; keine Shell-Ausführung.
- Fehler als typisierte Exceptions; REST übersetzt sie in `WP_Error` mit stabilen Codes (`docs/rest-api.md`).

## Sicherheit
- SQL ausschließlich über `$wpdb->prepare()` bzw. `$wpdb->insert/update/delete` mit Formaten.
- Eingaben validieren (Typ, Bereich, Länge), Ausgaben escapen (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` mit `JSON_HEX_*` für Inline-JSON).
- Jeder REST-Endpunkt hat einen `permission_callback`; objektbezogene Prüfung im Service/Controller.
- Keine Secrets in Logs, Exporten, DB oder öffentlichen Antworten.

## TypeScript / Vue
- `strict`, kein `any`, `consistent-type-imports`, kein `v-html`, `console` nur `warn`/`error`.
- Sichtbare Texte nur über Vue I18n (`t('key')`), nie als Literal im Template.
- Renderer-Code kennt weder Quasar noch Pinia; geteilt werden nur die Typen aus `packages/map-contracts`.

## Tests
- PHPUnit 11 (Core ohne WordPress), WordPress-Integration in `tests/wp/`, Vitest, Playwright (`e2e/`).
- Tests sind unabhängig, deterministisch und nutzen keine öffentlichen Dienste (Tiles/Geocoder gemockt).
