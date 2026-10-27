FROM php:8.4.27-fpm

# --- Системные зависимости ----------------------------------------------------
# poppler-utils — pdfinfo: считает страницы PDF, не загружая документ в память
# libonig-dev   — для сборки mbstring
# libzip-dev    — для сборки zip
# libssl-dev    — для сборки phpredis
# autoconf/gcc/make/pkg-config — сборка расширений и pecl
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        openssl \
        unzip \
        zip \
        poppler-utils \
        libonig-dev \
        libzip-dev \
        libssl-dev \
        libicu-dev \
        autoconf \
        gcc \
        make \
        pkg-config \
    && rm -rf /var/lib/apt/lists/*

# --- PHP-расширения -----------------------------------------------------------
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        pcntl \
        zip \
        intl \
        opcache

# --- Сертификаты Минцифры -----------------------------------------------------
# Без них не работают запросы к platform-api2.max.ru
COPY docker/php/certs/russian_trusted_root_ca.crt /usr/local/share/ca-certificates/
COPY docker/php/certs/russian_trusted_sub_ca.crt /usr/local/share/ca-certificates/
RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade ca-certificates \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && openssl verify -CAfile /etc/ssl/certs/ca-certificates.crt \
        /usr/local/share/ca-certificates/russian_trusted_root_ca.crt


# --- phpredis (кэш, очереди, сессии) ------------------------------------------
# Собираем из тега: сборка не зависит от доступности pecl.php.net
RUN git clone --depth 1 --branch 6.3.0 https://github.com/phpredis/phpredis.git /usr/src/php/ext/redis \
    && docker-php-ext-install redis \
    && docker-php-ext-enable redis \
    && rm -rf /usr/src/php/ext/redis

# --- Composer -----------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer-global-config.json /composer-global/config.json
ENV COMPOSER_HOME=/composer-global

WORKDIR /var/www
RUN usermod -u 1000 www-data

RUN php -r ' \
    $required = ["mbstring","zip","curl","openssl","dom","fileinfo","pdo_mysql","pcntl","intl","redis","Zend OPcache"]; \
    $missing = array_values(array_filter($required, fn ($e) => ! extension_loaded($e))); \
    if ($missing) { fwrite(STDERR, "Не хватает расширений: ".implode(", ", $missing).PHP_EOL); exit(1); } \
    fwrite(STDOUT, "Расширения на месте".PHP_EOL); \
' \
    && command -v pdfinfo \
    && command -v curl

# --- Точка входа ---------------------------------------------------------------
# Права на storage и папка для скачанных PDF создаются на старте контейнера,
# потому что кода в образе нет — он приходит bind-mount'ом
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]

EXPOSE 9000

CMD ["php-fpm"]