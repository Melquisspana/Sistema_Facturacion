# Pase de Cobros, PPQ y NC al servidor — 24/09/2026

## Resultado

El usuario autorizó el despliegue. Se instaló el módulo en `<servidor>`, proyecto `C:\laragon\www\Facturacion`, rama `master`, HEAD `15b50e74884321da496037fc0c0e81075d0f2b11`. Se trabajó por SSH sobre Tailscale y **no se hizo commit ni push**. La aplicación estuvo en mantenimiento durante la copia, la migración y el build; el procedimiento terminó con `artisan up` y `DEPLOYMENT=COMPLETE`.

El servidor tenía **76 entradas locales modificadas o nuevas** antes del pase. No se hizo `git pull`, `reset`, ni una copia total del árbol. Se compararon 98 archivos candidatos por SHA-256: 45 iguales, 38 distintos y 15 ausentes. El paquete final tuvo 52 archivos (tres ya idénticos: `DteController.php`, navegación y `routes/console.php`). Se separaron los cambios de Gastos, Planilla e invalidación DTE de los archivos compartidos. Las 15 combinaciones limpias quedaron idénticas al paquete local; los 19 casos restantes se revisaron contra las versiones del servidor con autorización expresa del usuario. El firmware ajeno, `C:\rclone`, `.env`, respaldos y datos de clientes no entraron en la copia.

El paquete temporal tuvo SHA-256 `58066E6C8C2610E7C2E18ABEA59BD440F32275C6E55F26A31033F4E16B86C015`. Pasaron la sintaxis de sus 50 archivos PHP y el prechequeo remoto de hashes/estado. El perfil documental del cliente por NIT coincidió con un solo cliente activo, código proveedor `000123`, formato NC `carga_masiva_nc_v1`; **no se modificó el perfil ni el formato NC aceptado**.

## Respaldo y cambio de esquema

Antes de instalar, `backup:mysql-diario --origen=manual` generó `backups/auto-2026-09-24_215312.sql` (3.278.357 bytes, SHA-256 `45C1FCA51BF775AB57EE948A63350EE6FEC8D08E690B1C348CB7E19DA67C3F72`) en el servidor. El comando aplicó su retención normal y eliminó **un** respaldo automático vencido; no tocó respaldos manuales.

Los archivos previos y los tres archivos del build se guardaron en `C:\Users\<usuario>\codex-calleja-predeploy-files-20260924.zip`, SHA-256 `00B0B418C50FD7CC006B133474427E9A2EB1AEE58D55B2DDB0487DF848170079`, con el manifiesto adyacente `codex-calleja-predeploy-manifest-20260924.json`. Contiene 40 archivos existentes; 15 eran nuevos y constan como ausentes en el manifiesto. Es el punto de retorno de código y assets; la migración nueva es aditiva y nullable, por lo que un eventual retorno de código no exige borrar columnas por reflejo.

Después de copiar el paquete, `migrate:status` mostró **solo una** pendiente: `2026_09_24_120000_add_copia_archivada_a_nc_exportaciones`. Se aplicó con `migrate --force`; terminó `DONE` y el chequeo posterior la mostró `Ran`, con cero migraciones pendientes. No se crearon migraciones adicionales ni se ejecutaron las de Gastos o Planilla.

## Comprobaciones posteriores

- Vite produjo el build de producción; `optimize:clear` y `view:cache` pasaron.
- `route:list` incluyó `cobros.index`, la vista previa/ficha de solicitudes, `ppq.lotes.quedan` y la ficha de formatos NC (467 rutas en total).
- Los 52 archivos instalados coincidieron con el manifiesto: **cero hashes distintos**.
- HTTP del servidor: `/login` respondió 200 y `/cobros` sin sesión respondió 302, como corresponde a una ruta protegida.
- Desde la computadora de desarrollo, `https://facturacion.example.com/login` respondió 200 con verificación TLS correcta.
- La generación temporal del archivo de quedan desde el lote PPQ #9 produjo **38 filas**, hoja `Hoja3`, los cinco encabezados exactos y un XLSX no vacío. El lote y su cantidad de items quedaron sin cambios; el temporal se eliminó. Como es un lote histórico con pagos registrados, **no subir ese archivo como una nueva solicitud**.
- El perfil NC aceptado sigue en `carga_masiva_nc_v1`. No se hizo una descarga real de NC ni se subió ningún archivo al portal de Calleja desde esta revisión.

Los tres interruptores existentes de Cobros (`COBROS_ALTA_AUTO`, `COBROS_CORREO_ENABLED`, `COBROS_CORREO_AUTO`) están en `true` en el `.env` del servidor. El despliegue no los cambió; la alta y la lectura automática del buzón siguen sujetas a las tareas programadas ya configuradas.

La copia temporal de los 19 archivos traídos para resolver diferencias se eliminó del entorno de desarrollo. En el servidor se retiraron las carpetas y paquetes temporales del ensayo, conservando solo el ZIP y manifiesto de retorno y el respaldo de base. Se revocó la llave pública SSH temporal, se borró la llave privada local, se desactivó la regla de firewall temporal y se detuvo `sshd` .

Queda para revisión humana iniciar sesión en [`/ppq/lotes/9`](https://facturacion.example.com/ppq/lotes/9) y comprobar la presentación de los botones «Reporte PPQ» y «Archivo para portal de quedan», además de la bandeja de Cobros y el historial/formatos NC con su usuario. La aceptación del XLSX por el portal del cliente solo se puede constatar al cargar un **lote vigente y autorizado**, no con el #9 histórico.
