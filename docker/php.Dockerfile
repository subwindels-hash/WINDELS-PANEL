FROM php:8.1-fpm

RUN apt-get update && apt-get install -y \
    libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libcurl4-openssl-dev \
    git unzip curl cron \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_mysql mysqli mbstring zip gd intl bcmath curl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Production opcache. Defaults are tuned for development (revalidating every
# request); this trades reload-on-change for throughput.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/zz-marvy.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency layer (cached until composer.json/lock change). --no-scripts
# because the post-install hook (tools/link_system.php) is not in this layer;
# the system/ link is created explicitly below. A failed install must fail the
# build — never ship an image with half a vendor tree.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .

# CodeIgniter 3.1.13 ships system/ inside the composer package; the front
# controller expects it at the app root (it is gitignored, so neither the
# build context nor a fresh clone carries it).
RUN ln -sfn vendor/codeigniter/framework/system system

# storage/ is gitignored, so the build context may not carry these. CI3 drops
# log lines silently when storage/logs is absent, and sessions break without
# the cache dir — create them explicitly rather than relying on the copy.
RUN mkdir -p /var/www/html/storage/logs \
             /var/www/html/storage/cache/sessions \
             /var/www/html/application/cache \
 && chown -R www-data:www-data /var/www/html/storage /var/www/html/application/cache

EXPOSE 9000
CMD ["php-fpm"]
