# Benchmarks (M3-07)

Gemessen auf einer Entwicklungsmaschine (WSL2, PHP 8.3, MariaDB, Chromium 140 in Docker, ohne Seiten-Cache, Kacheln gemockt). Die Zahlen zeigen Größenordnungen, keine Garantien; sie lassen sich wiederholen:

```bash
SWMAPS_BENCH=1 composer test:wp -- --filter BenchmarkTest          # Server: Payload, Laufzeit, Speicher
SWMAPS_BENCH=1 bin/test-e2e.sh -- tests/benchmark.spec.ts --retries 0   # Browser: Laden und Interaktion
```

## Server: Punkte (öffentliches Payload und HTML)
| Punkte | Payload bauen | Speicher (Spitze) | JSON | gzip | Render (HTML) | HTML |
|---:|---:|---:|---:|---:|---:|---:|
| 100 | 3 ms | 47 MB | 27 KB | 2 KB | 3 ms | 54 KB |
| 1 000 | 15 ms | 49 MB | 261 KB | 15 KB | 17 ms | 532 KB |
| 10 000 | 159 ms | 83 MB | 2,6 MB | 137 KB | 167 ms | 5,4 MB |

## Server: große Tracks (Darstellungsvariante, GPS-ähnlich, ca. 4 m Abstand)
| Trackpunkte | gespeichert | Payload (vereinfacht) | Punkte danach | Berechnung |
|---:|---:|---:|---:|---:|
| 1 000 | 37 KB | 1 KB | 23 | 2 ms |
| 10 000 | 373 KB | 8 KB | 208 | 11 ms |
| 100 000 | 3,7 MB | 78 KB | 2 087 | 116 ms |

Die Vereinfachung läuft bei jedem Seitenaufruf (kein Cache); dichte Tracks werden zuerst linear ausgedünnt und die Eingabe für Douglas-Peucker auf 20 000 Punkte begrenzt (vorher brauchte ein 100 000-Punkte-Track mit extremem Zickzack mehrere Sekunden). Ein Seiten-/Objekt-Cache der Website entlastet zusätzlich.

## Server: Nachladen (AJAX-Modus, 10 000 Punkte, 500 je Seite)
Eine Anfrage `/public/maps/{id}/features` mit Bounding Box: ca. 10 ms, 500 Standorte je Antwort.

## Browser: Laden und Suchen (Inline-Daten, Liste sichtbar)
| Punkte | Seite + JSON | Karte bereit | Listensuche | JS-Heap |
|---:|---:|---:|---:|---:|
| 100 | 115 KB | ca. 0,2 s | ca. 20 ms | 10 MB |
| 1 000 | 591 KB | ca. 0,35 s | ca. 50 ms | 15 MB |
| 10 000 | 5,4 MB | ca. 1,7 s | ca. 3,8 s | 80 MB |

## Empfehlungen
- Bis etwa **1 000 Standorte** je Karte ist inline problemlos.
- Ab etwa **2 000 Standorten** den **AJAX-Modus** der Karte nutzen (nur sichtbarer Ausschnitt, keine Standortliste im DOM). Die 10 000-Punkte-Liste kostet vor allem Browser-Layout (ca. 4 s für das Filtern), nicht JavaScript; Versuche mit `content-visibility` waren schlechter.
- Die Suche im Viewer ist mit 150 ms entprellt.
- Große Tracks brauchen keine manuelle Vereinfachung; die gespeicherte Geometrie bleibt unverändert.
