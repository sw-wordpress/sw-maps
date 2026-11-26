# Karteneditor

- **Layer-Leiste (links):** Layer anlegen, umbenennen, ein-/ausblenden, löschen, in der Reihenfolge verschieben (spätere Layer liegen oben) und über das Regler-Symbol gestalten (Farbe, Linienstärke, Muster, Deckkraft, Sichtbarkeit ab/bis Zoom); Standorte des Layers auswählen. Die Basiskarte (Anbieter) ist von den Layern getrennt.
- **Karte (Mitte):** Werkzeuge *Auswählen/Verschieben*, *Marker*, *Linie*, *Fläche*. Marker lassen sich per Ziehen verschieben; Stützpunkte von Linien und Flächen ändert das Auswahlwerkzeug (Ziehen verschiebt, Ziehen auf einer Linie ergänzt, Alt+Klick auf einen Stützpunkt entfernt ihn); Polygonlöcher und Multi-Geometrien bleiben erhalten; *Teil hinzufügen* im Standortformular zeichnet einen weiteren Teil (Multi-Geometrie).
- **Eigenschaften (rechts):** Reiter *Karte* (Titel, Slug, Status, Anbieter, Startansicht, Zoomgrenzen, Höhe/Seitenverhältnis, Bedienung) und *Standort* (Titel, Beschreibung, Adresse, Link, Symbol, Farbe, Bild, exakte Koordinaten).
- **Startansicht:** Mittelpunkt und Zoom eintragen oder *Aktuelle Ansicht übernehmen*; „An alle Standorte anpassen“ wählt den Ausschnitt automatisch.
- **Vorschau:** Umschalter Desktop/Mobil in der Werkzeugleiste.

## Kategorien, Stile und Zusatzfelder
- **Kategorien** gelten seitenweit (Standortformular → *Kategorien verwalten*): Name, Farbe und Symbol. Ein Standort kann mehrere Kategorien haben; Farbe und Symbol der Kategorie mit der niedrigsten ID gelten, solange der Standort nichts Eigenes wählt (Reihenfolge Standort > Regel > Kategorie > Layer). **Stilregeln** je Layer (Layer-Einstellungen): „Standorte der Kategorie X erhalten diese Farbe“. Besucher filtern über die Legende, deren Knöpfe das Symbol zeigen (nicht nur die Farbe).
- **Linien und Flächen:** Stärke, Muster (durchgezogen, gestrichelt, gepunktet), Deckkraft, Flächenfüllung; die Länge (Haversine) steht im Kopf des Formulars.
- **Zusatzfelder:** bis zu 10 Paare aus Bezeichnung und Wert, im Popup als Liste (reiner Text).

## Import
Über **Import** in der Kopfzeile des Editors (Recht *Importe ausführen*; ungespeicherte Änderungen vorher speichern): GeoJSON, GPX 1.0/1.1, CSV (Trennzeichen und Dezimaltrenner wählen). *Prüfen* zeigt Anzahl, Vorschau, Feldzuordnung, Hinweise und ungültige Einträge, ohne zu schreiben. Modi: neuer Layer, zu Layer hinzufügen, per externer ID aktualisieren (Bild und Symbol bleiben), Layerinhalt ersetzen (mit Bestätigung). Ungültige Einträge verhindern den Import, außer sie werden ausdrücklich übersprungen. Bilder und Symbole werden nicht importiert. Grenzen: 5 MiB, „Standorte pro Layer“ aus den Einstellungen, 200 000 Koordinaten; XML ohne DOCTYPE/Entities.

## Große Karten
*Standorte für den sichtbaren Ausschnitt nachladen* (Kartenreiter) lädt nur den sichtbaren Bereich (öffentlicher Endpunkt, begrenzt, entprellt); die Standortliste entfällt dann. Lange Tracks werden für die Ausgabe vereinfacht; die gespeicherte Geometrie bleibt unverändert. *Nahe Marker gruppieren* schaltet das Clustering ab; Marker, die sich durch Zoomen nicht trennen lassen (gleiche Stelle), öffnen beim Klick eine Auswahlliste.

## Speichern
Änderungen werden erst mit **Speichern** übernommen. Ungespeicherte Änderungen warnen beim Verlassen. *Rückgängig/Wiederholen* gilt für lokale Änderungen bis zum Speichern. Es gibt weder Sperren noch Revisionen: der letzte Speichervorgang gilt.

## Popups und Sicherheit
Popups entstehen ausschließlich aus den strukturierten Feldern; Texte werden als Text ausgegeben (kein HTML). Links sind auf `http(s)`, `mailto` und `tel` beschränkt. Eigene Symbole und Bilder kommen aus der Mediathek (nur JPEG, PNG, GIF, WebP).
