#!/bin/sh
set -eu
mode=$1
export COMPOSER_ROOT_VERSION=12.12.2
curl -fsSL --retry 3 'https://codeload.github.com/laravel/laravel/tar.gz/945f4e5a9fd3695dc0ee512f497c650fb82cfbb8' -o /tmp/benchmark-skeleton.tar.gz
printf '4c645fc1f4f234417aabb98084d740cb38cc44d76dc9a64b4e76cdef9fb8ec63  /tmp/benchmark-skeleton.tar.gz\n' | sha256sum -c -
mkdir -p /srv/app
tar -xzf /tmp/benchmark-skeleton.tar.gz --strip-components=1 -C /srv/app
rm /tmp/benchmark-skeleton.tar.gz
cp -R /bench/laravel/app/. /srv/app/
cd /srv/app
if [ -f /bench/laravel-$mode/composer.lock ]; then
    cp /bench/laravel-$mode/composer.json /bench/laravel-$mode/composer.lock .
elif [ "$mode" = octane ]; then
    composer require laravel/octane:^2.0 --no-update --no-interaction
fi
composer install --no-dev --classmap-authoritative --prefer-dist --no-interaction
if [ "$mode" = octane ]; then
    ln -s /usr/local/bin/frankenphp /srv/app/frankenphp
    php artisan octane:install --server=frankenphp --no-interaction
fi
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache
