#!/bin/sh
set -eu
curl -fsSL --retry 3 'https://codeload.github.com/tempestphp/tempest-app/tar.gz/6619b5c29ef06d177924b60cb6db6da5f494f751' -o /tmp/skeleton.tar.gz
printf '6264d15d1b818c7e66773ba72b8b55979507bbd55ca60939b5a203f7dbf0873f  /tmp/skeleton.tar.gz\n' | sha256sum -c -
mkdir -p /srv/app
tar -xzf /tmp/skeleton.tar.gz --strip-components=1 -C /srv/app
cd /srv/app
php -v
# Compatibility probe only: retain PHP's real platform check.
composer require tempest/framework:3.19.2 --dry-run --no-scripts --update-no-dev --no-interaction --ignore-platform-req='ext-*'
