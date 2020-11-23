#!/usr/bin/env bash

cd /data
php -d memory_limit=2048M /home/bin/console "$@"
