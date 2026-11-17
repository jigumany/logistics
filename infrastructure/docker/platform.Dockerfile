FROM php:8.3-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev libonig-dev unzip git curl && docker-php-ext-install pdo_pgsql mbstring intl zip bcmath pcntl opcache && rm -rf /var/lib/apt/lists/*
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && docker-php-ext-install gd && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY infrastructure/docker/php.ini /usr/local/etc/php/conf.d/zz-logistics.ini
WORKDIR /app
FROM base AS development
CMD ["php-fpm"]
FROM node:22-bookworm-slim AS frontend
WORKDIR /source
COPY package*.json ./
COPY apps/platform/package.json apps/platform/package.json
COPY apps/website/package.json apps/website/package.json
COPY packages packages
RUN npm ci
COPY apps/platform apps/platform
RUN npm run build --workspace=@logistics/platform
FROM base AS production
COPY apps/platform /app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && chown -R www-data:www-data storage bootstrap/cache
COPY --from=frontend /source/apps/platform/public/build /app/public/build
USER www-data
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY infrastructure/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /source/apps/platform/public /app/public
