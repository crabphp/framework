#!/bin/sh
set -eu
export COMPOSER_ROOT_VERSION=7.4.99
curl -fsSL --retry 3 'https://codeload.github.com/symfony/skeleton/tar.gz/bf1abda299403468285fd4fa0514d916991c9120' -o /tmp/benchmark-skeleton.tar.gz
printf '9dae378ef8e120b2763dd4879c9bb6ecae44592b3be156f3e130c72cc05a266d  /tmp/benchmark-skeleton.tar.gz\n' | sha256sum -c -
mkdir -p /srv/app
tar -xzf /tmp/benchmark-skeleton.tar.gz --strip-components=1 -C /srv/app
rm /tmp/benchmark-skeleton.tar.gz
cd /srv/app
if [ -f /bench/symfony/composer.lock ]; then
    cp /bench/symfony/composer.json /bench/symfony/composer.lock .
else
    composer require symfony/serializer:7.4.* symfony/validator:7.4.* symfony/property-access:7.4.* doctrine/doctrine-bundle:^3.0 doctrine/dbal:^4.0 --no-update --no-interaction
fi
cp /bench/symfony/symfony.lock .
cp -R /bench/symfony/recipe/. /srv/app/
composer install --no-dev --classmap-authoritative --prefer-dist --no-interaction --no-scripts
cp -R /bench/symfony/app/. /srv/app/
composer dump-autoload --no-dev --classmap-authoritative
printf 'APP_ENV=prod\nAPP_SECRET=benchmark-secret\nDEFAULT_URI=http://symfony:8080\nDATABASE_URL=mysql://bench:bench@mysql:23306/benchmark\n' > .env
composer dump-env prod
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug
