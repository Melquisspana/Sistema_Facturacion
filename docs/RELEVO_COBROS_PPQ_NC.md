# Próxima sesión: Cobros, PPQ y formatos de notas de crédito

Registro de requisitos del usuario y del estado reportado el 21/09/2026. Este documento conserva el alcance de la conversación; no certifica el estado actual del código ni del servidor. Verificarlo antes de actuar.

## Objetivo y reparto de trabajo

El usuario quiere un circuito digital sencillo para controlar los CCF por cliente, principalmente Calleja, preparar la solicitud de quedan y seguir su presentación y pago. Quiere que los datos de facturación, albaranes, NC, PPQ y formatos estén relacionados, sin capturarlos varias veces.

La nueva sesión se enfoca en este módulo. Codex conversa con el usuario, delimita tareas, diagnostica y revisa; Claude desarrolla y explica sus cambios mediante el puente local. El usuario no tiene que copiar respuestas entre ambos. Leer AGENTS.md y docs/COLABORACION_CODEX_CLAUDE.md. No se acordó invertir esos papeles.

El 22/09/2026 se completó la primera tarea de consulta y revisión con continuación por SessionId. El 23/09 se validó también la primera entrega de desarrollo con Claude Opus 5.5, revisión de Codex y pruebas; ver el cierre al final. El intento anterior había fallado por cuota. No hay automatización programada.

El usuario también desea que ambos revisen el proyecto en general, por etapas. Registrar problemas externos encontrados y tratarlos en otra fase; no convertir esta sesión en una reescritura o auditoría ilimitada de todo el sistema.

## Requisitos del usuario

### Circuito digital y documentos

- Mantener el selector por cliente; Calleja es la prioridad. No suponer que todas sus reglas aplican a los demás clientes.
- Incorporar los CCF del sistema al seguimiento y recoger automáticamente los albaranes que lleguen al correo, aprovechando el circuito existente. Distinguir importar el albarán de vincularlo al CCF.
- El papel físico no debe ser requisito para preparar el quedan. La disponibilidad digital y sus validaciones deben permitir identificar qué documentos están listos para presentar al cobro; eso no equivale a estar pagados.
- Investigar por qué aparecen sin albarán documentos que podrían tenerlo ya en PPQ. Recuperar y reutilizar los vínculos correctos, incluyendo los antiguos. Vincular automáticamente solo cuando la identidad esté suficientemente demostrada; no forzar por fecha, importe o simple coincidencia de sala. Colisiones y contradicciones deben quedar para revisión con explicación útil.
- Reconciliar el historial usando lotes, solicitudes, correos y TXT existentes. No presentar todo el histórico como deuda confirmada ni eliminar bloqueos sin evidencia. La falta de evidencia se debe distinguir de una deuda o una presentación pendiente confirmadas.
- Una NC creada en Facturación debe aportar a PPQ su CCF relacionado y sus datos de albarán ya guardados. No volver a pedirlos si están completos y válidos. No confundir el albarán de la NC con el albarán de entrega del CCF.

### Crear quedan y controlar cobros

- Acción principal clara para preparar el formato de quedan con los CCF aptos, revisar su contenido y descargarlo para subirlo a Calleja. Mostrar qué impide incluir los demás y cómo resolverlo.
- Usar la plantilla solicitada por el usuario: C:/Users/<usuario>/Desktop/FORMATO DE CARGA MASIVA QUEDAN.xlsx. Inspeccionarla antes de definir columnas. El Excel antiguo 000123202609040951.xlsx fue aportado como evidencia del circuito anterior, no como plantilla nueva.
- El usuario dice que Calleja ya aceptó el formato de NC, pero que PPQ sigue siendo el mismo. Verificar qué exportación está usando realmente y qué queda por adaptar. La aceptación del formato de NC no prueba la del formato de quedan.
- Archivo generado/descargado, presentado, recibido, observado y pagado son hechos diferentes. Mantener evidencias y no cambiar uno por inferencia del otro.
- Cargar el TXT recibido al pagar y mostrar qué documentos y montos confirma, qué diferencias quedan y cuáles no aparecen en esa evidencia. No declarar impagos por ausencia en un TXT ni contar dos veces una misma evidencia. Mantener revisión de pagos potencialmente repetidos entre archivos distintos.
- Revisar también Historial PPQ: relacionar documentos, NC, formato, acuse, observaciones y pago para que el usuario pueda entender cada envío y sus pendientes.
- Conservar Buscar CCF / NC como acceso secundario para casos externos/Conta; quitarlo del recorrido principal sin eliminar su funcionalidad.

### Importes y reglas de NC

- Investigar el descuadre que el usuario ve en PPQ con devoluciones AC04. Mostrar comparaciones y descuentos con significado claro, sin alterar importes para que visualmente coincidan.
- Según lo desarrollado anteriormente para Calleja, devolución/faltante AC04 no hereda el descuento del CCF; avería AC02 sí aplica su regla correspondiente. Verificar la implementación vigente y el recorrido completo, no solo el total de emisión.
- Ejemplo reportado y probado anteriormente: AC04 3874, PDF 26-08-0207-00-003874-AC04-0001.PDF, Santa Rosa de Lima: gravado 0.98 + IVA 0.13 = 1.11, descuento 0.00; el resultado incorrecto era 1.05 al heredar 5 %. Avería de referencia: 2.89 - 0.14 = 2.75 + IVA 0.36 = 3.11. Confirmar documentos reales antes de extrapolar estas cifras.
- Mantener separadas las magnitudes de CCF, albarán, NC, descuentos, retenciones y pago cuando corresponda; explicar diferencias legítimas. Evitar descontar dos veces una NC y conservar las reglas fiscales ya comprobadas.
- Preservar el formato de NC que el usuario confirmó aceptado. No recalcular ni modificar DTE emitidos.

### Orden y presentación visual

- No cargar aproximadamente 200 documentos completos en una sola pantalla. Introducir paginación real, búsqueda, filtros útiles por cliente/sala/fecha/estado y vistas que distingan lo listo, lo que requiere completar, lo presentado y lo pagado. Los nombres y la organización final se definen tras revisar el uso real.
- La sala y el documento deben identificarse fácilmente. Evitar enormes columnas de códigos y párrafos repetidos que obliguen a desplazar toda la tabla horizontalmente.
- Reducir explicaciones y advertencias repetidas; mostrar un motivo corto por fila y el detalle cuando se abre el documento. Conservar información y trazabilidad sin saturar la vista principal.
- Revisar modo claro y oscuro de todas las pantallas tocadas. Las capturas muestran filas históricas gris claro con texto amarillo/violeta de contraste deficiente en modo oscuro.
- En /ppq/nc-exportaciones, separar pendientes de formatos ya generados. Historial paginado con una fila por archivo/lote y nombre, fecha, cliente, cantidad y monto; detalle de sus notas al abrirlo. No listar indefinidamente todas las NC históricas en la página principal.
- Permitir descargar de nuevo el archivo original. Mantener registro de quién y cuándo, pero quitar el contador de descargas de la información principal. Evitar duplicar lotes o incluir de nuevo una NC ya exportada por una simple redescarga.

## Estado conocido y límites de confianza

Los siguientes son informes del servidor recibidos del usuario, no una comprobación actual desde desarrollo:

- Producción sirve /cobros como Seguimiento de CCF, además de /ppq, /ppq/lotes y /ppq/nc-exportaciones. No confundir /rutas-cobros (operación física/rutas) con el nuevo seguimiento.
- Se aplicaron 119 vínculos considerados seguros; había siete casos en revisión y 26 CCF sin albarán en esa auditoría. Las cifras posteriores cambian con el uso y las NC: no comparar contadores de poblaciones distintas como si fueran iguales.
- Alta horaria y lectura de correo cada 30 minutos activadas y verificadas mediante la tarea Windows existente bajo SYSTEM. Vinculación automática permanece apagada. No apagar ni duplicar el planificador o respaldos.
- Consulta de acuses/observaciones: from:quedan@cliente-ejemplo.test. Los documentos enviados por documentos@cliente-ejemplo.test y los albaranes tienen que revisarse en su circuito correspondiente; no asumir que esa consulta recoge todo.
- 22 correos registrados, dos eventos de observaciones, cinco desconocidos para revisión; sin solicitudes generadas ni pagos aplicados por esas intervenciones. Acuses antiguos quedaron sin asociar porque sus solicitudes no existen en el seguimiento nuevo.
- REF 31001 ya interpreta dos filas correctamente: CCF terminado en 119, documento 106, FALTA NOTA DE CREDITO; terminado en 1186, NO APARECE EN REPORTERIA, pendiente de localizar. No hereda archivo o fecha de un mensaje citado. No volver a atribuir ambas observaciones al mismo documento.
- La columna motivo de cobro_correos se amplió a TEXT con nueva migración 2026_09_21_131000_ampliar_motivo_cobro_correos_a_text.php en producción. El archivo también se incorporó a desarrollo; no se había ejecutado allí al comunicar ese traslado. Verificar el estado local antes de cualquier propuesta de migración.
- Los históricos conservan bloqueos. En un cierre había 193 documentos y 166 históricos; en las últimas capturas, 194 y 167. Es una fotografía, no un inventario vigente.

Consultar docs/COBROS_CALLEJA.md, docs/CIERRE_NC_COBROS.md, docs/CORRECCIONES_COBROS_20260920.md y docs/PPQ_ALBARANES_AUTOMATICO.md, contrastando sus afirmaciones con el código actual. Existen cambios ajenos sin commit, entre otros de Gastos, Planilla y Asistencia. Preservarlos.

El servidor y esta máquina pueden tener la misma ruta C:/laragon/www/Facturacion. Identificar entorno local sin divulgar secretos; la coincidencia de ruta no demuestra que se esté en producción. No tocar producción ni C:/rclone, ni hacer commit/push/despliegue por esta documentación.

## Orden propuesto para la siguiente sesión

1. Leer este relevo y las reglas; comprobar el puente con Claude mediante una tarea de análisis pequeña. Registrar respuesta, SessionId y limitaciones reales.
2. Ambos revisan el circuito actual de Cobros/PPQ/NC por turnos. Primera tarea sugerida a Claude: seguir desde una NC interna hasta PPQ/exportación e identificar dónde se vuelven a pedir datos ya guardados, con archivos y evidencia, sin editar. Codex contrasta el hallazgo.
3. Completar diagnóstico del origen de albaranes, vínculos históricos y descuadres AC04. Dibujar el recorrido de uso y proponer una organización concreta al usuario, sin prometer que todos los faltantes tienen evidencia recuperable.
4. Implementar en entregas pequeñas con Claude: primero datos reutilizados y reglas correctas, después bandeja/preparación de quedan e historiales, con orden visual y modo oscuro. Ajustar el orden si el diagnóstico lo justifica. Codex revisa cada entrega y prueba casos representativos.
5. Verificar comportamiento y aspecto en navegador, ejemplos de importes y contenido de Excel. Pruebas de regresión apropiadas; suite completa solo cuando el árbol deje de cambiar. La aceptación del portal se comprueba por separado.
6. Mantener aquí una lista breve de terminado, pendientes y próxima tarea para que las conversaciones puedan continuar sin perder el alcance. La revisión general del resto del proyecto tendrá su propia fase.

## Criterio de cierre del módulo

El usuario puede abrir un cliente, distinguir lo listo de lo que requiere atención, preparar el archivo correcto de quedan, consultar sus NC relacionadas sin recapturar datos, localizar un envío anterior y conciliar una evidencia de pago. Los importes se explican y respetan las reglas; los históricos no afirman estados sin respaldo; las pantallas se pueden usar en ambos temas con cientos de documentos. No declarar finalizado solo por pasar pruebas o por instalar archivos.

## Retoma autorizada — 22/09/2026

El usuario pidió recuperar y documentar este alcance y probar el puente con una tarea pequeña en desarrollo. Codex coordina y revisa; Claude desarrolla. Se conserva íntegro el alcance anterior; no se considera implementado por estar documentado.

Pendientes por entregas:

1. Diagnosticar y reutilizar los datos guardados de NC internas al pasar a PPQ, distinguiendo albarán de NC y albarán de entrega del CCF.
2. Verificar vínculos actuales e históricos y diferencias AC04/AC02 con evidencia, sin recalcular DTE emitidos.
3. Preparar quedan con la plantilla indicada, elegibilidad explicada y estados independientes de generación, presentación y pago.
4. Ordenar Cobros, Historial PPQ y formatos de NC con paginación, filtros, detalle por lote, redescarga original y contraste en ambos temas.
5. Conciliar TXT y evidencias históricas sin duplicar pagos ni afirmar deuda por ausencia de evidencia.

Primera prueba: consulta de lectura a Claude sobre el recorrido de una NC interna hacia PPQ/exportación; Codex contrastará el resultado y enviará una revisión usando el mismo SessionId. Solo después se delimitará una modificación pequeña si el diagnóstico la justifica. La prueba no equivale al cierre del rediseño.

Comprobación inicial: APP_ENV=local y DB_HOST=127.0.0.1 en esta copia. No se conectó a producción ni se ejecutaron migraciones. El árbol contiene numerosas modificaciones previas, incluidas las de firmware, que deben preservarse. No se encontró un proceso Claude activo antes de invocar el puente mediante Get-Process; la consulta alternativa por CIM no tuvo permisos.

### Resultado de la primera prueba

- Consulta de lectura registrada en `tmp/claude-colaboracion/prueba-nc-20260922.txt`. No se encargó implementación todavía.
- La llamada terminó con código 1: `Connection refused`, `terminal_reason=api_error`, cero tokens y ninguna respuesta de análisis. SessionId devuelto: `c51bbfc9-62f2-4f6c-89dc-8d2768c4ca7b`. Registro: `tmp/claude-colaboracion/20260922-062104-d94b8857-respuesta.json`.
- Se intentó reanudar esa sesión fuera del aislamiento para comprobar la conectividad. La revisión automática rechazó ejecutar el reintento porque el servicio externo de Claude podría recibir código fuente local y exige autorización explícita para ese procesamiento. El reintento no se ejecutó. No se eludió el rechazo.
- Ese bloqueo se resolvió con la autorización explícita posterior del usuario; ver la continuación siguiente. Claude no implementó cambios en esta prueba.

### Contraste inicial de Codex (lectura de código, sin pruebas funcionales)

- `NcExportacionService::pendientes()` carga la relación `albaran`; `elegibles()` exige que exista. `ExportadorNcCargaMasivaV1::faltantes()` y `fila()` leen ese albarán de la NC. Este recorrido sí reutiliza datos persistidos para el formato.
- `PpqItemController::store()` obtiene el albarán por `ppq_albaran_id` o por datos manuales recibidos, y crea el item con `sin_albaran` cuando no hay uno. En ese método no se observa reutilización directa de `Dte::albaran`. Hay que contrastar también la preparación de la pantalla antes de definir una corrección completa.
- No se ha demostrado aún el recorrido de reutilización del CCF relacionado ni se han comprobado documentos reales. Próxima tarea pequeña: Claude debe contrastar esta separación de recorridos y proponer una corrección acotada con criterio de aceptación; Codex revisará antes de pasar a implementación.

### Continuación autorizada y resultado — 22/09/2026

El usuario autorizó expresamente el procesamiento en el servicio externo de Claude de los archivos de código pertinentes, excluyendo credenciales y datos de producción. El reintento con el SessionId fallido no encontró conversación; se abrió una nueva sesión para el mismo encargo.

**Prueba de consulta y revisión completada:** SessionId `e4f53e43-f440-4a2e-b1a9-dd11860317ef`. Primera respuesta: `tmp/claude-colaboracion/20260922-062635-60bdd8a5-respuesta.json`; revisión continuada: `tmp/claude-colaboracion/20260922-062833-63976af2-respuesta.json`. Ambas llamadas terminaron correctamente. La segunda respuesta retomó el diagnóstico anterior y aceptó las observaciones de Codex. No hubo ediciones de aplicación ni ejecución de pruebas; no se ha validado todavía el modo Desarrollo.

Diagnóstico contrastado por ambos:

- El formato de NC ya usa el albarán persistido en `dte_albaranes`.
- En `resources/views/ppq/partials/fila-local.blade.php:19-23`, la NC local queda fuera del albarán automático; la línea 66 fija `ccfRelacionado` a null.
- `resources/views/ppq/partials/resultado.blade.php` muestra para NC campos manuales vacíos. Su texto actual de relación por «misma OC» no sirve para describir una relación explícita guardada.
- `PpqItemController::store()` no lee `Dte::albaran`; usa el albarán seleccionado o los campos recibidos. La relación explícita al CCF es `Dte::dteRelacionado()` mediante `dte_relacionado_id`. Claude corrigió su referencia inicial a la relación inversa y retiró la inferencia de que la ausencia de columna CCF en el formato demostraba un fallo de otra ruta.

Primera entrega propuesta para Claude: mostrar el CCF explícitamente relacionado y prellenar número, fecha y monto del albarán propio de NC internas en PPQ, cargando las relaciones sin consultas por cada fila. Diferenciar vínculo guardado de sugerencia por OC. Mantener el comportamiento manual cuando falten datos y el recorrido de CCF. No usar el albarán de entrega como albarán de la NC.

Antes de implementar, precisar el mapeo de número/canónico y la compatibilidad de `registrarAlbaran()` con los datos propios, así como el tratamiento de datos incompletos o contradictorios. El prellenado por sí solo no acredita reutilización validada en servidor. La prueba debe cubrir renderizado y POST al lote, conservación del monto, NC sin albarán y regresión CCF. Ejecutar pruebas focalizadas con base aislada; no ejecutar `pint --dirty` sobre el árbol completo ni suite global por esta entrega.

Hallazgo adicional registrado, sin implementación: `elegibles()` exige albarán y aceptación real; las NC sin albarán quedan fuera de pendientes y ya exportadas. Revisar su visibilidad en la etapa de bandejas, sin relajar reglas de exportación.

Estado de cierre de esta tarea pequeña: alcance recuperado y documentado, consulta recibida, revisión enviada y respondida en la misma sesión. Próximo paso: primera entrega de desarrollo acotada; no iniciar el rediseño completo ni declarar cerrado el módulo.

### Primera entrega de desarrollo cerrada — 23/09/2026

El usuario autorizó implementar la entrega y pidió continuar con **Claude Opus 5.5**. El modelo efectivo se verificó en `modelUsage` de las dos correcciones; se mantuvo SessionId `e4f53e43-f440-4a2e-b1a9-dd11860317ef`.

Terminado en desarrollo: PPQ muestra el CCF explícitamente relacionado de la NC interna, reutiliza su albarán propio desde el servidor, permite completar faltantes operativos sin modificar el DTE emitido, y rechaza evidencias contradictorias. El guardado de albarán/item es transaccional y completa la fecha vacía de un registro PPQ compatible. Se conservan el recorrido CCF, la captura manual sin datos guardados y la opción explícita previa de ingresar sin albarán.

Codex revisó los cambios, devolvió hallazgos y verificó las correcciones: **68 pruebas / 337 aserciones correctas**, Pint `--test` correcto y seis renders ficticios de la ficha (completa/parcial/inválida, claro/oscuro) inspeccionados en navegador. Sin conexión a producción, migraciones de bases reales, commit, push o despliegue. Detalle, evidencia y límites: `docs/REVISION_NC_PPQ_20260923.md`.

El flujo de implementación del puente queda probado para esta entrega. Mantener el método: Codex coordina y revisa; Claude desarrolla. La preferencia actual de modelo para continuar es `claude-opus-5-5`, aplicada al proceso del puente sin cambiar configuración global.

Pendiente del módulo: diagnóstico de vínculos actuales/históricos y diferencias AC04/AC02, bandeja digital de Cobros, plantilla de quedan, historial paginado de formatos NC/PPQ y conciliación de pagos. La siguiente etapa propuesta es el diagnóstico de vínculos e importes con evidencia. No se implementó ese resto por iniciativa propia.

### Aviso local de migraciones pendientes — 23/09/2026

El usuario reportó el aviso y pidió continuar con Claude. Codex verificó `migrate:status`: la única pendiente es `2026_09_21_131000_ampliar_motivo_cobro_correos_a_text`. No fue creada por la entrega reciente de NC/PPQ. La migración base de Cobros ya figura aplicada.

Claude Opus 5.5 revisó el código en la misma sesión; registro `tmp/claude-colaboracion/20260923-173007-19498b42-respuesta.json`. Codex contrastó la migración y el escritor de motivos: una lista de documentos pendientes de localizar puede exceder el límite de 255 caracteres.

La simulación focalizada (`migrate --pretend --path=database/migrations/2026_09_21_131000_ampliar_motivo_cobro_correos_a_text.php`) produjo únicamente `alter table cobro_correos modify motivo text null`. No se ejecutó el ALTER. La prueba aislada `CobrosCorreoReal31001Test` pasó: 2 pruebas, 19 aserciones; no demuestra aplicación en MySQL local. El estado se volvió a consultar y sigue pendiente.

Solución preparada: aplicar exclusivamente esa migración en desarrollo con `--path`, previa autorización expresa para modificar el esquema local según AGENTS.md; después verificar estado y diagnóstico. No usar una migración global que pueda incorporar archivos nuevos entre la revisión y la ejecución. No hace falta modificar código por este aviso. El rollback ya rechaza reducir a 255 si perdería texto. No se midieron tamaño de tabla ni duración de bloqueo; no asumirlos a partir del análisis de Claude. Producción permanece fuera del alcance.

Aplicación autorizada posteriormente por el usuario («sí»): Codex ejecutó únicamente esa migración con `--path` y `--no-interaction`, tras confirmar entorno local, host 127.0.0.1 y ausencia de configuración cacheada. Terminó correctamente en 76.24 ms. Verificación posterior: estado `[83] Ran`, columna `motivo` de tipo `text` y el control `checkMigracionesPendientes()` devolvió nivel `correcto`, «Todas las migraciones están aplicadas». No se modificó producción ni se ejecutaron otras migraciones.

### Continuación del plan: importes AC04/AC02 y diferencia PPQ — 23/09/2026

No está todo lo de notas y pagos. Claude Opus 5.5 diagnosticó los importes en lectura y, en una entrega acotada, corrigió el signo de la diferencia del Excel PPQ y de la ficha del lote, más la etiqueta confusa de una NC ya identificada. Codex contrastó el cálculo, revisó el diff y verificó 67 pruebas/276 aserciones, además de Pint `--test` y 3 pruebas/16 aserciones sobre la prueba nueva tras una corrección de estilo. La columna de diferencia guardada y los DTE no cambiaron.

En esta base local, el perfil activo de Calleja tiene AC04 sin descuento y AC02 heredado del CCF. Eso no valida el perfil de producción ni explica por sí solo cada documento histórico. El recorrido de emisión AC04/AC02 probado no mostró un error de cálculo; el defecto confirmado estaba en cómo PPQ presentaba la diferencia de la NC. Ver evidencia, límites y archivos en `docs/REVISION_IMPORTES_PPQ_20260923.md`.

Sigue pendiente el resto del plan: vínculos históricos, preparación de quedan, orden y paginación de bandejas e historial, formatos NC con redescarga y conciliación de pagos/evidencias. Próxima etapa: revisar vínculos y albaranes históricos con evidencia antes de diseñar o implementar las bandejas. Mantener desarrollo, sin tocar producción.

### Segunda entrega de continuación: vínculo de albaranes PPQ históricos — 23/09/2026

Claude Opus 5.5 implementó en desarrollo una vía conservadora para aprovechar el `ppq_albaran_id` que un item PPQ antiguo ya guardó para un CCF. Codex revisó y devolvió correcciones de identidad fiscal, OC, tipo de albarán, conflictos y trazabilidad. Un snapshot de Gmail requiere un segundo identificador coincidente, además del control; las colisiones y contradicciones siguen en revisión. El vínculo no infiere presentación ni pago y no quita el bloqueo de revisión histórica.

Verificación final: **65 pruebas/280 aserciones** y Pint `--test` correctos. El ensayo local de solo lectura mostró 14 documentos que entrarían en Cobros y cero ya existentes para auditar, de modo que no se ha medido la recuperación sobre vínculos históricos de esa base. No se aplicaron altas ni vínculos, ni se tocó producción. Evidencia, límites y archivos: `docs/REVISION_VINCULOS_HISTORICOS_20260923.md`.

El siguiente paso propuesto en esa revisión era inspeccionar la plantilla de carga masiva de quedan; se completó en la entrega documentada abajo. Persisten la preparación de la bandeja, el historial y formatos NC, el orden visual y la conciliación de pagos, sin confundir generado, presentado, recibido y pagado.

## Plantilla de quedan y redescarga — 23/09/2026

La plantilla que el usuario dejó en el Escritorio ya se incorporó **idéntica en bytes** al proyecto y el exportador de solicitudes parte de ella. Claude Opus 5.5 implementó ese ajuste y, tras la revisión de Codex, corrigió la redescarga para usar la copia archivada y verificada en lugar de generar otro archivo. La prueba enfocada terminó con **25 pruebas/161 aserciones** y Pint correcto. Evidencia, archivos y límites: `docs/REVISION_PLANTILLA_QUEDAN_20260923.md`.

La aceptación del XLSX por el portal de Calleja sigue sin verificarse. Tampoco está cerrado el rediseño: próxima entrega recomendada, paginación y preparación clara de quedan en Cobros; después historial/formatos NC con redescarga original y conciliación de TXT/pagos. No confundir esta entrega con implementación de esos pendientes. Trabajo solo en desarrollo; sin producción, commit, push ni despliegue.

## Bandeja paginada de Cobros — 23/09/2026

Claude Opus 5.5 completó en desarrollo la paginación real de documentos y la señal breve de preparación de quedan por fila. Codex revisó y verificó **55 pruebas/388 aserciones**, Pint correcto. Ver `docs/REVISION_BANDEJA_COBROS_20260923.md` para archivos, alcance y límites.

Faltan dos ajustes visuales pequeños: el fondo de revisión histórica en oscuro y el título neutral del historial de solicitudes. El encargo de cierre a Claude no se ejecutó por límite de sesión (`api_error`, restablecimiento anunciado a las 10:30 p. m. de El Salvador); no se deben dar por hechos. Retomar en la misma SessionId cuando haya cuota. Permanecen pendientes la reducción de ancho de la tabla/vista previa, el historial PPQ y NC y la conciliación de TXT/pagos. El portal de Calleja tampoco se ha comprobado. Sin cambios de producción, commit, push ni despliegue.

**Continuación 24/09:** Tras restablecerse la cuota, Claude cerró esos dos ajustes de Cobros; Codex verificó 20 pruebas/172 aserciones. El párrafo anterior conserva el estado al cierre del 23/09, no el estado actual. Siguen pendientes la comprobación visual en navegador/móvil y el resto del rediseño.

Diagnóstico de lectura para la siguiente etapa de formatos de NC: la pantalla carga todas las notas pendientes y exportadas, corta el historial en 30 lotes, y cada descarga regenera el Excel sin guardar copia ni hash original. La igualdad byte a byte de archivos anteriores no está garantizada. Alcance propuesto para Claude y límites en `docs/DIAGNOSTICO_FORMATOS_NC_20260923.md`. No se editó ese módulo en esta etapa.

**Continuación 24/09 — historial NC:** Claude implementó historial de archivos paginado y ficha por lote; Codex revisó y verificó 41 pruebas/227 aserciones, luego 23/107 tras ajuste de estilo, con Pint correcto. Ver `docs/REVISION_HISTORIAL_NC_20260924.md`. Permanece pendiente archivar bytes originales de NC y paginar pendientes; la regeneración actual no garantiza igualdad byte a byte. Ninguna migración ni producción afectada.

**Continuación 24/09 — copia de NC:** Claude implementó hash/copia/procedencia para futuras redescargas; las antiguas sin copia se marcan como reconstrucción. Codex revisó y corrigió con Claude la prueba que intentaba alterar un DTE aceptado. Pint y las pruebas focalizadas posteriores pasaron; ver cifras exactas y límites en `docs/REVISION_COPIA_NC_20260924.md`. Tras autorización expresa del usuario, Codex aplicó **solo** `2026_09_24_120000_add_copia_archivada_a_nc_exportaciones.php` en la base local de desarrollo; `migrate:status` confirma batch 84 `Ran`. Producción no fue tocada. Siguen pendientes el detalle de quién/cuándo descargó, presentación visual, PPQ general y pagos.

**Continuación 24/09 — pendientes de NC:** Claude paginó 25 notas por página, separadas de las páginas del historial. Los filtros y el cliente se conservan al navegar; ninguna nota viene marcada y «Marcar las de esta página» solo incluye las disponibles que se ven. Codex revisó y verificó **32 pruebas/228 aserciones** y Pint `--test`, correctos. Ver `docs/REVISION_PENDIENTES_NC_20260924.md`. Queda pendiente comprobar la interacción en navegador con sesión real y en móvil/tema oscuro; no se cambió el formato Excel ni se aplicaron más migraciones.

**Continuación 24/09 — Historial PPQ:** El índice ya paginaba 20 lotes; Claude añadió filtros por cliente, estado, fecha y referencia/número, con orden estable y contraste claro/oscuro. Codex pidió corrección para entradas GET no escalares y Pint, luego verificó **50 pruebas/184 aserciones**, Pint correcto, build local y recorrido real de búsqueda «julio» en ambos temas. Ver `docs/REVISION_HISTORIAL_PPQ_20260924.md`. La ficha PPQ aún carga todos sus documentos y queda para una etapa posterior. La base local tiene tres lotes PPQ, pero Cobros y formatos NC aparecen vacíos; ver instrucciones y límites en `docs/RECORRIDO_DESARROLLO_COBROS_PPQ_NC_20260924.md`.
