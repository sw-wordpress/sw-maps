# Entwicklung

Voraussetzungen: PHP >= 8.2 (Sodium, Zip, mysqli, gd), Composer 2, Node laut `.nvmrc`, MariaDB, Docker (E2E, Mozart), `gettext`.

| Aufgabe | Befehl |
|---|---|
| PHP-Abhängigkeiten | `composer install` |
| PHP-Qualität | `composer check` (`cs`, `cs:wp`, `stan`, `test`) |
| PHP Unit/Integration | `composer test` |
| WordPress-Tests | `bin/test-db.sh start && composer install -d tests/wp && composer test:wp` (`test:wp:ms` für Multisite) |
| Frontend | `npm ci --prefix apps/admin`, `npm run lint|typecheck|test:coverage` |
| Renderer | `npm ci --prefix packages/renderer-openlayers` |
| E2E | `bin/test-e2e.sh [--keep] [--headed] [-- <args>]` (Docker), Mock-Modus: `npm run test:mock --prefix e2e` |
| i18n | `bin/i18n.sh update|check` |
| Release-ZIP | `make dist` |

Tests laufen nie gegen die Dev-Site. Details zur Testumgebung: `docs/PLAN.md` §14.1.
Coverage-Gates: PHP `php tests/coverage/check.php coverage/clover.xml 80`, JS `npm run test:coverage` (strikt > 80 %).
