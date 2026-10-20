#!/bin/sh
set -eu
curl -fsSL --retry 3 'https://codeload.github.com/spiral/app/tar.gz/b3ebe0a5e089f0012482c808e90c614d9cfd9e51' -o /tmp/benchmark-skeleton.tar.gz
printf '1be27377af5a27f963f438a1359c06ab4f10fe0c6ebc22d1276da5c9416c8b65  /tmp/benchmark-skeleton.tar.gz\n' | sha256sum -c -
mkdir -p /srv/app
tar -xzf /tmp/benchmark-skeleton.tar.gz --strip-components=1 -C /srv/app
rm /tmp/benchmark-skeleton.tar.gz
cd /srv/app
if [ -f /bench/spiral/composer.lock ]; then cp /bench/spiral/composer.lock .; fi
composer install --no-dev --prefer-dist --no-interaction
if [ -d /bench/spiral/app ]; then cp -R /bench/spiral/app/. .; fi
sed -i '/DumperBootloader::class/d' app/src/Application/Kernel.php
composer dump-autoload --no-dev --classmap-authoritative
curl -fsSL --retry 3 'https://github.com/roadrunner-server/roadrunner/releases/download/v3.0.0/roadrunner-3.0.0-linux-arm64.tar.gz' -o /tmp/rr.tar.gz
printf '8c9440daaf583790ac7254246a8abe0023dc6fbc53ae13d017bf8b6da40d77e0  /tmp/rr.tar.gz\n' | sha256sum -c -
tar -xzf /tmp/rr.tar.gz --strip-components=1 -C /usr/local/bin roadrunner-3.0.0-linux-arm64/rr
rm /tmp/rr.tar.gz
rm -rf runtime/cache
php app.php configure
