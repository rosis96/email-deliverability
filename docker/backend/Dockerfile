# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine

# System deps + PHP extensions required by Laravel + PostgreSQL
RUN apk add --no-cache \
        bash \
        git \
        curl \
        zip \
        unzip \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        postgresql-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        pcntl \
        zip \
    && apk del $PHPIZE_DEPS

# Composer (from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy only dependency manifests first for better layer caching.
COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader --no-progress || true

# Copy the rest of the application.
COPY backend/ ./

RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress || true

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

ENTRYPOINT ["entrypoint.sh"]
