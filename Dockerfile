FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libzip-dev libsqlite3-dev unzip \
    && docker-php-ext-install intl pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
