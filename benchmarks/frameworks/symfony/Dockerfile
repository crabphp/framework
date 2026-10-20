ARG BASE=dunglas/frankenphp:php8.4-bookworm
FROM ${BASE}
COPY --from=composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
RUN install-php-extensions pcntl pdo_mysql mbstring intl opcache
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY common/php.ini /usr/local/etc/php/conf.d/zz-benchmark.ini
COPY symfony/ /bench/symfony/
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod APP_DEBUG=0 APP_SECRET=benchmark-secret APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= DB_CONNECTION=mysql DB_HOST=mysql DB_PORT=23306 DB_DATABASE=benchmark DB_USERNAME=bench DB_PASSWORD=bench DATABASE_URL=mysql://bench:bench@mysql:23306/benchmark SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr LOG_LEVEL=error
RUN /bench/symfony/setup.sh
COPY symfony/preload.ini /usr/local/etc/php/conf.d/zzz-preload.ini
WORKDIR /srv/app
ENTRYPOINT ["frankenphp", "run", "--config", "/bench/symfony/Caddyfile"]
