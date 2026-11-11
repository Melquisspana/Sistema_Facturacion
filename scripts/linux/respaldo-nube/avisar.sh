#!/bin/bash
# avisar.sh "asunto" "detalle": manda un correo a ALERTAS_CORREO del sistema por el contenedor web,
# con la configuración SMTP del Centro de Configuración (la misma de los envíos del sistema).
set -uo pipefail
DIR=$(dirname "$(readlink -f "$0")")
. "$DIR/comun.sh"
dc cp "$DIR/avisar.php" web:/tmp/avisar.php > /dev/null 2>&1 &&
  dc exec -T -u www-data web php /tmp/avisar.php "$1" "$2"
r=$?
dc exec -T -u root web rm -f /tmp/avisar.php > /dev/null 2>&1
exit $r
