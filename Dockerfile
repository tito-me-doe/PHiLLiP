FROM php:8.5-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libsqlite3-dev libcurl4-openssl-dev libonig-dev libzip-dev unzip git iproute2 poppler-utils tesseract-ocr \
    && docker-php-ext-install pdo_pgsql pdo_mysql pdo_sqlite curl mbstring pcntl zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY docker/php.ini /usr/local/etc/php/conf.d/phillip.ini
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . /app
ENV PHP_CLI_SERVER_WORKERS=4
ENV COMPOSER_CACHE_DIR=/tmp/phillip-composer
EXPOSE 8080
CMD ["sh", "-c", "composer install --no-dev --no-interaction --prefer-dist && php bin/phill-migrate && exec php -S 0.0.0.0:8080 -t public public/index.php"]
