FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip opcache pcntl \
    && docker-php-ext-enable opcache

# OPcache empfohlene Einstellungen
RUN echo "[opcache]" > /usr/local/etc/php/conf.d/opcache-recommended.ini && \
    echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache-recommended.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache-recommended.ini && \
    echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache-recommended.ini

RUN curl -sS https://get.symfony.com/cli/installer | bash \
    && mv /root/.symfony5/bin/symfony /usr/local/bin/symfony

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
