# MVP-Abnahme (P§17, M1-30)

Nachweise: automatisierte Tests (Dateien siehe unten) plus Lauf gegen das **entpackte Release-ZIP** (`bin/test-zip-install.sh`, alle Playwright-Specs).

| # | Kriterium | Nachweis |
|---|---|---|
| 1 | Karte ohne API-Schlüssel mit OSM anlegen und veröffentlichen; selbst gehosteter Tile-Server ohne externe Requests | `e2e/tests/admin-editor.spec.ts` (Anlegen, Marker, Veröffentlichen, Frontend). OSM-Preset: `tests/wp/tests/SchemaTest.php::test_migrate_is_idempotent_and_seeds_openstreetmap_once`. Eigener Tile-Server: Provider-Endpunkte `RestApiTest::test_providers_endpoints_*`; Requests gehen ausschließlich an den konfigurierten Host (`e2e/tests/privacy.spec.ts`, `tileHostsOnly`). |
| 2 | Marker anlegen, verschieben, speichern, sichere Popups | `admin-editor.spec.ts` (Klick, Drag, Koordinaten, Speichern); Popups: `packages/renderer-openlayers/tests/units.test.ts` („popup (XSS safety)“), `e2e/tests/security.spec.ts` („imported markup is shown as text“). |
| 3 | Gutenberg = Shortcode; mehrere Karten unabhängig; kein zusätzlicher Datenrequest | `e2e/tests/frontend.spec.ts` (gleiche Liste/Daten, `dataRequests` leer, zwei Karten, ein Bundle); `tests/wp/tests/RenderingTest.php::test_block_renders_same_output_as_shortcode`; Editor-Vorschau: `admin-editor.spec.ts` (Block-Vorschau). |
| 4 | Attribution und Providereinstellungen sichtbar/konfigurierbar | `frontend.spec.ts` (Attribution inkl. Vollbild); Admin: `apps/admin/tests/views-providers.test.ts`, `RestApiTest::test_providers_*`. |
| 5 | Consent API und Click-to-load; Standortliste bleibt nutzbar | `e2e/tests/privacy.spec.ts` (kein Request vor Consent, Click-to-load, **echtes wp-consent-api-Plugin**); `packages/renderer-openlayers/tests/viewer.test.ts`. |
| 6 | Entwürfe/nicht freigegebene Inhalte nicht über öffentliche REST abrufbar | Es gibt in M1 keine öffentlichen REST-Routen: `RestApiTest::test_anonymous_gets_401_and_subscriber_403_on_every_route`, `e2e/tests/security.spec.ts` (anonym 401, Entwurf rendert nichts, Vorschau nur für Editoren), `RenderingTest::test_unpublished_layers_and_features_are_not_in_public_output`. |
| 7 | Funktioniert ohne andere SW-Plugins; Deaktivierung beschädigt nichts | Keine Abhängigkeiten (Composer/Code); `tests/wp/tests/LifecycleTest.php` (Deaktivierung erhält Daten, Deinstallation nur mit Opt-in). |
| 8 | Release-ZIP ohne Build-Tools installierbar | `make dist` (`bin/verify-zip.sh`, `tests/release/check-package.php`) und `bin/test-zip-install.sh`: gesamte Playwright-Suite gegen das entpackte ZIP in einem frischen WordPress (21 Tests grün). |
| 9 | PHPStan Level 8, > 80 % Coverage je Sprache | PHPStan: `composer stan` (0 Fehler, ohne Baseline). PHP-Coverage (Core + WP-Adapter, Unit + WordPress + Multisite zusammengeführt): `bin/coverage-php.sh` (≈ 89 %). JS/TS: `bin/test-js.sh` je Paket (Admin ≈ 97 %, Renderer ≈ 94 %, Contracts 100 %). |

Weitere Abnahmetests (M1-26..29): Mobilansicht und Tastatur (`frontend.spec.ts`), Netzwerk/Bundle (`privacy.spec.ts`), Security-Fixtures XSS/IDOR/private Layer/Entwürfe (PHPUnit-WP + `security.spec.ts`), Multisite/Aktivierung/Upgrade/Deinstallation (`MultisiteTest`, `LifecycleTest`, `SchemaTest`).

## Bekannte Grenzen des MVP
- `e2e/tests/consent-plugins.spec.ts` versucht denselben Ablauf mit Complianz (wordpress.org). Das Plugin ließ sich ohne dessen interaktiven Assistenten nicht so konfigurieren, dass es Karten sperrt; der Test überspringt sich dann. Complianz und Borlabs sind damit **nicht** nachgewiesen.
- Die Consent-Kompatibilität ist gegen das Referenz-Plugin „WP Consent API“ mit einer simulierten Opt-in-Konfiguration getestet; konkrete Banner-Plugins (z. B. Complianz, Borlabs) wurden nicht einzeln geprüft.
- Linien- und Flächenbearbeitung ist im Editor als Prototyp vorhanden (zeichnen, Stützpunkte ändern), aber nicht Teil der MVP-Abnahme (M2-02).
- Der Mock-Modus der E2E-Tests deckt nur Kernabläufe der Admin-App ab.
