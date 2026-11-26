
- [x] M3-03: WP-CLI: `status`, `maps list`, `import`, `export`; Bestätigung/`--yes` bei destruktiven Befehlen, Exit-Codes, maschinenlesbare Ausgabe
  - Ergebnis: `Cli\Command` (`status`, `import`, `export`) und `Cli\MapsCommand` (`maps list`), registriert nur unter WP-CLI; Exit-Codes 0/1/2, `--yes`/Rückfrage bei Ersetzen und Überschreiben, JSON-Ausgabe, `--dry-run`. Doku: `docs/cli.md`. Tests: `CliTest` (WP-CLI-Attrappe `WpCliStub.php`).
