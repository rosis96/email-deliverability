# syntax=docker/dockerfile:1
# Production image for the RevCadence backend (Laravel 13 / PHP 8.4).
# Single self-contained container: nginx + php-fpm, listening on $PORT.
# Build context = the backend/ directory (set this service's root dir to "backend").
FROM php:8.4-fpm-alpine

# System deps + PHP extensions required by Laravel + PostgreSQL + Instantly TLS.
RUN apk add --no-cache \
        bash \
        git \
        curl \
        zip \
        unzip \
        nginx \
        gettext \
        ca-certificates \
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
        mbstring \
    && apk del $PHPIZE_DEPS \
    && update-ca-certificates

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install the whole app, then production autoloader. --no-scripts avoids running
# artisan at build time (no env yet); package discovery happens on first boot.
COPY . .
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts --optimize-autoloader --no-progress \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/nginx.conf /etc/nginx/nginx.conf.template
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["entrypoint.sh"]
