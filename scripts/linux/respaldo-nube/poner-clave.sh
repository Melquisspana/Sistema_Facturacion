#!/bin/bash
# Guarda la contraseña maestra de rclone (solo root, 0400). La escribe quien administra el servidor;
# no se muestra ni queda en el historial. Uso: sudo /usr/local/lib/respaldo-nube/poner-clave.sh
set -uo pipefail
. "$(dirname "$(readlink -f "$0")")/comun.sh"
IFS= read -r -s -p "Contraseña maestra de rclone: " P
echo
umask 077
printf '%s' "$P" > "$RCLONE_DIR/clave"
unset P
chmod 0400 "$RCLONE_DIR/clave"
if rclone listremotes > /dev/null 2>&1; then
  echo "CLAVE-OK"
else
  rm -f "$RCLONE_DIR/clave"
  echo "CLAVE-MAL: no abre $RCLONE_CONFIG. Intentá de nuevo."
  exit 1
fi
