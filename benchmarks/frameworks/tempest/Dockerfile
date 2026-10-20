ARG BASE=php@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b
FROM ${BASE}
COPY --from=composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
COPY tempest/setup.sh /bench/setup.sh
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN /bench/setup.sh
