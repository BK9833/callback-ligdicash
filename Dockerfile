# ── Build stage : installation des dépendances Composer ─────────────────────
FROM composer:2.7 AS builder

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist

# ── Runtime stage : image PHP légère ────────────────────────────────────────
FROM php:8.2-cli-alpine

# Extensions nécessaires
RUN apk add --no-cache \
        libpng-dev \
        oniguruma-dev \
        openssl-dev \
        curl \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        opcache \
    && docker-php-ext-enable opcache

# Configuration PHP optimisée production
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

# Copie des vendors buildés
COPY --from=builder /app/vendor ./vendor

# Copie du code applicatif
COPY . .

# Render injecte $PORT dynamiquement (défaut 10000)
ENV PORT=10000

EXPOSE ${PORT}

# Healthcheck interne Docker
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:${PORT}/health || exit 1

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /var/www/html index.php"]
