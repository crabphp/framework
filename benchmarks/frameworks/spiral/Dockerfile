ARG BASE=php:8.4-cli-bookworm
FROM ${BASE}
COPY --from=composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
COPY --from=dunglas/frankenphp@sha256:14007e6207a57651cb402970a44e5f2df15bfeffcbdeb5bbb322271949878b07 /usr/local/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN apt-get update && apt-get install -y --no-install-recommends unzip && install-php-extensions pcntl pdo_mysql sockets zip intl && rm -rf /var/lib/apt/lists/*
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY common/php.ini /usr/local/etc/php/conf.d/zz-benchmark.ini
COPY common/metadata.php /bench/metadata.php
COPY spiral/ /bench/spiral/
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=production APP_DEBUG=false
RUN /bench/spiral/setup.sh
WORKDIR /srv/app
ENTRYPOINT ["/bench/spiral/entrypoint.sh"]
