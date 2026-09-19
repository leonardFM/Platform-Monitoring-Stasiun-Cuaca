FROM php:8.4-fpm-alpine

ARG PHPIZE_DEPS="autoconf g++ gcc libc-dev make pkgconf re2c"

RUN apk add --no-cache nginx supervisor curl postgresql-libs \
        ${PHPIZE_DEPS} linux-headers postgresql-dev \
    && docker-php-ext-install pdo_pgsql pgsql sockets pcntl posix \
    && docker-php-ext-enable opcache \
    && printf 'clear_env = no\n' | tee -a /usr/local/etc/php-fpm.d/www.conf \
    && apk del ${PHPIZE_DEPS} linux-headers postgresql-dev \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --prefer-dist

COPY . .
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisor-backend.conf /etc/supervisor-backend.conf
COPY supervisor-worker.conf /etc/supervisor-worker.conf
COPY entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8080

WORKDIR /var/www/html
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["backend"]