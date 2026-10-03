# Manual Linux check of the test suite; see tests/linux/run.sh.
FROM php:8.4-cli

RUN docker-php-ext-install pcntl sockets \
    && docker-php-ext-enable opcache \
    && apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /engine
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist
COPY . .

# The suite once as shipped, then with tracing JIT in random order.
CMD ["sh", "-c", "php -v && uname -a && vendor/bin/phpunit && php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M vendor/bin/phpunit --order-by=random"]
