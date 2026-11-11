#!/bin/bash
# Lo dispara systemd (OnFailure) cuando respaldo-nube.service termina con error: avisa por correo.
DIR=$(dirname "$(readlink -f "$0")")
. "$DIR/comun.sh"
ult=$(ls -t "$LOGS"/respaldo-nube-*.log 2> /dev/null | head -1)
motivo=""
[ -n "$ult" ] && motivo=$(grep -h -o "RESPALDO FALLO.*" "$ult" | head -1)
"$DIR/avisar.sh" "FALLÓ el respaldo a Google Drive" \
  "El respaldo a Drive terminó con error (${motivo:-sin detalle}). Revisar en el servidor: journalctl -u respaldo-nube y $LOGS."
