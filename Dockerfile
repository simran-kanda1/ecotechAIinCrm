FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

COPY . .

# Render sets PORT; default 8080 for local docker runs
ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "php -d memory_limit=512M -d max_execution_time=300 -S 0.0.0.0:${PORT} router.php"]
