# Formatos de NC: diagnóstico para la siguiente entrega (23/09/2026)

Revisión de lectura de Codex, sin implementación ni migraciones.

`NcExportacionController::index()` carga todas las NC pendientes y todas las ya exportadas, más los últimos 30 lotes. `NcExportacionService::pendientes()` y `yaExportadas()` terminan en `get()`. La pantalla muestra tres tablas extensas y el historial de archivos se corta en 30 sin paginación. El requerimiento del usuario es consultar un historial paginado de archivos/lotes, con detalle de notas por lote, y no cargar indefinidamente las NC históricas en la página principal.

`NcExportacionService::archivo()` vuelve a llamar al exportador en cada descarga. El lote guarda referencia, formato, nombre y sus items, pero `nc_exportaciones` no tiene hash ni ruta de una copia archivada. La prueba actual comprueba que dos generaciones dan las mismas **celdas** y que no incorporan notas nuevas; no comprueba igualdad de bytes ni cambios posteriores de plantilla/perfil/exportador. Por ello la afirmación visible de que la redescarga entrega el archivo «tal como se entregó» no está demostrada. El formato de NC que el usuario dijo que Calleja acepta debe preservarse; corregir la redescarga no debe alterar columnas ni importes.

Siguiente encargo recomendado a Claude Opus 5.5, después de cerrar los dos ajustes visuales de Cobros:

1. Separar la lista de NC pendientes del historial de archivos; paginar este último por cliente, con una fila por lote (archivo, fecha, cantidad, monto) y vista de detalle de las NC incluidas. Mantener filtros claros y no reutilizar `page` para listas independientes.
2. Diseñar el archivo original de NC como evidencia persistida (hash/ruta, almacenamiento verificado) y una migración **solo de desarrollo** si es necesaria. No ejecutar la migración por iniciativa de Codex. Primer archivo descargado: archivar los bytes; siguientes descargas: verificar y devolver esa copia. Ante copia ausente/corrupta, no sustituirla silenciosamente. Los lotes anteriores sin archivo archivado necesitan tratamiento honesto: la base no prueba qué bytes se entregaron antes.
3. Reducir la información secundaria en la vista principal, conservar acceso a notas y trazabilidad, y mantener el registro de quién/cuándo se descargó sin destacar el contador. Probar claro/oscuro, móvil, filtros, permisos y no duplicación de NC.

Se mantiene independiente la etapa posterior de conciliación TXT y pagos. Esta revisión no modifica producción ni el formato fiscal aceptado.
