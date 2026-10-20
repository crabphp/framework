ARG BASE=php:8.4-cli-bookworm
FROM ${BASE}
COPY --from=composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip && docker-php-ext-install -j2 pcntl pdo_mysql && rm -rf /var/lib/apt/lists/*
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY common/php.ini /usr/local/etc/php/conf.d/zz-benchmark.ini
COPY crab/ /bench/crab/
COPY framework-source/ /srv/framework/
COPY crab-source/ /srv/crab/
COPY crab/app/ /srv/crab/
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_DEBUG=false SERVER_WORKERS=2 LOG_LEVEL=error
RUN /bench/crab/setup.sh
WORKDIR /srv/crab
ENTRYPOINT ["/bench/crab/entrypoint.sh"]
