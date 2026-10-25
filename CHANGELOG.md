# Registro de cambios

Este archivo registra los cambios relevantes del proyecto siguiendo el formato de Keep a Changelog, con categorías en español y las entradas más recientes primero.

Cada despliegue a producción lleva la etiqueta `vAAAA.MM.DD` (con `.2`, `.3`… si hay más de uno el mismo día) y su entrada en este archivo, con la fecha y los cambios incluidos. Los cambios pendientes se agrupan en `[Sin publicar]`. No se incluyen datos del servidor ni detalles operativos del despliegue.

El historial anterior a este archivo está en el registro de git.

## [Sin publicar]

### Agregado

- Cobros: al cargar el TXT de pagos se ven los CCF del PPQ que no vinieron pagados, los pagados de menos (con aviso de posible devolución AC04) y las NC no descontadas, y con un botón quedan listos para el próximo PPQ. #140
- El correlativo del número de control vuelve a 1 con el primer DTE de cada ejercicio (Normativa 2.0, regla 7.1), con la columna `ejercicio` en `dtes` y bloqueo para que dos documentos del 01/01 no repitan número. Trae migración. #125
- Cobros y PPQ identifican cada documento por número de control y ejercicio. Los TXT, Excel y correos de Calleja que traen solo el número se resuelven por fecha, y lo ambiguo queda para revisión. Trae migración. Se despliega junto con #125. #127
- Facturación: el buscador del listado encuentra las notas de crédito por el número de su albarán (completo, solo el número o el nombre del PDF), con coincidencia exacta.

### Corregido

- Productos → Exportación: desmarcar un cliente en «Clientes» lo quita de la presentación. #115
- Las NC de pronto pago ya no se recomiendan ni entran en el PPQ, y no bloquean ni salen en el archivo de quedan. El comando `ppq:quitar-nc-pronto-pago` las saca de los PPQ armados que todavía no se presentaron, salvo las conciliadas. #139

## [2026.10.25] - 2026-10-25

Incluye #133. No trae migraciones.

### Corregido

- NC v4: si el prorrateo del descuento global deja alguna línea con venta negativa, la generación se frena con un mensaje que indica la línea, en vez de mandar a Hacienda un documento que rechazaría. #133

## [2026.10.24.2] - 2026-10-24

Segundo despliegue del día. Incluye #132. No trae migraciones.

### Agregado

- Nota de crédito versión 4 ACTIVADA (`DTE_NC_VERSION=4`). El descuento global del CCF se prorratea en el `montoDescu` de cada ítem, por categoría y sobre las ventas netas de línea, y el último ítem absorbe los centavos. Se validó en el ambiente de pruebas del MH con 7 casos con un descuento global del 10 %. #132

## [2026.10.24] - 2026-10-24

Paquete de Hacienda 2.0. Incluye #58 (sin activar), #113, #116, #119, #120, #123, #124, #128 y #129. No trae migraciones. Quedan afuera #125 y #127.

### Agregado

- Nota de crédito versión 4 de Hacienda 2.0, con la versión fijada por documento. Llega apagada (`DTE_NC_VERSION=3`) hasta que admita el descuento global. #58
- Contingencia: los documentos emitidos fuera de la ventana de entrada automática quedan para revisión manual, visibles para el administrador. #113 #116
- NC fuera del plazo de 3 meses desde su CCF: el sistema avisa y pide confirmar la excepción con un motivo, que queda en la auditoría. #123
- Comando de solo lectura `dte:catalogos --verificar`, que compara los catálogos cargados con el Excel oficial. #124

### Cambiado

- El PDF y el evento de invalidación usan los datos fiscales del JSON emitido, no los actuales del cliente o del emisor. #120
- `resumen.pagos` según la Normativa 2.0: FE y FEX al contado informan la forma de pago, y el CCF a crédito lleva plazo y período. #119 #129
- NC: topes de exento, no sujeto e IVA retenido contra el saldo del CCF. La FEX de servicios queda bloqueada, porque solo se exportan bienes. #123
- FE: `totalIva` es la suma de los IVA por ítem menos el IVA del descuento global. La base gravada de las FE nuevas puede variar unos centavos; el total no cambia. #124

### Corregido

- CCF y NC sin correo del receptor se frenan antes de generar el JSON. En la FE, el NIT o DUI del receptor se envía sin guiones. #119
- FE con descuento global: `totalGravada` es la suma de las ventas gravadas de los ítems. Hacienda la rechazaba. #128
- Contingencia: el inicio retrocede como máximo 10 minutos hasta la emisión del documento que la dispara. #113

## [2026.10.20] - 2026-10-20

Incluye #111 y #112. No trae migraciones.

### Corregido

- Consulta al MH: el HTTP 400 con código 999 («no se encontró ningún registro») se interpreta como documento no encontrado. Así funcionan la entrada automática a contingencia y el reenvío tras un corte. #111
- Contingencia: el inicio retrocede hasta la emisión del documento que la dispara, porque Hacienda lo rechazaba por quedar antes del inicio. #112

## [2026.10.17] - 2026-10-17

Contingencia de Hacienda 2.0 instalada y apagada (`DTE_CONTINGENCIA_ENABLED=false`). Incluye #99, #101, #104, #105 y #106. Trae dos migraciones: `contingencias`, `contingencia_eventos` y `lotes_dte`, más sus columnas en `dtes`.

### Agregado

- Modo contingencia: documentos transitorios firmados sin transmitir, evento de contingencia, envío por lotes y consulta de lotes, entrada automática tras agotar los reintentos, reconexión y cierre, y aviso «Sin internet» con service worker que lleva a la dirección del local. #99 #101 #104 #105 #106

## [2026.10.14] - 2026-10-14

Incluye #107 y #108. No trae migraciones.

### Agregado

- Paquete de contabilidad: incluye las ventas invalidadas ante Hacienda (CCF, factura, NC y FEX). En el Excel de ventas salen con estado «INVALIDADO», la fila en rojo claro y los montos en 0, más la fecha, el código del evento y el monto original. El ZIP trae su PDF y la evidencia del evento. Los totales cuentan solo los vigentes y las invalidadas se informan aparte. #108

### Corregido

- PPQ: al cargar el reporte del caso de Calleja, los CCF devueltos salen del lote junto con sus NC, y el lote queda con el mismo total que el caso. Las observaciones del lote y la bitácora registran cada devuelto. Volver a subir el mismo reporte no cambia nada. #107

## [2026.10.10.2] - 2026-10-10

Segundo despliegue del día. Incluye #103. No trae migraciones.

### Corregido

- Archivo de quedan: el mes y el año de las filas de NC (AC02/AC04) salen de la fecha del albarán que se registró en la NC, que es la que usa Calleja, y las filas se ordenan AC01, AC02 y AC04 por fecha del albarán. #103

## [2026.10.10] - 2026-10-10

Incluye #100. No trae migraciones.

### Cambiado

- Lote PPQ: queda un solo botón, «Generar formato quedan». El archivo de NC se genera antes desde Documentos fiscales. #100

### Corregido

- Archivo de quedan: las NC que estuvieron en un lote PPQ borrado vuelven a salir en el quedan del lote nuevo. #100

## [2026.10.09] - 2026-10-09

Incluye #79, #94 y #97. No trae migraciones.

### Agregado

- Compras: se puede leer un segundo buzón mientras dure un cambio de correo (`DOCUMENTOS_RECIBIDOS_MAIL2_*` en `.env`, `compras:sincronizar --buzon=principal|adicional|todos`). Cada buzón lleva su propio progreso, y un mismo DTE llegado a los dos queda una sola vez. #97
- Invalidación: el formulario pide quién solicita la anulación (nombre, tipo y número de documento) y valida los documentos de responsable y solicitante según CAT-022. #79

### Corregido

- Compras: un UID de IMAP repetido (otro buzón o una carpeta reconstruida) ya no hace rechazar el correo por la columna única `gmail_message_id`. #97
- Paquete de contabilidad: cuando falla la subida a Drive, queda registrado el motivo técnico saneado. #94

## [2026.10.07] - 2026-10-07

Incluye #73, #74, #76, #77 y #78. No trae migraciones.

### Agregado

- Paquete de contabilidad: «Enviar a contabilidad» sube el ZIP a Google Drive y manda un correo con el enlace, compartido solo con el correo de contabilidad. Hay que autorizar Drive en Configuración → Integraciones. #74
- Seguimiento de CCF: explica cuántos CCF no se pueden seleccionar y por qué. #73
- Comando `cobros:recalcular-pagos` (con `--dry-run` y `--cliente`), que vuelve a calcular el estado de pago a partir de los eventos. #76

### Corregido

- Un CCF pagado por su total, con la NC descontada en una línea aparte del TXT, ya figura como pagado. En producción se recalcularon 14 CCF: 10 pasaron a pagados, y 4 cuya NC nunca se descontó pasaron a «Diferencia». #76
- Desarrollo y CI usan la hora de El Salvador, igual que producción. Asistencia guarda la hora de la marcación sin desfase. #77
- Evento de invalidación: los códigos de establecimiento y punto de venta del MH salen del número de control del DTE, con validación de su formato. #78

## [2026.10.06.6] - 2026-10-06

Sexto despliegue del día. Incluye #69 y #71. No trae migraciones.

### Agregado

- Seguimiento de CCF: avisos «Albarán reciente» (registrado hace menos de `COBROS_DIAS_ALBARAN_RECIENTE` días, 5 por defecto, que quizá todavía no esté en el portal de Calleja) y «Posible duplicado» (otro documento ya cobró con ese albarán), y botón «Quitar los de albarán reciente» de la selección. #69

### Corregido

- Archivo de quedan: si el albarán de un CCF se eligió a mano en el Seguimiento, su sala vale como confirmada y ya no hace falta anotarla en `PPQ_QUEDAN_SALAS_CONFIRMADAS`. #71

## [2026.10.06.5] - 2026-10-06

Quinto despliegue del día. Incluye #68. No trae migraciones.

### Agregado

- Una NC aceptada después de armar un PPQ entra sola al lote de su CCF, si el lote sigue «Armado». La ficha del lote ofrece «Agregar NC nuevas» para las anteriores al cambio. Si el lote ya se presentó, avisa «NC posterior a la presentación». #68
- Las NC aceptadas después de presentar el lote de su CCF se suman solas al próximo PPQ del cliente, con aviso en el Seguimiento y en la confirmación. #68
- Ficha del lote: «Marcar como presentado» con fecha, para los lotes que se subieron al portal sin cargar el reporte de caso. #68

### Corregido

- Un CCF ya no puede quedar en dos lotes vigentes. Un «va en el siguiente PPQ» de un caso solo libera el item del lote al que pertenece ese caso. #68

## [2026.10.06.4] - 2026-10-06

Cuarto despliegue del día. Incluye #67. No trae migraciones.

### Corregido

- Archivo de quedan: la fecha del albarán se lee de su PDF como la fecha de creación del albarán, y ya no la del pedido de compras. Los albaranes creados en un mes distinto al del pedido salían con el mes equivocado y el portal no los encontraba. El comando `ppq:recalcular-fecha-albaranes` (con `--dry-run` y `--desde`) corrige los ya guardados; en producción cambió 42 fechas, 17 de ellas de septiembre a octubre. #67

## [2026.10.06.3] - 2026-10-06

Tercer despliegue del día. Incluye #64 y #65. No trae migraciones. La nota de crédito versión 4 (#58) queda en el código, pero no se desplegó.

### Agregado

- Seguimiento de CCF: botón «Seleccionar todas las pendientes» (respeta el filtro, hasta 500 CCF por PPQ). La selección se conserva al cambiar de página, y antes de crear el PPQ se confirma la cantidad y el monto. #64
- Comandos de reparación idempotentes: `ppq:liberar-lotes-borrados` (devuelve a «por presentar» los CCF de un lote que se borró antes del arreglo) y `ppq:completar-cliente-lotes` (completa el cliente de los lotes que no lo tienen). Los dos admiten `--dry-run`. #64 #65

### Cambiado

- Historial de PPQ: el estado de cada lote se calcula a partir de sus CCF en el Seguimiento (pagado, con diferencias, en cobro, presentado, armado) y ya no depende de la columna guardada. #65

### Corregido

- Borrar un PPQ devuelve sus CCF a «por presentar», salvo los que tienen pago, van en una solicitud, siguen en otro PPQ vigente o figuran en un caso de Calleja. Un doble clic ya no crea dos lotes. #64

## [2026.10.06.2] - 2026-10-06

Segundo despliegue del día. Incluye #62. No trae migraciones.

### Cambiado

- El PDF del DTE incluye solo los caracteres usados de cada fuente y usa un logo reducido. Una factura bajó a unos 100 KB, y el paquete mensual de contabilidad pasó de 240 MB a 36 MB. #62

## [2026.10.06] - 2026-10-06

Incluye #37, #38, #47, #48, #50, #55 y #56. No trae migraciones. También entra la matriz de invalidación de Hacienda (documento sustituto y notas vigentes), que es la base de #38 y #50 y todavía no estaba en producción. El código de #44 (Rutas) queda en el repositorio, pero no se desplegó: depende de la reorganización de Rutas, que necesita migraciones pendientes.

### Agregado

- Archivo del DTE con firma y sello: el correo al cliente, la descarga del JSON, el reporte de la contadora y el ZIP de contabilidad entregan el documento con `firmaElectronica` y `selloRecibido`, sin recodificar el original. Botón «Descargar JSON con firma y sello» en los documentos aceptados, y comando de solo lectura `dte:entrega-check`. #48
- Plazo para invalidar según el manual funcional: CCF, NC y ND hasta el 10.º día hábil del mes siguiente al sello; factura y exportación, 3 meses. Se vence a las 23:59:59 hora de El Salvador. Fuera de plazo, el documento se detiene antes de firmar o transmitir, y la ficha muestra el límite. #50
- Paquete mensual de contabilidad en segundo plano: la pantalla muestra «Generando…» y luego ofrece «Descargar», sin el corte de Cloudflare. #56

### Cambiado

- Invalidación: matriz documento × motivo de Hacienda, documento sustituto verificado y de un solo uso, y bloqueo cuando hay notas de crédito o débito vigentes o en trámite (ya no se puede confirmar para saltarlo). Durante la transmisión se toma un candado por documento. #38
- Una sola regla de saldo del CCF para las notas de crédito: las rechazadas y los borradores ya no reservan saldo, y al generar se vuelve a medir bajo bloqueo. #38
- Hora de negocio única (`HoraNegocio`) para «hoy», la fecha y la hora de emisión, y la hora del evento de invalidación. #47 #55
- Laravel 12.69.3 y dependencias PHP sin avisos de seguridad; se quitó Livewire, que no se usaba. #37

### Problemas conocidos

- «Enviar a contabilidad» no puede mandar el ZIP mensual por correo: pesa más que el límite de Gmail. Mientras se resuelve en #60, se descarga y se comparte a mano.

## [2026.10.02.2] - 2026-10-02

Segundo despliegue del día. Incluye #31, #34 y #35. No trae migraciones. El cambio de Gastos del #31 queda en el código, pero el módulo de Gastos todavía no está en producción.

### Agregado

- Ficha del CCF: botón «Elegir albarán» / «Vincular con otro albarán» para corregir un CCF cuya orden de compra no encuentra su albarán. Sugiere hasta 10 albaranes de entrega puntuados por monto, OC, sala parecida y fecha, con buscador por número y vista previa antes de confirmar. Cada corrección queda en el historial del CCF y en la bitácora; no se puede mover el albarán de un CCF que ya está en una solicitud o tiene pagos. #35

### Cambiado

- Seguimiento de CCF: un CCF figura como pagado cuando lo cobrado coincide con su total menos las NC aceptadas y sin invalidar. Los pagos que no cuadran (faltante o diferencia) pasan a la pestaña nueva «Pagos con diferencia». #34

### Seguridad

- Gastos: la cuenta de un proveedor ya no muestra obligaciones protegidas (sueldos de planilla) a quien no tiene permiso de verlas. Aplica la misma protección que el resto de las consultas del módulo. #31
- Las acciones que escribían por GET ahora van por POST con CSRF: preparar el archivo de NC de un lote PPQ, descargar un formato de NC y descargar el archivo de una solicitud de cobro (las dos descargas quedan contadas en la bitácora). Un GET a la auditoría de vinculación de albaranes es siempre el ensayo en seco. #31
- Conexión OAuth de Gmail: el regreso exige un `state` aleatorio ligado a la sesión que inició la conexión, y el error ya no muestra el mensaje técnico en pantalla. #31

## [2026.10.02] - 2026-10-02

Primer despliegue con etiqueta. Incluye #1, #2, #29 y #30. No trae migraciones.

### Seguridad

- Los datos reales de la empresa salen del código: el código de proveedor de los TXT de pagos, los datos del exportador y el host de Cloudflare Access se leen del entorno (`PPQ_CODIGO_PROVEEDOR`, `EXPORTACIONES_*`, `CLOUDFLARE_ACCESS_ALLOWED_HOST`), sin valores operativos por defecto. Si falta el código de proveedor, el TXT se rechaza con un mensaje que lo dice. El seeder del administrador inicial ya no trae una contraseña fija. #2
- Los datos que llegan a Alpine y a `confirm()` se escapan como literal de JavaScript (`@js`), y las fechas de los filtros de Facturación e Invalidaciones solo aceptan `AAAA-MM-DD` válidas. #29
- Un usuario desactivado pierde sus sesiones abiertas en la petición siguiente. La política de contraseñas de `config/security.php` se aplica también al cambio desde el perfil y al restablecimiento por correo. Los inicios y cierres de sesión, los intentos fallidos, los bloqueos y los restablecimientos quedan en Auditoría (módulo «Acceso») con la IP real del cliente. Se agrega un techo de intentos de inicio de sesión por IP (`LOGIN_MAX_ATTEMPTS_POR_IP`). #30

### Agregado

- Integración continua en GitHub Actions con PHPUnit, un job de PHPUnit con MySQL estricto (en cada push desde #1, todavía no bloqueante) y Pint sobre los archivos cambiados.
- Plantillas de pull requests e issues para documentar cambios, pruebas y criterios de aceptación.
- Política de reporte privado de vulnerabilidades en `SECURITY.md`.
- Registro de decisiones del proyecto y primera decisión sobre ramas y pull requests.
- Decisión 0002, en propuesta: cómo aplicar las migraciones pendientes y las destructivas en producción. Relacionado con #5.
- `README.md` como portada del proyecto: qué hace el sistema explicado sin tecnicismos, capturas tomadas sobre una base desechable con datos inventados (`docs/img/`), tecnología, arquitectura, calidad y seguridad.
- `LICENSE` con todos los derechos reservados: el código es público como portafolio, no de uso libre (`composer.json` pasa de `MIT` a `proprietary`).

### Cambiado

- El candado de base de datos de la suite admite una única excepción: la MySQL desechable del job de la CI, que debe pedirse de forma explícita y solo pasa si la base se llama `testing` y está en el runner.

### Corregido

- Cobros: los textos libres que genera el sistema (motivos, notas, asuntos) se recortan al largo de su columna. Con MySQL estricto, un motivo demasiado largo revertía la aplicación completa de un TXT de pagos. Además, un correo que falla ya no detiene la lectura del buzón: se registra, se cuenta y se reintenta. #1 cierra #4.
- Las pruebas del diagnóstico del sistema (Salud del sistema, Dashboard y `DiagnosticoSistemaService`) ya no dependen de que exista `public/storage` en la máquina: fallaban en todo checkout nuevo y en la CI. Se agregan pruebas del chequeo del enlace, que no tenía ninguna.

- `NavigationTest` actualizado al menú vigente de Cobros con el seguimiento de CCF.
