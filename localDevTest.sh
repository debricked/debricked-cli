#!/usr/bin/env bash

docker build --target=test -t debricked/debricked-cli-test . && docker run --rm --env-file ./.env.test.dev.local --network docker_frontend debricked/debricked-cli-test
