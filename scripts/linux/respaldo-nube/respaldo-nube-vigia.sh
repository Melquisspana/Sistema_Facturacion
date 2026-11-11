#!/bin/bash
# Si pasaron más de VIGIA_HORAS sin un respaldo a Drive completo, avisa por correo.
# Cubre lo que OnFailure no ve: que el temporizador no haya corrido.
DIR=$(dirname "$(readlink -f "$0")")
. "$DIR/comun.sh"
ult=$(grep -l "RESPALDO COMPLETADO" "$LOGS"/respaldo-nube-*.log 2> /dev/null | xargs -r ls -t | head -1)
if [ -z "$ult" ] || [ $(($(date +%s) - $(stat -c %Y "$ult"))) -gt $((VIGIA_HORAS * 3600)) ]; then
  cuando=nunca
  [ -n "$ult" ] && cuando=$(date -r "$ult" '+%d/%m/%Y %H:%M')
  "$DIR/avisar.sh" "Sin respaldo a Google Drive hace más de $VIGIA_HORAS horas" \
    "El último respaldo a Drive completo es de: $cuando. Revisar en el servidor: systemctl list-timers 'respaldo-nube*' y $LOGS."
fi
