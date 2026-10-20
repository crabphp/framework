#!/bin/sh
set -eu
php-fpm --fpm-config /bench/laravel-fpm/php-fpm.conf &
fpm=$!
nginx -c /bench/laravel-fpm/nginx.conf -g 'daemon off;' &
nginx=$!
trap 'kill -TERM "$fpm" "$nginx" 2>/dev/null || true; wait || true' EXIT INT TERM
wait -n "$fpm" "$nginx"
