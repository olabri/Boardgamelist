FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libxml2-dev libzip-dev unzip \
    && docker-php-ext-install curl dom pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json ./
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-interaction --prefer-dist --no-progress

COPY . .
RUN mkdir -p storage

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
