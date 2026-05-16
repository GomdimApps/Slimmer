# Slimmer — PHP 8.4 + Ghostscript (Alpine)
FROM php:8.4-cli-alpine

# System dependencies
RUN apk add --no-cache \
        ghostscript \
        zstd \
        freetype \
        libjpeg-turbo \
        libpng \
        libwebp \
    && apk add --no-cache --virtual .build-deps \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
    # GD extension
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Working directory
WORKDIR /app

# PHP dependencies (cached)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

# Copy project source
COPY . .

# Optimize autoload
RUN composer dump-autoload --optimize
