# WP-CLI

Die Befehle stehen nur unter `wp` zur Verfügung (die Ausgabe ist wie bei WP-CLI üblich englisch).

| Befehl | Zweck |
|---|---|
| `wp sw-maps status [--format=table\|json]` | Version, Schema, Tabellen, Umgebung, Einstellungen |
| `wp sw-maps maps list [--status=] [--search=] [--format=table\|json\|csv\|ids]` | Karten auflisten |
| `wp sw-maps import <datei> [--format=geojson\|gpx\|csv\|package] [--map=<id>] [--mode=new_layer\|append\|upsert\|replace] [--layer=<id>] [--layer-title=] [--delimiter=] [--decimal=] [--dry-run] [--skip-invalid] [--yes] [--output=text\|json]` | Datei importieren; Plugin-Dateien legen neue Entwurfskarten an |
| `wp sw-maps export <map-id> [--format=json\|geojson\|csv] [--layer=<id>] [--output=<datei>] [--yes]` | Karte exportieren (ohne `--output` auf die Standardausgabe) |
| `wp sw-maps export --all [--output=<datei>]` | Plugin-Datei aller Karten |

- **Exit-Codes:** `0` Erfolg, `1` Betriebsfehler (Datei nicht lesbar, Karte nicht gefunden, Schreibfehler, Rückfrage abgelehnt), `2` Eingabe oder Datei abgelehnt (fehlende/ungültige Argumente, Limits, Validierung).
- **Bestätigung:** Das Ersetzen eines Layers (`--mode=replace`) und das Überschreiben einer Ausgabedatei fragen nach, `--yes` überspringt die Rückfrage (ohne Terminal bricht der Befehl ohne `--yes` ab). Mit `--dry-run` wird nichts geschrieben.
- **Maschinenlesbar:** `--format=json` (status, maps list) bzw. `--output=json` (import) liefern JSON auf der Standardausgabe.
- **Rechte:** WP-CLI läuft ohne Benutzer; mit `--user=<id>` wird der Autor neuer Karten gesetzt. Es gelten dieselben Limits wie in der REST-API (Dateigröße, Standorte pro Layer, Koordinaten).
- Beispiel: `wp sw-maps import spur.gpx --map=3 --user=1` · `wp sw-maps export --all > sicherung.json`
