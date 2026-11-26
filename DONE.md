
- [x] M3-05: `POST /geocoding/search` capability-geschützt; Schlüssel nur aus `wp-config`, nie im Browser
  - Ergebnis: `POST /geocoding/search` und `/reverse` mit `swmap_edit_maps`; Schlüssel nur aus `SWMAP_GEOCODER_KEY` (wird nur serverseitig an den Dienst gesendet, nie ausgeliefert); stabile Fehlercodes. Tests: `NominatimGeocoderTest`, `RestApiTest`, `geocoding.test.ts`.
