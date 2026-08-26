# syntax=docker/dockerfile:1

# ── Stage 1: application (PHP-FPM) ────────────────────────────────────────────
FROM php:8.5-fpm-alpine AS app

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
# pcntl: `reverb:start` references SIGINT/SIGTERM/SIGTSTP directly and fails
# to boot at all without it (not just a degraded shutdown, like queue/
# schedule workers).
RUN install-php-extensions pdo_pgsql mbstring zip opcache pcntl

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# true → dev/test image (with require-dev), false → lean production image
ARG INSTALL_DEV_DEPENDENCIES=false

COPY composer.json composer.lock ./
RUN if [ "$INSTALL_DEV_DEPENDENCIES" = "true" ]; then \
        composer install --no-interaction --no-scripts --no-autoloader --prefer-dist; \
    else \
        composer install --no-interaction --no-scripts --no-autoloader --prefer-dist --no-dev; \
    fi

COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=0755 docker/entrypoint-prod.sh docker/entrypoint-dev.sh docker/entrypoint-test.sh /usr/local/bin/

EXPOSE 9000

ENTRYPOINT ["entrypoint-prod.sh"]

# ── Stage 2: web/edge (nginx) ──────────────────────────────────────────────────
FROM nginx:alpine AS web

COPY docker/nginx/templates /etc/nginx/templates
COPY --from=app /var/www/html/public /var/www/html/public

# FastCGI upstream service name; overridden per Compose profile
ENV FPM_HOST=app

EXPOSE 80
