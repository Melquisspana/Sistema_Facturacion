#!/bin/bash
# Respaldo a Google Drive, cifrado con rclone crypt (versión Linux del respaldo-nube.ps1 de Windows).
# Sube los documentos (storage/app/private, sin los ZIP de spatie), la configuración crítica,
# el firmador y un volcado propio de la base. Lo corre respaldo-nube.timer 4 veces al día:
# la corrida de las 02:xx (o con --completo) compara cada archivo cifrado (cryptcheck, lento);
# las de día solo comparan tamaños. Si falla, systemd corre respaldo-nube-fallo.service (aviso
# por correo). No imprime secretos. Configuración: /etc/default/respaldo-nube.
set -euo pipefail
umask 077
. "$(dirname "$(readlink -f "$0")")/comun.sh"

VOL=$(docker volume inspect -f '{{.Mountpoint}}' "$VOLUMEN_STORAGE")
PRIVADO=$VOL/app/private
APP_NAME=$(grep -m1 '^APP_NAME=' "$LARAVEL_ENV" | cut -d= -f2- | tr -d "\"'")
EXCLUIR_ZIP="/${APP_NAME}/*.zip"
RUN=$(date +%Y%m%d-%H%M%S)-$(openssl rand -hex 4)
ANIO=$(date +%Y)
MES=$(date +%m)
DIA=$(date +%d)
MESES=(Enero Febrero Marzo Abril Mayo Junio Julio Agosto Septiembre Octubre Noviembre Diciembre)
MESRUTA="$MES-${MESES[$((10#$MES - 1))]}"
VER="$ANIO/$MES/$DIA/$RUN"
install -d -m 0750 "$LOGS"
LOG=$LOGS/respaldo-nube-$RUN.log
TRABAJO=$RCLONE_DIR/trabajo/$RUN
DUMPS=$BASE/backups/nube
C=$REMOTO_CIFRADO
log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*" >> "$LOG"; }
falla() { log "RESPALDO FALLO: $1"; echo "RESPALDO FALLO: $1" >&2; exit 1; }
trap 'falla "línea $LINENO"' ERR
exec 9> "$RCLONE_DIR/.lock"
flock -n 9 || { echo "Ya hay un respaldo corriendo" >&2; exit 1; }
rc() {
  local etiqueta=$1
  shift
  if rclone "$@" --stats 0 >> "$LOG" 2>&1; then log "OK $etiqueta"; else falla "$etiqueta"; fi
}

T0=$(date +%s)
COMPLETO=no
if [ "$(date +%H)" = 02 ] || [ "${1:-}" = --completo ]; then COMPLETO=si; fi
log "INICIO RESPALDO RUN=$RUN HOST=$(hostname) COMPLETO=$COMPLETO"
for p in "$RCLONE_CONFIG" "$RCLONE_DIR/clave" "$RCLONE_DIR/RECUPERACION-RCLONE.txt" "$PRIVADO" $CONFIG_ARCHIVOS $CONFIG_DIRS $FIRMADOR_DIRS; do
  [ -e "$p" ] || falla "falta $p"
done
ESPERADOS=$(printf '%s:\n%s:\n' "$REMOTO_CIFRADO" "$REMOTO_RESCATE" | sort | tr '\n' ' ')
[ "$(rclone listremotes | sort | tr '\n' ' ')" = "$ESPERADOS" ] || falla "los remotos de rclone no son $REMOTO_CIFRADO y $REMOTO_RESCATE"
log "REMOTOS OK"

# Volcado propio, transaccional y sin bloquear, dentro del contenedor de MySQL.
install -d -m 0750 -o 33 -g 33 "$DUMPS"
DUMP=$DUMPS/$PREFIJO_VOLCADO-$RUN.sql
dc exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --skip-lock-tables --set-gtid-purged=OFF --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --no-tablespaces "$MYSQL_DATABASE"' > "$DUMP"
[ "$(stat -c %s "$DUMP")" -gt 1024 ] || falla "volcado demasiado chico"
tail -n 30 "$DUMP" | grep -q 'Dump completed' || falla "volcado sin el marcador final"
log "DUMP OK BYTES=$(stat -c %s "$DUMP") SHA256=$(sha256sum "$DUMP" | cut -c1-64)"

# Configuración crítica y firmador: una copia de trabajo, para subir una foto fija.
install -d -m 0700 "$TRABAJO/Configuracion-critica" "$TRABAJO/Firmador"
for f in $CONFIG_ARCHIVOS; do cp "$f" "$TRABAJO/Configuracion-critica/$(basename "$f" | sed 's/^\.//')"; done
for d in $CONFIG_DIRS; do cp -r "$d" "$TRABAJO/Configuracion-critica/"; done
for d in $FIRMADOR_DIRS; do cp -r "$d" "$TRABAJO/Firmador/"; done

O=(--create-empty-src-dirs --metadata)
rc "COPY Archivos-fiscales" copy "$PRIVADO" "$C:Produccion/Actual/Archivos-fiscales" --exclude "$EXCLUIR_ZIP" --backup-dir "$C:Versiones/$VER/Archivos-fiscales" "${O[@]}"
rc "COPY Configuracion-critica" copy "$TRABAJO/Configuracion-critica" "$C:Produccion/Actual/Configuracion-critica-linux" --backup-dir "$C:Versiones/$VER/Configuracion-critica-linux" "${O[@]}"
rc "COPY Firmador" copy "$TRABAJO/Firmador" "$C:Produccion/Actual/Firmador-linux" --backup-dir "$C:Versiones/$VER/Firmador-linux" "${O[@]}"
rc "COPY Dump" copy "$DUMP" "$C:Produccion/$ANIO/$MESRUTA/Base-de-datos" --metadata

# Rescate: la configuración cifrada de rclone y el instructivo, en la carpeta visible de Drive.
install -d -m 0700 "$RCLONE_DIR/rescate/$RUN"
R="$REMOTO_RESCATE:$CARPETA_RESCATE"
rc "REFRESH OAuth Rescate" lsf "$R" --max-depth 1
cp "$RCLONE_CONFIG" "$RCLONE_DIR/rescate/$RUN/rclone.conf"
rc "COPY Rescate rclone.conf" copyto "$RCLONE_DIR/rescate/$RUN/rclone.conf" "$R/rclone.conf" --backup-dir "$R/Versiones/$VER/Rescate" --metadata
rc "COPY Instructivo rescate" copyto "$RCLONE_DIR/RECUPERACION-RCLONE.txt" "$R/RECUPERACION-RCLONE.txt" --backup-dir "$R/Versiones/$VER/Rescate" --metadata
rc "CHECK Rescate" check "$RCLONE_DIR/rescate/$RUN" "$R" --one-way --include /rclone.conf

# Lo que se creó mientras corría el respaldo se sube en la próxima corrida: no se compara ahora.
EDAD="$(($(date +%s) - T0 + 60))s"
if [ "$COMPLETO" = si ]; then
  rc "CRYPTCHECK Archivos-fiscales" cryptcheck "$PRIVADO" "$C:Produccion/Actual/Archivos-fiscales" --exclude "$EXCLUIR_ZIP" --min-age "$EDAD" --one-way
else
  rc "CHECK tamaños Archivos-fiscales" check "$PRIVADO" "$C:Produccion/Actual/Archivos-fiscales" --exclude "$EXCLUIR_ZIP" --min-age "$EDAD" --size-only --one-way
fi
rc "CRYPTCHECK Configuracion-critica" cryptcheck "$TRABAJO/Configuracion-critica" "$C:Produccion/Actual/Configuracion-critica-linux" --one-way
rc "CRYPTCHECK Firmador" cryptcheck "$TRABAJO/Firmador" "$C:Produccion/Actual/Firmador-linux" --one-way
rc "CRYPTCHECK Dump" cryptcheck "$DUMPS" "$C:Produccion/$ANIO/$MESRUTA/Base-de-datos" --include "/$PREFIJO_VOLCADO-$RUN.sql" --one-way

# Limpieza local: la carpeta de trabajo; volcados y rescates de más de 14 días; registros de más de 90.
rm -rf "$TRABAJO"
find "$DUMPS" -name "$PREFIJO_VOLCADO-*.sql" -mtime +14 -delete
find "$RCLONE_DIR/rescate" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
find "$LOGS" -name 'respaldo-nube-*.log' -mtime +90 -delete
trap - ERR
log "RESPALDO COMPLETADO EXIT=0"
echo "RESPALDO COMPLETADO ($RUN)"
