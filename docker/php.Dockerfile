FROM php:8.1-fpm

RUN apt-get update && apt-get install -y \
    libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libcurl4-openssl-dev \
    git unzip curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_mysql mysqli mbstring zip gd intl bcmath curl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress || true

COPY . .

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/application/cache 2>/dev/null || true

EXPOSE 9000
CMD ["php-fpm"]
