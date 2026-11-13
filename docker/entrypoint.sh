#!/bin/sh
# Arranque de los contenedores de la aplicación (web, worker y programador).
# Ver docs/MIGRACION_LINUX_DOCKER.md §3.1.
#
#  - Como root (web): deja storage/ y backups/ con dueño www-data y corre lo de abajo
#    como www-data; después Apache arranca y atiende con www-data.
#  - Como www-data (worker, programador, comandos a mano): corre lo de abajo directo.
#
# Cachea la configuración, porque el .env se monta al arrancar y no existe al construir, y
# también las rutas y los eventos.
# NUNCA corre migrate: las migraciones se corren a mano y con respaldo.
set -eu

cd /var/www/html

: "${APACHE_DOMINIO_PUBLICO:=facturacion.example.com}"
: "${APACHE_HOSTS_EXTRA:=}"
: "${LAN_SUBNET:=}"
: "${LAN_HOST:=}"
export APACHE_DOMINIO_PUBLICO APACHE_HOSTS_EXTRA LAN_SUBNET LAN_HOST

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

# Rutas y eventos armados una vez al arrancar, no en cada pedido. Es solo velocidad: si
# fallaran, se limpian y la aplicación arranca igual (resuelve las rutas en cada pedido).
como_www_data php artisan route:cache --no-ansi || {
    echo "facturacion-entrypoint: route:cache falló; sigue sin caché de rutas" >&2
    como_www_data php artisan route:clear --no-ansi || true
}
como_www_data php artisan event:cache --no-ansi || {
    echo "facturacion-entrypoint: event:cache falló; sigue sin caché de eventos" >&2
    como_www_data php artisan event:clear --no-ansi || true
}

if [ ! -e public/storage ]; then
    como_www_data php artisan storage:link --no-ansi
fi

# HTTPS de la red local (docker/apache/facturacion.conf). Se enciende solo si están el
# certificado, la llave, LAN_SUBNET y LAN_HOST, si la llave es la del certificado y no
# tiene contraseña, y si Apache acepta esa configuración. Si no, Apache arranca igual
# con el 80 y el motivo queda en docker compose logs web.
https_red_local() {
    tls=/etc/facturacion/tls
    if [ ! -e "$tls/lan.crt" ] && [ ! -e "$tls/lan.key" ] && [ -z "$LAN_SUBNET" ] && [ -z "$LAN_HOST" ]; then
        return 1
    fi
    falta=""
    [ -f "$tls/lan.crt" ] || falta="$falta $tls/lan.crt"
    [ -f "$tls/lan.key" ] || falta="$falta $tls/lan.key"
    [ -n "$LAN_SUBNET" ] || falta="$falta LAN_SUBNET"
    [ -n "$LAN_HOST" ] || falta="$falta LAN_HOST (WEB_IP_LAN en .env.compose)"
    if [ -n "$falta" ]; then
        echo "facturacion-entrypoint: HTTPS de la red local apagado; falta:$falta" >&2
        return 1
    fi
    clave_publica="$(openssl x509 -noout -pubkey -in "$tls/lan.crt" 2>/dev/null || true)"
    if [ -z "$clave_publica" ] || [ "$clave_publica" != "$(openssl pkey -pubout -passin pass: -in "$tls/lan.key" 2>/dev/null || true)" ]; then
        echo "facturacion-entrypoint: HTTPS de la red local apagado; lan.crt o lan.key no se pueden leer, no son pareja o la llave tiene contraseña" >&2
        return 1
    fi
    if ! salida="$(apache2ctl -t -D FACTURACION_LAN_HTTPS 2>&1)"; then
        echo "facturacion-entrypoint: HTTPS de la red local apagado; Apache no acepta la configuración: $salida" >&2
        return 1
    fi
}

if [ "${1:-}" = "apache2-foreground" ] && https_red_local; then
    set -- "$@" -D FACTURACION_LAN_HTTPS
fi

exec "$@"
