#!/bin/sh
set -eu
curl -fsSL --retry 3 'https://codeload.github.com/walkor/webman/tar.gz/394e611efc496eab992fc6447d2c7ef2438b6902' -o /tmp/benchmark-skeleton.tar.gz
printf 'f411981968724143cbcbf9a5306752babab2ac47c216a0f10e4fd8d1acb252d0  /tmp/benchmark-skeleton.tar.gz\n' | sha256sum -c -
mkdir -p /srv/app
tar -xzf /tmp/benchmark-skeleton.tar.gz --strip-components=1 -C /srv/app
rm /tmp/benchmark-skeleton.tar.gz
cd /srv/app
if [ -f /bench/webman/composer.lock ]; then
    cp /bench/webman/composer.json /bench/webman/composer.lock .
else
    composer require webman/database webman/validation --no-update --no-interaction
fi
composer install --no-dev --classmap-authoritative --prefer-dist --no-interaction
cp -R /bench/webman/app/. .
composer dump-autoload --no-dev --classmap-authoritative
