# syntax=docker/dockerfile:1.7

# ---------- 1. Composer deps ----------
FROM composer:2 AS vendor

COPY composer.json /package/composer.json
COPY src /package/src
COPY config /package/config
COPY migrations /package/migrations
COPY templates /package/templates
COPY translations /package/translations

COPY docker/host-app/composer.json /host/composer.json
WORKDIR /host
RUN composer install \
    --no-interaction --no-progress --no-dev --prefer-dist \
    --optimize-autoloader --no-scripts \
    --ignore-platform-req=ext-intl \
    --ignore-platform-req=ext-zip

# ---------- 2. Runtime ----------
FROM php:8.4-cli-alpine AS runtime

RUN apk add --no-cache postgresql-client libpq icu-libs libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        intl \
        zip \
        opcache \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

WORKDIR /host

COPY --from=vendor /package /package
COPY --from=vendor /host /host
COPY docker/host-app/ /host/

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /host/bin/console \
    && mkdir -p /host/var/cache /host/var/log \
    && chmod -R 777 /host/var

ENV APP_ENV=demo

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
