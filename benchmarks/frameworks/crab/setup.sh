#!/bin/sh
set -eu
cd /srv/crab
if [ -f /bench/crab/composer.lock ]; then cp /bench/crab/composer.lock .; fi
composer install --no-dev --classmap-authoritative --prefer-dist --no-interaction
mkdir -p storage/logs
