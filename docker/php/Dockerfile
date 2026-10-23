FROM php:8.4-apache AS app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions gd intl pdo_mysql zip @composer

COPY php.ini /usr/local/etc/php/conf.d/zidle.ini

WORKDIR /var/www/html

# prepare.sh: Node, Chromium for Playwright and the MariaDB client next to PHP
FROM app AS test
RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs npm chromium mariadb-client \
    && rm -rf /var/lib/apt/lists/*
