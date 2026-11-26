# ADR 0003: Synchroner Import

Status: angenommen
Entscheidung: Import und Export laufen synchron im Request, begrenzt durch konfigurierbare Limits (Dateigröße, Features, Koordinaten, Laufzeit). Es gibt bis 1.0 keine Job-Queue.
Gründe: weniger bewegliche Teile auf Shared Hosting; Atomarität über eine Transaktion bzw. Staging.
Folgen: Überschreitung eines Limits lehnt den Import vor dem Schreiben ab; kein stiller Teilimport. Queue-Konzept steht im BACKLOG.
