# ADR 0001: Eigene Datenbanktabellen

Status: angenommen
Entscheidung: Karten, Layer, Features, Kategorien und Provider liegen in eigenen Tabellen (`{$wpdb->prefix}swmap_*`), nicht in Custom Post Types.
Gründe: Geodaten brauchen Indizes (Layer, Status, Bounding Box) und eigene Abfragen; Revisionen, Permalinks und Post-Meta werden nicht benötigt. Kein Spatial-Typ, kein PostGIS: Bounding-Box-Spalten reichen für Viewport-Abfragen.
Folgen: versionierte, multisite-fähige Migrationen (`docs/database.md`); Sicherung über BackupIt-kompatible Tabellen; Löschregeln werden im Service erzwungen (keine Fremdschlüssel, da WordPress/MyISAM-Hosting).
