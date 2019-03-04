#!/usr/bin/env bash

docker build -t php-test . && docker run php-test --env-file ./.env.test.local