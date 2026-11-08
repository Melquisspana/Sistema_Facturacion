# syntax=docker/dockerfile:1
#
# Imagen única de la aplicación (web, worker y programador). Ver
# docs/MIGRACION_LINUX_DOCKER.md §3.1. Etapas:
#   vendor  -> dependencias de Composer sin las de desarrollo
#   assets  -> public/build con Vite, igual que la CI
#   app     -> imagen final (php:8.3-apache-bookworm)
#   test    -> la final más las dependencias de desarrollo, para correr PHPUnit
#
#   docker build -t facturacion-app:<etiqueta> .
#   docker build --target test -t facturacion-app:test .

# ---------------------------------------------------------------- vendor
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# La imagen de Composer trae otro PHP y no tiene gd, imap ni bcmath: los requisitos de
# plataforma se comprueban en la etapa final, contra el PHP que corre la aplicación.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
        --no-interaction --no-progress --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---------------------------------------------------------------- assets
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
# tailwind.config.js también lee las vistas de paginación de Laravel.
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
     ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------------------------------------------------------------- app
FROM php:8.3-apache-bookworm AS app

# Vistas compiladas dentro de la imagen y no en el volumen de storage: así una imagen
# nueva nunca usa vistas compiladas por la anterior.
ENV TZ=America/El_Salvador \
    VIEW_COMPILED_PATH=/var/www/html/bootstrap/views

# Extensiones de §2.2. curl, iconv, mbstring, dom/xml, posix, fileinfo y opcache ya
# vienen en la imagen oficial; se agregan las que faltan y se quitan los paquetes de
# compilación, dejando solo las bibliotecas que las extensiones usan.
RUN set -eux; \
    apt-get update; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev \
        libc-client-dev libkrb5-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-configure imap --with-kerberos --with-imap-ssl; \
    docker-php-ext-install -j"$(nproc)" bcmath gd zip imap intl pdo_mysql pcntl; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -rt apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# Cliente OFICIAL de MySQL 8.4 (mysqldump/mysql): el default-mysql-client de Debian es
# MariaDB y no sirve contra MySQL 8.4. Más ca-certificates (Hacienda, Gmail, SMTP) y
# curl para el healthcheck.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
    install -d -m 0755 /etc/apt/keyrings; \
    curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 -o /tmp/mysql.asc; \
    # Huella de la clave de MySQL Release Engineering: si no coincide, no se instala.
    gpg --show-keys --with-colons /tmp/mysql.asc | grep -q '^fpr:::::::::BCA43417C3B485DD128EC6D4B7B3B788A8D3785C:$'; \
    gpg --dearmor -o /etc/apt/keyrings/mysql.gpg /tmp/mysql.asc; \
    rm /tmp/mysql.asc; \
    echo 'deb [signed-by=/etc/apt/keyrings/mysql.gpg] http://repo.mysql.com/apt/debian bookworm mysql-8.4-lts' \
        > /etc/apt/sources.list.d/mysql.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends mysql-community-client; \
    apt-get purge -y --auto-remove gnupg; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    a2enmod rewrite headers; \
    a2dissite 000-default
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-facturacion.ini"
COPY docker/apache/facturacion.conf /etc/apache2/sites-available/facturacion.conf
RUN a2ensite facturacion
COPY docker/entrypoint.sh /usr/local/bin/facturacion-entrypoint
RUN chmod 0755 /usr/local/bin/facturacion-entrypoint

WORKDIR /var/www/html

# Código con dueño www-data (uid 33): web, worker y programador corren con ese usuario.
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

# storage/ no entra a la imagen (.dockerignore): se arma vacío para que el volumen nuevo
# nazca con esta estructura y con el dueño correcto.
RUN set -eux; \
    mkdir -p storage/app/private storage/app/public storage/app/templates \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/framework/testing storage/logs storage/fonts backups bootstrap/cache bootstrap/views; \
    chown -R www-data:www-data storage backups bootstrap; \
    # La imagen oficial deja /var/www/html en 1777: el código no tiene por qué ser de todos.
    chmod 0755 /var/www/html; \
    ln -s ../storage/app/public public/storage; \
    chown -h www-data:www-data public/storage

# Falla al construir si falta una extensión que pide composer.json (ext-bcmath, gd...).
RUN --mount=type=bind,from=vendor,source=/usr/bin/composer,target=/usr/local/bin/composer \
    COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev --no-interaction

USER www-data
RUN php artisan package:discover --ansi
USER root

EXPOSE 80
ENTRYPOINT ["facturacion-entrypoint"]
CMD ["apache2-foreground"]

# ---------------------------------------------------------------- test
# Para PHPUnit dentro del contenedor (SQLite en memoria de phpunit.xml). Sin el
# entrypoint de producción: config:cache dejaría a las pruebas con la configuración fija.
FROM app AS test
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
USER www-data
RUN composer install --no-interaction --no-progress --prefer-dist \
    && cp .env.example .env \
    && php artisan key:generate --ansi
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php", "vendor/bin/phpunit"]

# ---------------------------------------------------------------- por defecto
# La última etapa es la que se construye sin --target: tiene que ser la de producción.
FROM app AS produccion
