# Karteneditor

- **Layer-Leiste (links):** Layer anlegen, umbenennen, ein-/ausblenden, löschen; Standorte des Layers auswählen.
- **Karte (Mitte):** Werkzeuge *Auswählen/Verschieben*, *Marker*, *Linie*, *Fläche*. Marker lassen sich per Ziehen verschieben; Stützpunkte von Linien und Flächen ändert das Auswahlwerkzeug.
- **Eigenschaften (rechts):** Reiter *Karte* (Titel, Slug, Status, Anbieter, Startansicht, Zoomgrenzen, Höhe/Seitenverhältnis, Bedienung) und *Standort* (Titel, Beschreibung, Adresse, Link, Symbol, Farbe, Bild, exakte Koordinaten).
- **Startansicht:** Mittelpunkt und Zoom eintragen oder *Aktuelle Ansicht übernehmen*; „An alle Standorte anpassen“ wählt den Ausschnitt automatisch.
- **Vorschau:** Umschalter Desktop/Mobil in der Werkzeugleiste.

## Speichern
Änderungen werden erst mit **Speichern** übernommen. Ungespeicherte Änderungen warnen beim Verlassen. *Rückgängig/Wiederholen* gilt für lokale Änderungen bis zum Speichern. Es gibt weder Sperren noch Revisionen: der letzte Speichervorgang gilt.

## Popups und Sicherheit
Popups entstehen ausschließlich aus den strukturierten Feldern; Texte werden als Text ausgegeben (kein HTML). Links sind auf `http(s)`, `mailto` und `tel` beschränkt. Eigene Symbole und Bilder kommen aus der Mediathek (nur JPEG, PNG, GIF, WebP).
