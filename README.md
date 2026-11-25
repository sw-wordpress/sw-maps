# SW Maps

Interaktive Karten für WordPress: Marker, Linien, Flächen, GPX/GeoJSON/CSV. OpenStreetMap ohne API-Schlüssel, frei wählbare Kartenanbieter, selbst gehostet möglich, keine Telemetrie.

Status: in Entwicklung (Foundation und MVP). Plan: [docs/PLAN.md](docs/PLAN.md), offene Arbeit: [TODO.md](TODO.md), erledigt: [DONE.md](DONE.md).

## Voraussetzungen
PHP 8.2+, WordPress 6.4+, MySQL 5.7+ / MariaDB 10.4+ (utf8mb4, InnoDB), Einzelsite oder Multisite.

## Entwicklung
```bash
composer install && npm ci --prefix apps/admin
make build              # Admin-App und Renderer
composer check          # PHPCS, PHPStan, PHPUnit
bin/test-db.sh start    # Wegwerf-MariaDB für Integrationstests
make dist               # Release-ZIP
```
Details: [docs/development.md](docs/development.md).

## Lizenz
GPL-2.0-or-later
