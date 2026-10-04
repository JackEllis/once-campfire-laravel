FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer
FROM php:8.4-fpm-bookworm@sha256:43e1ac38217031dbbecae60e84ccf8593722031559178d199bf56adb0145d5d0
RUN apt-get update && apt-get install -y --no-install-recommends nginx libsqlite3-dev libonig-dev libxml2-dev libcurl4-openssl-dev libzip-dev libvips-tools ffmpeg unzip && docker-php-ext-install pdo_sqlite mbstring dom pcntl sockets opcache && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install bcmath
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /rails
COPY . .
RUN composer install --no-dev --classmap-authoritative --no-interaction && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/db storage/files && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/php.ini /usr/local/etc/php/conf.d/campfire.ini
COPY deploy/fpm.conf /usr/local/etc/php-fpm.d/zz-campfire.conf
ENTRYPOINT ["/rails/bin/start"]
