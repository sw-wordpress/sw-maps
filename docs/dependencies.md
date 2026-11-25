# Abhängigkeiten und Referenzabgleich (M0-01 bis M0-03)

## Abgleich mit SW BackupIt (Stand der Prüfung)
Übernommen: Monorepo-Struktur (`apps/admin`, `packages/*`, `bin`, `docs`, `pages`, `tests/wp`), Composer-Skripte (`test`, `stan`, `cs`, `cs:wp`, `test:wp`, `check`), PHPCS PSR-12 (Core) und WPCS (Adapter), PHPStan Level 8 mit `tests/phpstan`-Stubs, strenges Coverage-Gate (> 80 %, nicht >=) für PHP (Clover-Merge) und JS (`check-coverage.mjs`), `bin/test-db.sh`, `bin/i18n.sh`, Workflows `tests.yml` und `security-audit.yml`, Mozart-Prefixing, `.editorconfig`.
Nicht übernommen: Backup/Restore-Logik, Restore-App, Update-Mechanismus (X-01).

## Versionen
| Bereich | Version | Anmerkung |
|---|---|---|
| PHP | >= 8.2 | wie BackupIt |
| WordPress | >= 6.4 | wie BackupIt |
| Datenbank | MySQL 5.7+ / MariaDB 10.4+ | utf8mb4, InnoDB, Index-Präfixlängen ≤ 191 Zeichen bei `utf8mb4_unicode_520_ci` |
| Node | ^22.13 / ^24 | wie BackupIt (`engines`) |
| Vue / Quasar / Pinia / Router / I18n | 3.5 / 2.x / Pinia / 5 / 11 | wie BackupIt |
| Vite / Vitest / TypeScript | 8 / 5 / 6 | wie BackupIt |
| PHPUnit / PHPStan | 11 / 2 | wie BackupIt |
| OpenLayers | `ol` 10.x | siehe unten |

## OpenLayers und PMTiles (M0-03)
- `ol`: Lizenz BSD-2-Clause (GPL-kompatibel), aktiv gepflegt. Bundle: Viewer-Build mit Tree-Shaking (nur Map, View, TileLayer, VectorLayer, Overlay, Controls) bleibt unter dem Budget in `bin/bundle-budget.json`; Editor-Interaktionen (`Draw`, `Modify`, `Snap`) nur im Admin-Chunk.
- PMTiles: `pmtiles` (BSD-3-Clause) mit eigener `ol`-Anbindung. Die Einbindung erfolgt erst in M2-14; Entscheidung für die Bibliothek wird dort anhand von Bundlegröße gegen das Budget getroffen. Bis dahin keine Abhängigkeit.
- Tile-Nutzung: https://operations.osmfoundation.org/policies/tiles/ (Attribution, kein Prefetch, Hinweis auf Alternativen im Admin).
