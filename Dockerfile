FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader

FROM php:8.4-fpm-alpine
RUN apk add --no-cache libzip-dev sqlite-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_sqlite intl mbstring opcache \
    && rm -rf /tmp/*
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/manaleague-entrypoint
RUN mkdir -p bootstrap/cache storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && touch storage/app/database.sqlite \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod -R 775 bootstrap/cache storage \
    && chmod +x /usr/local/bin/manaleague-entrypoint
USER www-data
EXPOSE 9000
ENTRYPOINT ["manaleague-entrypoint"]
CMD ["php-fpm"]
