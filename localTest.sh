#!/usr/bin/env bash

docker build --target=test -t debricked/debricked-cli-test . && docker run --env-file ./.env.test.local debricked/debricked-cli-test
