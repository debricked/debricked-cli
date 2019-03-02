#!/usr/bin/env bash

cd "${0%/*}/../"

# -e  Exit immediately if a simple command exits with a non-zero status
# -x  Print a trace of simple commands and their arguments after they are
# expanded and before they are executed.
set -xe

composer install

bin/phpunit

vendor/bin/phpstan analyse src/ --level=7

vendor/bin/php-cs-fixer fix --config=.php_cs.dist -v --dry-run --stop-on-violation --diff --using-cache=no