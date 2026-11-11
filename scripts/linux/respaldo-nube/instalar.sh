#!/bin/bash
# Instala el respaldo a la nube en un servidor Linux con la instalación de Docker de
# docs/MIGRACION_LINUX_DOCKER.md. Uso, desde esta carpeta: sudo bash instalar.sh
# - rclone oficial, verificado con el SHA256SUMS de la misma versión (si ya hay uno, se deja);
# - los scripts en /usr/local/lib/respaldo-nube y las unidades de systemd;
# - /etc/default/respaldo-nube desde el ejemplo, solo si no existe.
# No copia ni crea claves: rclone.conf, RECUPERACION-RCLONE.txt y la contraseña maestra los pone
# a mano quien administra el servidor (ver README.md). Los temporizadores quedan apagados hasta
# que estén esas tres cosas.
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"
[ "$(id -u)" = 0 ] || { echo "Correr con sudo."; exit 1; }

if ! command -v rclone > /dev/null; then
  T=$(mktemp -d)
  V=$(curl -fsSL https://downloads.rclone.org/version.txt | awk '{print $2}')
  D="rclone-$V-linux-amd64.deb"
  curl -fsSL -o "$T/$D" "https://downloads.rclone.org/$V/$D"
  curl -fsSL -o "$T/SHA256SUMS" "https://downloads.rclone.org/$V/SHA256SUMS"
  (cd "$T" && grep " $D\$" SHA256SUMS | sha256sum -c -)
  dpkg -i "$T/$D" > /dev/null
  rm -rf "$T"
fi
rclone version | head -1

L=/usr/local/lib/respaldo-nube
install -d -m 0755 "$L"
install -m 0755 comun.sh respaldo-nube.sh avisar.sh respaldo-nube-fallo.sh respaldo-nube-vigia.sh poner-clave.sh "$L/"
install -m 0644 avisar.php "$L/"
install -m 0644 respaldo-nube.service respaldo-nube.timer respaldo-nube-fallo.service \
  respaldo-nube-vigia.service respaldo-nube-vigia.timer /etc/systemd/system/
[ -f /etc/default/respaldo-nube ] || install -m 0644 respaldo-nube.env.example /etc/default/respaldo-nube
. "$L/comun.sh"
install -d -m 0700 "$RCLONE_DIR" "$RCLONE_DIR/trabajo" "$RCLONE_DIR/rescate"
systemctl daemon-reload

falta=""
for f in rclone.conf RECUPERACION-RCLONE.txt clave; do [ -f "$RCLONE_DIR/$f" ] || falta="$falta $f"; done
if [ -n "$falta" ]; then
  echo "Instalado, con los temporizadores APAGADOS. Falta en $RCLONE_DIR:$falta (ver README.md)."
else
  systemctl enable --now respaldo-nube.timer respaldo-nube-vigia.timer
  systemctl list-timers 'respaldo-nube*' --no-pager
fi
