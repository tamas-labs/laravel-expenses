# Test and tooling image: the stock CLI image plus what the package needs to
# reach MySQL and what Pest needs to run in parallel.
ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache git \
    && docker-php-ext-install pdo_mysql pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The container runs as the host user; the cache directory seeds the named
# volume, so it must be writable by anyone.
ENV COMPOSER_HOME=/tmp/composer
RUN mkdir -p /tmp/composer/cache && chmod -R 0777 /tmp/composer
