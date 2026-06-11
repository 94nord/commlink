#!/bin/sh
set -e

# Instant feedback on the live-mounted source: no opcache, no cached config.
printf 'opcache.enable=0\nopcache.enable_cli=0\n' > /usr/local/etc/php/conf.d/zz-disable-opcache.ini

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan clear-compiled

exec php-fpm
