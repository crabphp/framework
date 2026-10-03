#!/bin/sh
# Manual only: builds a Linux image from the working tree and runs the test
# suite inside it. Needs Docker. Extra arguments replace the default command,
# for example: tests/linux/run.sh vendor/bin/phpunit --filter HttpTest
set -eu
cd "$(dirname "$0")/../.."
docker build --quiet --file tests/linux/Dockerfile --tag crab-engine-linux-test . >/dev/null
exec docker run --rm --init crab-engine-linux-test "$@"
