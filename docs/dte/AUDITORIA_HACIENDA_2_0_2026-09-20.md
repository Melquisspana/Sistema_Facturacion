# Hacienda 2.0: revisión y trabajo pendiente

Fecha: 20 de septiembre de 2026. Revisión de Codex para implementación por Claude.

## Resultado y alcance

La adaptación está parcialmente hecha. No hace falta rehacer la integración, pero no se puede declarar cumplimiento completo: hay diferencias comprobadas en notas de crédito, invalidación y entrega del archivo electrónico. Contingencia queda al final por instrucción del usuario.

Esta es una auditoría estática del código local y de los materiales entregados. No se consultó la base operativa, no se ejecutaron pruebas contra Hacienda ni la suite de aplicación, no se modificó código, configuración o producción. La presencia de pruebas y de registros históricos en documentación no demuestra aceptación actual de la versión nueva.

## Material revisado

Archivos del escritorio `C:/Users/<usuario>/Desktop/`:

- `Manual Funcional del Sistema de Transmisión V 2.0.pdf`: 80 páginas físicas, versión mayo de 2026. Texto extraído completo y revisión visual de las tablas críticas de invalidación y del ejemplo de archivo DTE. Referencias siguientes usan página impresa; la página física es tres números mayor.
- `Manual Técnico para la Integración Tecnológica del Sistema de Transmisión v2.pdf`: 35 páginas, escaneado. Revisados visualmente control de versiones, índice y secciones 5 y 6 (páginas impresas 30-31). No se hizo lectura visual íntegra de las 35 páginas; los capítulos restantes requieren cotejo de cierre.
- `Manual de Usuario del Sistema de Facturación.pdf`: 53 páginas, escaneado. Revisados portada e índice: describe la plataforma de facturación del MH. Es referencia de uso, no sustituto del contrato de integración del sistema propio.
- `Catálogos- Facturación Electrónica.pdf`: 62 páginas, extracción textual insuficiente para comparación completa. Se usó el Excel entregado como fuente estructurada; no se afirma identidad PDF/Excel.
- `Catálogos - Facturación Electrónica.xlsx`: comparación de valores de todas las celdas con las dos revisiones del repositorio.
- `svfe-json-schemas.zip`: inventario de los 15 JSON y comparación semántica de los esquemas existentes.

La captura adjunta es un aviso sobre adecuación y plazo, no una lista de mejoras implementadas por ContaPortable. Menciona 1 de diciembre de 2026. No se confirmó ese plazo con una publicación oficial accesible en esta revisión. El portal oficial fue accesible, pero los enlaces de descarga de la Normativa fallaron con la herramienta web.

No se encontró entre los cuatro PDF del escritorio la **Normativa de Cumplimiento completa con anexos de estructuras y validaciones**. Obtenerla y fijar su revisión es un requisito de cierre; el manual funcional remite expresamente a ella. No convertir ejemplos históricos del manual ni comentarios del código en reglas vigentes.

## Qué ya existe

| Área | Evidencia local | Estado comprobado |
|---|---|---|
| Factura | `config/dte.php`, `fe-f-v2.json`, serializador | Configurada v2; esquema igual al ZIP recibido |
| CCF | `config/dte.php`, `fe-ccf-v4.json`, serializador | Configurado v4; esquema igual al ZIP recibido |
| Exportación | `config/dte.php`, `fe-fex-v3.json`, serializador | Configurada v3; esquema igual al ZIP recibido |
| NC | Configuración v3; archivos v3 y v4 | v4 está guardado pero se selecciona v3 |
| Invalidación | `invalidacion-schema-v3.json` y servicios | Esquema igual al ZIP; reglas de negocio incompletas |
| Catálogo | `config/catalogos_mh.php` | Selecciona julio 2026; valores de todas las celdas iguales al Excel del escritorio |
| Transporte | Servicios de autenticación, consulta, transmisión y reintentos | Implementados y con pruebas existentes; no revalidados contra API en esta sesión |
| Nuevos eventos | Búsqueda en aplicación, rutas, configuración y esquemas | No se encontró implementación de retorno ni operaciones especiales |

“Normativa 2.0” no significa poner `version: 2` en todos los documentos. Cada DTE/evento mantiene la versión de su propia estructura.

## Pendientes comprobados

### 1. Invalidación: reglas según documento y motivo

Evidencia: `app/Enums/TipoAnulacionMh.php:43`, `app/Services/Dte/Serializadores/SerializadorInvalidacionMh.php:74`, `app/Http/Requests/Dte/TransmitirInvalidacionRequest.php:41`, `app/Services/Dte/DteInvalidacionService.php:250`.

El código exige reemplazo solo para motivo 1 y prohíbe reemplazo para 3. El manual funcional, páginas 13-15, establece:

- Para FE, CCF y FEX, motivos 1 y 3 requieren documento sustituto previamente aceptado.
- Motivo 2 lleva `codigoGeneracionR: null`.
- Para NC, motivos 1 y 3 también llevan reemplazo `null`: primero se invalida la NC incorrecta y después se emite la nueva. No aplicar la regla general del motivo 1 a todas las clases de documento.
- Un CCF con NC o ND validada relacionada no se puede invalidar antes de invalidar esas notas. Hoy existe un permiso por confirmación (`confirmoNcRelacionada`) que permite continuar. La confirmación humana no sustituye esa regla fiscal.

Implementación propuesta: una política compartida que reciba tipo del documento, motivo y relaciones vigentes. Consumirla en interfaz, Form Request, comandos, preflight y serializador. Validar el sustituto en servidor: no basta un UUID bien formado ni que el buscador solo sugiera aceptados. Diferenciar notas aceptadas vigentes de borradores o notas ya invalidadas.

Criterios de cierre: matriz FE/CCF/NC/FEX por motivos 1/2/3; sustituto sin sello, ajeno al ambiente o invalidado; CCF con NC activa bloqueado incluso con confirmación; CCF con notas ya invalidadas evaluado correctamente; comportamiento igual por interfaz y consola.

### 2. Invalidación: plazos y fechas

No se encontró validación de las ventanas normativas en las rutas de invalidación revisadas. El serializador toma siempre la fecha de emisión del documento original.

El manual funcional, página 11, distingue fecha de generación y fecha de transmisión:

- CCF/NC y otros tipos del grupo: transmisión del evento hasta el décimo día hábil del mes siguiente al período de recepción del documento. Fecha del evento igual a la fecha de generación del original.
- FE/FEX/FSEE: ventana de transmisión de tres meses desde el sello; la fecha del evento tiene además su propia ventana respecto de la generación del original.

**Inconsistencia de la fuente:** el párrafo introductorio de la página 12 dice diez días hábiles posteriores a la transmisión, pero la tabla de página 11 y los ejemplos de página 12 usan el mes siguiente. Confirmar contra la Normativa/anexos o aclaración oficial antes de cerrar el algoritmo. No tratar tres meses como noventa días ni días hábiles como simplemente lunes a viernes sin calendario oficial.

Criterios de cierre: fin de mes, febrero, feriados aplicables, cambio de año, hora límite 23:59:59 en El Salvador, fechas de generación y recepción en períodos distintos, documento fuera de plazo detenido antes de firmar/transmitir.

### 3. Nota de crédito v4

Evidencia: `config/dte.php:470` y `app/Services/Dte/Serializadores/SerializadorNotaCreditoMh.php`.

La selección actual es v3. El serializador documenta un rechazo histórico de v4 con `resumen.totalIva`; eso justifica preservar el flujo histórico, pero no demuestra que v4 siga rechazada ahora.

Comparación contra el ZIP: v4 incorpora `identificacion.fusion`, distrito del emisor/receptor, identificación del receptor por `tipoDocumento`/`numDocumento`, importes por línea `totalIva`, `ivaPerci`, `ivaRete`, `noGravado` y cambios sustanciales del resumen, incluido `totalPagar`. Quita `extension` y varios campos de v3. Cambiar solo la configuración a 4 produciría un JSON incompatible.

Implementación propuesta: serialización y cálculo explícitos por versión, conservación de documentos y firmas históricos, selección controlada de versión para documentos nuevos y pruebas de v4 en ambiente habilitado por MH. Preservar los casos de devolución, pronto pago, avería, descuentos y retenciones ya existentes.

Criterios de cierre: NC parcial/total, varias líneas, descuentos por línea/globales, retención, redondeos, saldo acreditable y documentos anteriores. Validación estructural y aritmética; aceptación real con sello en pruebas. No reenviar ni regenerar documentos ya aceptados para migrarlos.

### 4. Archivo entregado al receptor

Evidencia: `app/Jobs/EnviarDteCorreo.php:147`, `app/Services/Dte/DteJsonService.php` y persistencia en `DteTransmisionService`.

El correo lee el JSON generado antes de firmar y añade el JWS como archivo aparte solo si una opción está activada. La respuesta/sello se guarda separadamente. No se encontró construcción de `firmaElectronica` dentro del archivo entregado en el código de aplicación.

Manual técnico sección 6.5, página 31, y manual funcional sección XXIII, páginas 74-75: el archivo DTE entregado debe contener estructura, firma electrónica y sello; el funcional identifica las claves `firmaElectronica` y `selloRecibido`.

Implementación propuesta: construir un archivo de entrega a partir del JSON original exacto, JWS original y sello real, sin alterar el material firmado ni reutilizar ese archivo enriquecido como payload para volver a firmar. Usar el mismo archivo final para correo y descarga. Si un documento fiscal aceptado carece de componentes, reportar entrega incompleta y permitir recuperar evidencia; no presentarlo como paquete completo.

Criterios de cierre: JSON+firma+sello pertenecen al mismo documento; adjunto y descarga coinciden; PDF/QR identifican el mismo DTE; rechazos y mock no se confunden con entrega fiscal definitiva; archivos originales permanecen intactos.

### 5. Retorno y operaciones especiales: resolver aplicabilidad

El ZIP incluye `fe-eret-v1.json` y `fe-eop-v1.json`, ausentes en el repositorio de esquemas de aplicación. No se encontraron servicios correspondientes.

Retorno sí es relevante si se necesitan devoluciones/reembolsos de FE o FEX. Manual funcional, páginas 26-28: aplica a FE/FEX/FSEE; hasta 50 documentos del mismo tipo y con identidad compatible; suma de retornos limitada por el documento; plazo de tres meses desde recepción. No sustituirlo automáticamente por la NC existente de CCF. Requiere flujo de autorización, emisión, firma, recepción, invalidación, entrega y efecto en saldos.

Operaciones especiales aplica a facturas simplificadas y comprobantes de control interno autorizados (páginas 20-25). Su existencia en la normativa no prueba que esta empresa necesite emitirlo. Confirmar operación y autorización; si no aplica, registrar explícitamente la exclusión del alcance. Si aplica, hacerlo antes de contingencia.

### 6. Reglas transversales pendientes de cierre completo

- Obtener Normativa 2.0 y anexos para matriz de validaciones por campo/tipo. El propio manual técnico sección 6.1 señala que el JSON Schema es referencial y no sustituye las reglas de negocio.
- Revisar catálogos efectivamente importados en la base objetivo; esta auditoría solo comparó archivos y selección en configuración.
- Revisar documentos relacionados, tributos aplicables al negocio, descuentos, pagos, redondeos y límites. Algunos bloques se serializan siempre como `null`; documentar qué operaciones no se soportan, sin inventar datos para completarlos.
- Revisar fusiones y operaciones por cuenta de terceros solo para los documentos/casos aplicables. No agregar `fusion` indiscriminadamente: en el ZIP entregado las identificaciones de FE v2, CCF v4 y FEX v3 no admiten esa propiedad; NC v4 sí la exige (puede ser null).
- Revisar representación gráfica y descarga/entrega tomando los datos fiscales históricos, no los datos actuales de un cliente editado después de emitir. La invalidación lee actualmente el modelo de cliente y merece una prueba específica de esta situación.
- Cotejar íntegramente los capítulos técnicos de firma, autenticación, recepción, consulta y reintentos con implementación actual; aprovechar las pruebas existentes.
- Actualizar documentación obsoleta: `docs/TRANSMISION_DTE.md` mezcla hitos reales con apartados que todavía describen servicios como futuros o deshabilitados. No inferir el estado de producción de esos textos.

## Orden propuesto para Claude

1. Corregir política de invalidación (motivos, sustitutos y relaciones); definir calendario/plazos con la fuente oficial completa.
2. Completar archivo de entrega con firma y sello para correo y descarga.
3. Implementar y validar transición NC v3/v4, conservando históricos y evidencia del rechazo previo.
4. Cerrar validaciones y representación de FE/CCF/FEX/NC con matriz contra anexos; verificar catálogo importado.
5. Implementar retorno si se operarán esos casos y resolver aplicabilidad de operaciones especiales.
6. Ejecutar matriz local y validación controlada en MH pruebas; registrar evidencia por tipo y versión.
7. Contingencia al final: evento, generación durante interrupción, lotes, recuperación, consulta, plazos y entrega posterior. Hoy aplazarla significa que el cierre total de Hacienda 2.0 sigue pendiente.

Para cada bloque: cambios acotados, pruebas de reglas reales, resumen de evidencia y revisión de Codex. No hacer commit, push, despliegue ni cambios de producción sin instrucción expresa. Respetar modificaciones locales preexistentes y `firmware/asistencia/asistencia.ino`.

## Qué permite declarar cerrado un bloque

- Requisito y revisión oficial identificados.
- Implementación y pruebas locales verificadas, con casos de rechazo y límites.
- Prueba de integración aceptada cuando cambie un contrato con MH; un test simulado no cuenta como sello real.
- Conservación de JSON/JWS/respuestas históricos y correspondencia de PDFs/archivos entregados.
- Operación y mensajes comprensibles para quien factura.

El manual funcional, página 76, lista mínimos por tipo/evento para autorización. Consultar cuáles corresponden a la habilitación actual de este emisor; no asumir que actualizar obliga a repetir automáticamente toda la autorización ni que un único documento aceptado la reemplaza.
