# -----------------------------------------------------------------------------
# Stage 1: Build Frontend Assets
# -----------------------------------------------------------------------------
FROM node:20-alpine AS frontend_builder

WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build

# -----------------------------------------------------------------------------
# Stage 2: Production PHP 8.4 + Nginx Runtime
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-alpine

WORKDIR /var/www/html

# Install system dependencies and PHP 8.4 extensions
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    git \
    unzip \
    bash \
    libpq-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) pdo pdo_pgsql pdo_mysql bcmath gd zip intl opcache

# Get Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy configuration files
COPY conf/nginx/default.conf /etc/nginx/http.d/default.conf
COPY conf/supervisord.conf /etc/supervisor/supervisord.conf

# Copy project code
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend_builder /app/public/build ./public/build

# Install PHP dependencies with Composer
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Permissions
RUN chmod +x scripts/entrypoint.sh && \
    mkdir -p storage/framework/{sessions,views,cache/data} storage/logs bootstrap/cache && \
    chmod -R 777 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/bin/bash", "scripts/entrypoint.sh"]
