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

phpdbg -qrr -d memory_limit=17179869184 bin/phpunit

composer static
