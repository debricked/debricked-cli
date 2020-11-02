#!/usr/bin/env bash

# -e  Exit immediately if a simple command exits with a non-zero status
# -x  Print a trace of simple commands and their arguments after they are
# expanded and before they are executed.
set -xe

echo "Test that Console can self install if needed"
rm -Rf /home/vendor
/home/bin/console about --env=test

cd "${0%/*}/../"

echo $PWD

echo "Install Composer with dev dependencies"
composer install

phpdbg -qrr -d memory_limit=-1 bin/phpunit --coverage-clover coverage.xml

php -d memory_limit=-1 vendor/bin/phpstan analyse src/ --level=7

vendor/bin/php-cs-fixer fix --config=.php_cs.dist -v --dry-run --stop-on-violation --diff --using-cache=no
