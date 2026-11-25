# ADR 0004: Secrets in wp-config

Status: angenommen
Entscheidung: Geheime Schlüssel (Provider, Geocoder) stehen als PHP-Konstanten in `wp-config.php`. `swmap_providers.secret_constant` speichert nur den **Namen** der Konstante. Öffentliche Browser-Tokens liegen getrennt in `public_config_json`.
Folgen: Schlüssel erscheinen nie in DB, Export, Logs oder REST-Antworten; der Admin sieht nur, ob die Konstante definiert ist. Namen müssen `^SWMAP_[A-Z0-9_]{1,57}$` entsprechen: nur Konstanten im Namensraum des Plugins sind erlaubt, nie `DB_PASSWORD`, `AUTH_KEY` & Co. (sonst könnte auf Multisite ein Site-Administrator Netzwerk-Zugangsdaten über den Verbindungstest an einen fremden Server senden).
