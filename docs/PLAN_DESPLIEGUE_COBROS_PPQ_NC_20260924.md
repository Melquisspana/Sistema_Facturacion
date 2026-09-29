# Pase de Cobros, PPQ y NC al servidor — preparación 24/09/2026

El usuario autorizó desplegar el módulo por SSH sobre Tailscale. **El pase terminó el 24/09/2026** y quedó comprobado en el servidor. La evidencia final está en [`RESULTADO_DESPLIEGUE_COBROS_PPQ_NC_20260924.md`](RESULTADO_DESPLIEGUE_COBROS_PPQ_NC_20260924.md). Los apartados siguientes conservaron el plan original como referencia; el estado real prevalece sobre las hipótesis iniciales.

## Estado comprobado en desarrollo

- Cobros y PPQ: 589 pruebas / 2.998 aserciones correctas tras añadir el XLSX de cinco columnas para «Solicitud de Quedan» desde un lote PPQ.
- El lote local #9 generó 38 CCF y excluyó 8 NC. El XLSX de `tmp/ppq-quedan-lote-9-EJEMPLO-NO-SUBIR.xlsx` es para inspección, no para presentarlo: el lote histórico ya tiene pagos registrados.
- La base local no muestra migraciones pendientes. Esto **no dice nada** sobre las migraciones del servidor.
- El HEAD local es `15b50e74884321da496037fc0c0e81075d0f2b11`, pero el árbol de trabajo contiene alrededor de 198 entradas modificadas o nuevas, incluidas tareas ajenas de Gastos, Planilla, invalidación DTE y firmware. No copiar el árbol completo ni hacer un `git pull` suponiendo que estos cambios estén publicados.

## Lectura inicial indispensable en el servidor

1. Confirmar identidad del equipo, carpeta realmente servida y versión de Git; anotar cualquier modificación local para preservarla.
2. Consultar `migrate:status`, versión de PHP/Composer/Node, presencia de `vendor`, `public/build` y `public/storage`, y estado del servicio de colas/planificador. No ejecutar pruebas PHPUnit contra su base operativa.
3. Consultar el perfil documental vigente de Calleja y el formato NC que ya aceptó el cliente. No cambiarlo por inferencia desde la base local.
4. Identificar las diferencias exactas entre servidor, HEAD y desarrollo para construir una entrega **solo del módulo**, incluidos archivos compartidos y assets necesarios. Excluir Gastos, Planilla, invalidación DTE ajena y `firmware/asistencia/asistencia.ino`.

## Antes de escribir en el servidor

Preparar y verificar un paquete con manifiesto/hash de archivos; ensayarlo en una copia aislada que reproduzca el estado del servidor. Hacer respaldo puntual verificado de base y archivos que se sustituirán, guardar el estado de retorno del código y comprobar espacio disponible. Evaluar una ventana breve de mantenimiento según el resultado de `migrate:status` y los cambios de esquema reales. Si el servidor tiene migraciones inesperadas o archivos modificados, detener la aplicación del paquete y resolver el desvío antes de seguir.

## Pase y verificación

Aplicar solo el paquete revisado y las migraciones que correspondan al alcance confirmado; actualizar autoload, build y cachés cuando los archivos lo requieran, y renovar workers solo si el código que ejecutan cambió. Mantener desactivadas las automatizaciones nuevas que no estén ya activas. Comprobar dashboard, Cobros, lote PPQ existente, descarga del nuevo XLSX, NC con el formato aceptado, salud del sistema y logs. La aceptación del archivo por el portal de Calleja se verifica con el usuario; descargarlo no lo presenta.

Si falla una comprobación crítica, restaurar los archivos anteriores y, si hubo migración con datos afectados, usar el respaldo de base verificado. No aplicar un rollback destructivo de migraciones por reflejo. Registrar resultado y diferencias finales en esta tarea.
