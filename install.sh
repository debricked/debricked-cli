#!/usr/bin/env bash

if ! type php > /dev/null; then
  echo "Please install PHP and try again"
  exit 1
fi
composer install --no-dev --no-suggest --classmap-authoritative