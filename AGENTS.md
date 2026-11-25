# AGENTS.md – dauerhafte Regeln für sw-maps

Vor Codeänderungen `docs/PLAN.md` (Produktplan), `CODE-STYLE.md` (Stil und Sicherheit) und `TODO.md` lesen. Referenzprojekt: sw-backupit (Struktur, Tooling), Oberfläche: sw-gallery/sw-sliderman.

## Architekturregeln
- `packages/core` ist frameworkunabhängig: **keine WordPress-Funktionen, -Klassen oder -Hooks**. PSR-12, `strict_types`, camelCase.
- `packages/wordpress` ist der einzige Ort mit WP-APIs. WPCS-Stil (Tabs, snake_case-Funktionen); PSR-4-Klassennamen sind eine dokumentierte Ausnahme.
- Abhängigkeiten zeigen nach innen: WordPress → Core. REST-Controller delegieren an Application Services; keine Logik in Controllern, keine SQL im Renderer.
- Status, Geometrietypen und Provider-Typen sind Enums, keine Strings.
- Koordinaten intern WGS84, GeoJSON-Reihenfolge `[longitude, latitude]`.
- PHPStan Level 8 ohne Baseline über allen produktiven PHP-Code; WP-Symbole und PHPDoc-Korrekturen in `tests/phpstan/`.
- Sicherheit: SQL nur über `$wpdb->prepare()`, Ausgabe kontextbezogen escapen, `permission_callback` mit objektbezogener Prüfung, Secrets nur in `wp-config.php` (DB speichert nur den Konstantennamen).
- OpenLayers ist der einzige Renderer bis 1.0; Renderer-Vertrag minimal halten (`packages/map-contracts`).

## Arbeitsablauf
1. Schnittstelle → Implementierung → Tests → `composer check` / `npm run lint` → Doku → `TODO.md`/`DONE.md` → kleiner, zusammenhängender Commit.
2. Tests laufen nie gegen die Dev-Site, sondern gegen Wegwerf-Umgebungen (`bin/test-db.sh`, `tests/e2e-env/`).
3. Nicht umgesetzte Funktionen ausdrücklich dokumentieren; keine Platzhalter als fertig deklarieren.

## Texte in der WordPress-Oberfläche
- Zeige nur Informationen, die bei einer Entscheidung, Aktion oder Fehlerbehebung helfen. Zusatztexte entfallen, wenn ein Status, eine Beschriftung oder der Kontext dieselbe Information bereits vermittelt. Technische Hintergründe gehören in die Dokumentation.
- Folge: keine erklärenden Untertitel unter selbsterklärenden Feldern, keine Hinweise, die den Feldnamen wiederholen, keine Texte zu Standardverhalten. Ausnahmen sind Datenschutz-, Kosten- und Sicherheitshinweise (z. B. externe Requests eines Providers), die der Plan ausdrücklich verlangt.
- Änderungen an sichtbaren Texten aktualisieren alle Sprachen (Deutsch und Englisch) im selben Arbeitsschritt.

## Übersetzungen
Jede Änderung an sichtbaren Texten aktualisiert alle Sprachen (PHP-gettext in `languages/`, SPA-Kataloge in `apps/admin/src/i18n/`). `bin/i18n.sh update` und `bin/i18n.sh check` ausführen; der PHPUnit-Test `I18nTest` und der Vitest-Paritätstest erzwingen das.

## Dokumentation
- Knapp, ohne Wiederholungen; nur Entscheidungen, Sicherheitsdetails und Testhinweise; vorhandene Doku verlinken.
- Jede Änderung aktualisiert `pages/Changelog.md` unter `Unreleased` (`Feature`, `Bugs`, `Other topics`); Kategorien nur mit Einträgen.

## Tests und Coverage
- Jede funktionale Änderung erhält Tests im selben Arbeitsschritt. Zeilenabdeckung **> 80 %** (genau 80 % reicht nicht), PHP und JS/TS getrennt; keine Dateien ausschließen oder Grenzen senken.
- Vor Abschluss Tests und Coverage ausführen und Ergebnisse nennen.
