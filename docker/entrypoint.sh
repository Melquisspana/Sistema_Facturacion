#!/bin/sh
# Arranque de los contenedores de la aplicación (web, worker y programador).
# Ver docs/MIGRACION_LINUX_DOCKER.md §3.1.
#
#  - Como root (web): deja storage/ y backups/ con dueño www-data y corre lo de abajo
#    como www-data; después Apache arranca y atiende con www-data.
#  - Como www-data (worker, programador, comandos a mano): corre lo de abajo directo.
#
# Cachea la configuración, porque el .env se monta al arrancar y no existe al construir.
# NUNCA corre migrate: las migraciones se corren a mano y con respaldo.
set -eu

cd /var/www/html

: "${APACHE_DOMINIO_PUBLICO:=facturacion.example.com}"
: "${APACHE_HOSTS_EXTRA:=}"
export APACHE_DOMINIO_PUBLICO APACHE_HOSTS_EXTRA

como_www_data() {
    if [ "$(id -u)" = "0" ]; then
        setpriv --reuid=www-data --regid=www-data --init-groups "$@"
    else
        "$@"
    fi
}

if [ "$(id -u)" = "0" ]; then
    # Un volumen o carpeta del host nuevos pueden venir vacíos o con otro dueño
    # (por ejemplo, después de copiar storage desde Windows).
    mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs backups
    find storage backups \! -user www-data -exec chown www-data:www-data {} +
fi

# Sin .env legible, Laravel arranca callado con la configuración por defecto (sin
# APP_KEY ni DB_HOST): se corta acá para que el motivo salga en docker compose logs.
if [ ! -f .env ] || ! como_www_data test -r .env; then
    echo "facturacion-entrypoint: /var/www/html/.env falta, no es un archivo o www-data (uid 33) no lo puede leer; en el host: chown root:33 laravel.env && chmod 0640 laravel.env" >&2
    exit 1
fi

como_www_data php artisan config:cache --no-ansi

if [ ! -e public/storage ]; then
    como_www_data php artisan storage:link --no-ansi
fi

exec "$@"
