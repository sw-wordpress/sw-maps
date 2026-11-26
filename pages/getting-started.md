# Erste Schritte

## Installation
Voraussetzungen: WordPress 6.4+, PHP 8.2+. ZIP unter *Plugins → Installieren → Plugin hochladen* installieren und aktivieren. Es werden keine Schlüssel und keine Cloud-Dienste benötigt; OpenStreetMap ist vorkonfiguriert.

## Erste Karte
1. *Karten → Neue Karte*, Titel eingeben, *Neue Karte*.
2. Im Editor das Werkzeug *Marker setzen* wählen und auf die Karte klicken.
3. Titel und weitere Angaben rechts eintragen, **Speichern**.
4. Auf *Karte* wechseln, Status auf *Veröffentlicht* setzen, **Speichern**.

## Einbinden
| Weg | Beispiel |
|---|---|
| Block | Block „SW Map“ einfügen, Karte wählen |
| Shortcode | `[sw_map id="12"]`, optional `height="60vh"`, `click_to_load="1"`, `list="0"`, `lazy="0"` |
| PHP | `echo swmap_render_map( 12, array( 'height' => '400px' ) );` |

Die Karte enthält ihre Daten direkt im HTML; es gibt keinen zusätzlichen Datenrequest. Das Kartenskript wird nur auf Seiten mit Karte geladen. Entwürfe sehen nur Benutzer mit Bearbeitungsrecht (Vorschau).

## Berechtigungen
Administratoren und Redakteure bearbeiten und veröffentlichen Karten; Anbieter, Einstellungen und Systemstatus bleiben Administratoren vorbehalten (Tabelle unter *Einstellungen*).
