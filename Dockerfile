FROM phpswoole/swoole:php8.5 AS base

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --classmap-authoritative

COPY . .

RUN composer dump-autoload --optimize

FROM node:22-alpine AS build

WORKDIR /var/www/html

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

RUN npm run build

FROM base AS production

COPY --from=build /var/www/html/public/build /var/www/html/public/build

RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan storage:link --relative 2>/dev/null || true

EXPOSE 8000

CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000"]
