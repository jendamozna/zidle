FROM php:8.4-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions gd intl pdo_mysql zip @composer

COPY php.ini /usr/local/etc/php/conf.d/zidle.ini

WORKDIR /var/www/html
