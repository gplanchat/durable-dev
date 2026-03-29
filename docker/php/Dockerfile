# PHP CLI aligné sur le monorepo (8.2+, grpc pour le bridge Temporal, pcov pour couverture / Infection).
FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libsqlite3-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" zip pdo_sqlite pcntl sockets \
    && pecl install grpc-1.57.0 pcov \
    && docker-php-ext-enable grpc pcov \
    && apt-get purge -y $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app
