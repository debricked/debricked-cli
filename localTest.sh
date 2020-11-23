#!/usr/bin/env bash

docker build --target=test -t debricked/debricked-cli-test . && docker run --rm --env-file ./.env.test.local debricked/debricked-cli-test
