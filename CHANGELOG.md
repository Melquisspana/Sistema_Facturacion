# Registro de cambios

Este archivo registra los cambios relevantes del proyecto siguiendo el formato de Keep a Changelog, con categorías en español y las entradas más recientes primero.

Cada despliegue a producción lleva la etiqueta `vAAAA.MM.DD` (con `.2`, `.3`… si hay más de uno el mismo día) y su entrada en este archivo, con la fecha y los cambios incluidos. Los cambios pendientes se agrupan en `[Sin publicar]`. No se incluyen datos del servidor ni detalles operativos del despliegue.

El historial anterior a este archivo está en el registro de git.

## [Sin publicar]

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
