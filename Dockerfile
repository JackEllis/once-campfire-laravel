FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer
FROM dunglas/frankenphp:1.13-php8.4-bookworm@sha256:1481cd38046efda3b61455056816b5f6e9f02db546a48ba866a90331dd0c6658
RUN apt-get update && apt-get install -y --no-install-recommends libvips-tools ffmpeg unzip && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions bcmath pcntl sockets apcu-5.1.24
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp && chown -R www-data:www-data /config/caddy /data/caddy
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /rails
COPY . .
RUN composer install --no-dev --classmap-authoritative --no-interaction && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/ && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/db storage/files && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/php.ini /usr/local/etc/php/conf.d/campfire.ini
ENTRYPOINT ["/rails/bin/start"]
