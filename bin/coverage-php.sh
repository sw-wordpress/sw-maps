#!/usr/bin/env bash
# PHP line coverage of the productive code (core + WordPress adapter), merged over all suites, strict > 80 %.
# Needs Xdebug (coverage mode) and the private test database (bin/test-db.sh start).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
export XDEBUG_MODE=coverage
mkdir -p coverage

vendor/bin/phpunit --testsuite unit,integration --coverage-clover coverage/unit.xml >/dev/null
(cd tests/wp && vendor/bin/phpunit -c phpunit.xml.dist --coverage-clover ../../coverage/wordpress.xml >/dev/null)
(cd tests/wp && vendor/bin/phpunit -c phpunit-ms.xml.dist --coverage-clover ../../coverage/multisite.xml >/dev/null)

php tests/coverage/merge.php coverage/unit.xml coverage/wordpress.xml coverage/multisite.xml
php tests/coverage/check.php coverage/clover.xml 80
