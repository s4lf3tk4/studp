FROM php:8.4-fpm

# --- Системные зависимости ----------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        openssl \
        unzip \
        zip \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libpq-dev \
        libssl-dev \
        autoconf \
        gcc \
        make \
        pkg-config \
    && rm -rf /var/lib/apt/lists/*

# --- PHP-расширения -----------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache

# --- Сертификаты Минцифры -----------------------------------------------------
COPY docker/php/certs/russian_trusted_root_ca.crt /usr/local/share/ca-certificates/
COPY docker/php/certs/russian_trusted_sub_ca.crt /usr/local/share/ca-certificates/
RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade ca-certificates \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && openssl verify -CAfile /etc/ssl/certs/ca-certificates.crt \
        /usr/local/share/ca-certificates/russian_trusted_root_ca.crt

# --- phpredis -----------------------------------------------------------------
RUN git clone --depth 1 --branch 6.3.0 https://github.com/phpredis/phpredis.git /usr/src/php/ext/redis \
    && docker-php-ext-install redis \
    && docker-php-ext-enable redis \
    && rm -rf /usr/src/php/ext/redis

# --- Composer + зеркало Packagist --------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer-global-config.json /composer-global/config.json
ENV COMPOSER_HOME=/composer-global

# --- Зависимости приложения «запекаем» в образ --------------------------------
# require, а не install: в lock-файле ещё нет mjohann/paginated-file-reader,
# а install при рассинхроне composer.json и lock падает
COPY php-producer/composer.json php-producer/composer.lock /var/www/
RUN cd /var/www \
    && composer require mjohann/paginated-file-reader \
        --no-scripts --no-interaction --prefer-dist --optimize-autoloader

WORKDIR /var/www
RUN usermod -u 1000 www-data

# --- Проверка, что всё нужное на месте ----------------------------------------
RUN php -r ' \
    $required = ["dom","xml","xmlwriter","simplexml","json","zlib","iconv","mbstring","fileinfo","zip","gd","curl","openssl","pdo_mysql","pcntl","redis","Zend OPcache"]; \
    $missing = array_values(array_filter($required, fn ($e) => ! extension_loaded($e))); \
    if ($missing) { fwrite(STDERR, "Не хватает расширений: ".implode(", ", $missing).PHP_EOL); exit(1); } \
    fwrite(STDOUT, "Расширения на месте".PHP_EOL); \
'

EXPOSE 9000

CMD ["php-fpm"]