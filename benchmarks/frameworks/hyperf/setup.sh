#!/bin/sh
set -eu
curl -fsSL --retry 3 'https://codeload.github.com/hyperf/hyperf-skeleton/tar.gz/855dd7ef145d9e1e3d0500d958ac55d51cbf6ef0' -o /tmp/benchmark-skeleton.tar.gz
printf '514c6880516db17d091aeef30d3617d1b8c219db037ad4949a90b28dde910d27  /tmp/benchmark-skeleton.tar.gz\n' | sha256sum -c -
mkdir -p /srv/app
tar -xzf /tmp/benchmark-skeleton.tar.gz --strip-components=1 -C /srv/app
rm /tmp/benchmark-skeleton.tar.gz
cd /srv/app
if [ -f /bench/hyperf/composer.lock ]; then
    cp /bench/hyperf/composer.json /bench/hyperf/composer.lock .
else
    composer require hyperf/validation:~3.2.0 --no-update --no-interaction --no-scripts
fi
composer install --no-dev --classmap-authoritative --prefer-dist --no-interaction --no-scripts
cp -R /bench/hyperf/app/. .
composer dump-autoload --no-dev --classmap-authoritative --no-scripts
SCAN_CACHEABLE=false php bin/hyperf.php list > /dev/null
