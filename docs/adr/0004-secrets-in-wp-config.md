# ADR 0004: Secrets in wp-config

Status: angenommen
Entscheidung: Geheime Schlüssel (Provider, Geocoder) stehen als PHP-Konstanten in `wp-config.php`. `swmap_providers.secret_constant` speichert nur den **Namen** der Konstante. Öffentliche Browser-Tokens liegen getrennt in `public_config_json`.
Folgen: Schlüssel erscheinen nie in DB, Export, Logs oder REST-Antworten; der Admin sieht nur, ob die Konstante definiert ist. Namen müssen `^[A-Z][A-Z0-9_]{2,63}$` entsprechen.
