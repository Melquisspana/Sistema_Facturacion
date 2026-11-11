#!/bin/bash
# Valores comunes del respaldo a la nube. Lo cargan los demás scripts.
[ -r /etc/default/respaldo-nube ] && . /etc/default/respaldo-nube
BASE=${BASE:-/srv/facturacion}
COMPOSE_DIR=${COMPOSE_DIR:-$BASE/app}
LARAVEL_ENV=${LARAVEL_ENV:-$BASE/env/laravel.env}
RCLONE_DIR=${RCLONE_DIR:-$BASE/rclone}
VOLUMEN_STORAGE=${VOLUMEN_STORAGE:-facturacion_app-storage}
FIRMADOR_DIRS=${FIRMADOR_DIRS:-"$BASE/firmador/jar $BASE/certificados/produccion"}
CONFIG_ARCHIVOS=${CONFIG_ARCHIVOS:-"$BASE/env/laravel.env $COMPOSE_DIR/.env.compose"}
CONFIG_DIRS=${CONFIG_DIRS:-"$BASE/tls"}
PREFIJO_VOLCADO=${PREFIJO_VOLCADO:-facturacion}
REMOTO_CIFRADO=${REMOTO_CIFRADO:-drive_cifrado}
REMOTO_RESCATE=${REMOTO_RESCATE:-gdrive}
CARPETA_RESCATE=${CARPETA_RESCATE:-FacturacionRescate}
VIGIA_HORAS=${VIGIA_HORAS:-8}
LOGS=/var/log/respaldo-nube
export RCLONE_CONFIG=$RCLONE_DIR/rclone.conf
export RCLONE_PASSWORD_COMMAND="cat $RCLONE_DIR/clave"
dc() { (cd "$COMPOSE_DIR" && docker compose --env-file .env.compose "$@"); }
