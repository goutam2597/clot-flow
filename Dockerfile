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
# Stage 2: Production PHP + Nginx Runtime
# -----------------------------------------------------------------------------
FROM richarvey/nginx-php-fpm:latest

WORKDIR /var/www/html

# Image & Container settings
ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1
ENV COMPOSER_ALLOW_SUPERUSER=1

# Laravel Production Defaults
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

# Copy project files
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend_builder /app/public/build ./public/build

# Permissions
RUN chmod -R +x scripts/ && \
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

EXPOSE 80

CMD ["/start.sh"]
