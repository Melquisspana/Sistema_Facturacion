# Respaldo a la nube (Linux)

Copia diaria cifrada a Google Drive con [rclone](https://rclone.org) `crypt`. Es la versión Linux del `respaldo-nube.ps1` que corría en Windows, con las mismas carpetas en Drive para que el historial siga en el mismo lugar.

## Qué sube

| En Drive (remoto cifrado) | Qué es |
|---|---|
| `Produccion/Actual/Archivos-fiscales` | `storage/app/private` del volumen de Docker, sin los ZIP de spatie (`<APP_NAME>/*.zip`) |
| `Produccion/Actual/Configuracion-critica-linux` | el `.env` de Laravel, el `.env.compose` y el certificado del 443 de la oficina |
| `Produccion/Actual/Firmador-linux` | el JAR del firmador y el certificado de firma de producción |
| `Produccion/<año>/<mes>/Base-de-datos` | un volcado propio de la base, transaccional |
| `Versiones/<año>/<mes>/<día>/<corrida>/…` | lo que cada corrida reemplazó o cambió (`--backup-dir`) |

En el remoto de rescate (visible, sin cifrar) va `FacturacionRescate/rclone.conf` (cifrado con la contraseña maestra) y el instructivo `RECUPERACION-RCLONE.txt`.

## Cuándo corre y cómo avisa

- `respaldo-nube.timer`: 02:30, 08:30, 12:30 y 17:30. La de las 02:30 compara cada archivo cifrado contra el local (`cryptcheck`, unos 40 minutos con ~24 000 archivos); las de día solo comparan tamaños (unos 5 minutos). Lo que se crea mientras corre se compara en la corrida siguiente.
- Si una corrida falla, `respaldo-nube-fallo.service` (por `OnFailure=`) manda un correo a `ALERTAS_CORREO`.
- `respaldo-nube-vigia.timer` (10:00, 15:00 y 20:00) avisa si pasaron más de 8 horas sin un respaldo completo. Cubre que el temporizador no haya corrido.
- El correo sale por el contenedor `web` con la configuración SMTP del Centro de Configuración (`avisar.php`), la misma de los envíos del sistema.
- Registros: `/var/log/respaldo-nube/` (90 días) y `journalctl -u respaldo-nube`.

## Instalar en un servidor nuevo

Requisitos: la instalación de Docker de `docs/MIGRACION_LINUX_DOCKER.md` corriendo, `ALERTAS_CORREO` en el `.env` de Laravel y el correo configurado en el sistema.

1. Desde esta carpeta: `sudo bash instalar.sh`. Instala rclone oficial (verificado con `SHA256SUMS`), los scripts en `/usr/local/lib/respaldo-nube`, las unidades de systemd y `/etc/default/respaldo-nube` desde `respaldo-nube.env.example`. Revisar ese archivo: las rutas por defecto son las del documento de migración.
2. Quien administra el servidor copia a mano, en `RCLONE_DIR` (por defecto `/srv/facturacion/rclone`, 0700, root):
   - `rclone.conf`, el cifrado (se baja de la carpeta de rescate de Drive si no hay otro), con permiso 0600;
   - `RECUPERACION-RCLONE.txt`.
3. La contraseña maestra la escribe esa persona: `sudo /usr/local/lib/respaldo-nube/poner-clave.sh`. No se muestra; queda en `RCLONE_DIR/clave` (0400, root) y el script comprueba que abra la configuración.
4. `sudo bash instalar.sh` otra vez: con las tres cosas en su lugar, enciende los temporizadores.
5. Primera corrida y revisión: `sudo systemctl start respaldo-nube.service` y `ls -t /var/log/respaldo-nube | head -1`. Tiene que terminar en `RESPALDO COMPLETADO EXIT=0`.
6. Probar el aviso: `sudo /usr/local/lib/respaldo-nube/avisar.sh "Prueba" "Correo de prueba del respaldo"`.

## Qué no hace

- No restaura. Para recuperar, seguir `RECUPERACION-RCLONE.txt` y probar con `scripts/linux/backup-restore-test.sh`.
- No guarda claves en el repositorio ni en este directorio: `rclone.conf` y la contraseña maestra viven solo en el servidor y en el lugar seguro donde las guarde quien administra.
