ARG BASE=php:8.4-fpm-bookworm
FROM ${BASE}
COPY --from=composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends nginx=1.22.1-9+deb12u10 libicu-dev unzip && docker-php-ext-install -j2 pdo_mysql intl && rm -rf /var/lib/apt/lists/*
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY common/php.ini /usr/local/etc/php/conf.d/zz-benchmark.ini
COPY laravel/ /bench/laravel/
COPY laravel-fpm/ /bench/laravel-fpm/
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=production APP_DEBUG=0 APP_SECRET=benchmark-secret APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= DB_CONNECTION=mysql DB_HOST=mysql DB_PORT=23306 DB_DATABASE=benchmark DB_USERNAME=bench DB_PASSWORD=bench DATABASE_URL=mysql://bench:bench@mysql:23306/benchmark SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr LOG_LEVEL=error
RUN /bench/laravel-fpm/setup.sh
WORKDIR /srv/app
RUN chown -R www-data:www-data /srv/app/storage /srv/app/bootstrap/cache
ENTRYPOINT ["/bin/bash", "/bench/laravel-fpm/entrypoint.sh"]
