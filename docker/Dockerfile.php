FROM php:8.4-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    postgresql-dev \
    redis \
    libzip-dev \
    zip \
    unzip \
    git \
    supervisor

# PHP extensions required by Laravel + TimescaleDB
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    pcntl \
    bcmath \
    sockets

# Redis PHP extension
RUN pecl install redis && docker-php-ext-enable redis

# PHP config optimizations for queue workers
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/memory.ini

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first (Docker layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY . .

# Post-install scripts
RUN composer dump-autoload --optimize

# Default command: PHP-FPM
CMD ["php-fpm"]
