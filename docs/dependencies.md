# Abhängigkeiten und Referenzabgleich (M0-01 bis M0-03)

## Abgleich mit SW BackupIt (Stand der Prüfung)
Übernommen: Monorepo-Struktur (`apps/admin`, `packages/*`, `bin`, `docs`, `pages`, `tests/wp`), Composer-Skripte (`test`, `stan`, `cs`, `cs:wp`, `test:wp`, `check`), PHPCS PSR-12 (Core) und WPCS (Adapter), PHPStan Level 8 mit `tests/phpstan`-Stubs, strenges Coverage-Gate (> 80 %, nicht >=) für PHP (Clover-Merge) und JS (`check-coverage.mjs`), `bin/test-db.sh`, `bin/i18n.sh`, Workflows `tests.yml` und `security-audit.yml`, Mozart-Prefixing, `.editorconfig`.
Nicht übernommen: Backup/Restore-Logik, Restore-App, Update-Mechanismus (X-01).

## Versionen
| Bereich | Version | Anmerkung |
|---|---|---|
| PHP | >= 8.2 | wie BackupIt |
| WordPress | >= 6.4 | wie BackupIt |
| Datenbank | MySQL / MariaDB wie WordPress 6.4 (MySQL 5.5.5+, MariaDB 10.0+) | utf8mb4, InnoDB; getestet mit MariaDB 11.4. BackupIt nennt keine konkreten Versionen („MySQL or MariaDB“), die Untergrenze folgt daher WordPress. Indexlängen ≤ 190 Zeichen (utf8mb4). |
| Node | ^22.13 / ^24 | wie BackupIt (`engines`) |
| Vue / Quasar / Pinia / Router / I18n | 3.5 / 2.x / Pinia / 5 / 11 | wie BackupIt |
| Vite / Vitest / TypeScript | 8 / 5 / 6 | wie BackupIt |
| PHPUnit / PHPStan | 11 / 2 | wie BackupIt |
| OpenLayers | `ol` 10.x | siehe unten |

## OpenLayers und PMTiles (M0-03)
- `ol` 10.x: Lizenz BSD-2-Clause (GPL-kompatibel), aktiv gepflegt. Gemessene Bundlegrößen (gzip): Frontend-Viewer ≈ 101 KB JS + 2 KB CSS; Budget in `bin/bundle-budget.json` (CI: `node bin/check-bundle-size.mjs`). Editor-Interaktionen (`Draw`, `Modify`, `Snap`) sind nur im Admin-Chunk enthalten, nicht im Frontend-Bundle.
- PMTiles: `pmtiles` 4.x (BSD-3-Clause), nur im Frontend-Viewer, nur Raster-Archive mit eigener `ol`-Anbindung (≈ 5,5 KB gzip; Viewer jetzt 112,4 von 115 KB). Entscheidung und Grenzen: [ADR 0005](adr/0005-pmtiles-raster.md).
- Tile-Nutzung: https://operations.osmfoundation.org/policies/tiles/ (Attribution, kein Prefetch, Hinweis auf Alternativen im Admin).
