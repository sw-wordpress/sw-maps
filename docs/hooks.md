# PHP-API und Hooks (M0-07)

Stabilität: nur hier dokumentierte Symbole sind öffentlich. Änderungen werden im Changelog versioniert.

```php
swmap_render_map( int $map_id, array $options = array() ): string;
swmap_register_source( string $id, \SWMaps\Core\Contracts\MapSourceInterface $source ): void; // M2
```
`swmap_render_map` gibt HTML zurück (leerer String, wenn die Karte nicht existiert oder nicht öffentlich ist; Entwürfe nur bei `swmap_edit_maps`). Erlaubte `$options`: `height` (CSS-Länge), `aspect_ratio`, `lazy` (bool), `click_to_load` (bool), `list` (bool).

## Hooks
| Hook | Typ | Argumente | Zeitpunkt |
|---|---|---|---|
| `swmap_map_published` | action | `int $map_id` | nach Commit, wenn Status auf `published` wechselt |
| `swmap_layer_updated` | action | `int $layer_id, int $map_id` | nach Commit |
| `swmap_import_completed` | action | `array $report` | nach Commit (M2) |
| `swmap_register_sources` | action | – | Quellenregistrierung (M2) |
| `swmap_render_options` | filter | `array $options, int $map_id` | vor dem Rendern |

Fehlerrichtlinie: Events erst nach konsistentem Commit; Exceptions in Drittanbieter-Callbacks werden protokolliert und brechen abgeschlossene Operationen nicht ab.

## Capabilities (M1-04)
| Capability | Zweck | Standardrollen bei Aktivierung |
|---|---|---|
| `swmap_edit_maps` | Karten und Features bearbeiten | Administrator, Editor |
| `swmap_publish_maps` | Karten veröffentlichen | Administrator, Editor |
| `swmap_manage_layers` | Layer verwalten | Administrator, Editor |
| `swmap_manage_imports` | Importe ausführen | Administrator |
| `swmap_manage_system` | Provider, Einstellungen, Systemstatus | Administrator (Multisite: Super Admin über `manage_options`-Mapping) |

| `swmap_consent_category` | filter | `string\|null $category` | WP Consent API category required for tiles (default `marketing`; `null` disables gating) |
| `swmap_consent_api_active` | filter | `bool $active` | Whether a consent implementation is available (default: `function_exists( 'wp_has_consent' )`) |
| `swmap_can_edit_map` | filter | `bool $allowed, int $map_id, int $user_id` | Restricts (never extends) who may edit a map |
