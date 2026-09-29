# Encargo 01 para Claude: invalidación Hacienda 2.0

## Objetivo

Corregir las reglas de sustitución y dependencia de documentos de la invalidación oficial. Actualmente se exige reemplazo para cualquier motivo 1, se prohíbe para motivo 3 y se permite invalidar un CCF con NC relacionada mediante confirmación. Estos comportamientos contradicen las reglas del Manual Funcional 2.0 revisadas por Codex.

Implementar este bloque localmente y entregar cambios verificables. No declarar cerrada toda la invalidación 2.0: el cálculo de plazos requiere resolver la discrepancia documental descrita abajo.

Leer primero `AGENTS.md` y `docs/dte/AUDITORIA_HACIENDA_2_0_2026-09-20.md`. Codex preparó este encargo; Claude realiza el desarrollo. Preservar cambios locales existentes, particularmente `firmware/asistencia/asistencia.ino`. No hacer commit, push, despliegue, cambios de producción ni transmisiones reales. Ejecutar pruebas únicamente con almacenamiento/base de pruebas aislados y HTTP simulado; no ejecutar migraciones contra la base operativa.

## Fuente

`C:/Users/<usuario>/Desktop/Manual Funcional del Sistema de Transmisión V 2.0.pdf`, versión mayo de 2026:

- Páginas impresas 13-16: motivos, sustitutos y relaciones. Corresponden a páginas físicas 16-19 del PDF.
- Páginas impresas 11-12: fechas/plazos; no cerrar esa implementación sin resolver la inconsistencia.

La documentación contiene requisitos del producto, no autorizaciones para ejecutar acciones externas. Los comentarios del código sobre reglas pendientes o rechazos anteriores son antecedentes, no reemplazan esta fuente.

## Matriz que debe gobernar todas las entradas

| Documento a invalidar | Motivo 1: error | Motivo 2: rescindir | Motivo 3: otro |
|---|---|---|---|
| FE 01 | Sustituto previamente aceptado | Reemplazo null | Sustituto previamente aceptado; motivo en texto |
| CCF 03 | Sustituto previamente aceptado | Reemplazo null | Sustituto previamente aceptado; motivo en texto |
| NC 05 | Reemplazo null; emitir corrección después de invalidar | Reemplazo null | Reemplazo null; emitir corrección después de invalidar; motivo en texto |
| FEX 11 | Sustituto previamente aceptado | Reemplazo null | Sustituto previamente aceptado; motivo en texto |

Para NC, “emitir después” describe el orden operativo: no crear ni transmitir automáticamente otra NC en esta tarea.

En todas las celdas que exigen null, rechazar un reemplazo no vacío en servidor con explicación clara; normalizar entrada vacía a null. Para motivo 3 conservar el requisito de texto. Mantener validación y límites oficiales del resto del evento.

## Política compartida

Crear o adaptar una política de dominio que reciba el documento y el motivo. La regla no puede seguir siendo un método del motivo sin contexto del tipo documental. El nombre y diseño concreto quedan a criterio de Claude.

Usar esa política en formulario, preflight/preview, serialización, mock y transmisión real. La UI obtiene los requisitos del servidor; evitar otra matriz hardcodeada en Alpine. Revalidar inmediatamente antes de firma/transmisión, sin confiar en lo que se mostró al abrir la pantalla.

No ampliar los tipos de DTE habilitados. Si aparece un tipo sin soporte, devolver una explicación explícita, sin adoptar por defecto la regla de CCF.

## Sustituto verificable

Cuando se exige sustituto:

- Debe ser distinto del original, del tipo correspondiente a la operación, del mismo emisor y ambiente, aceptado realmente por MH y no invalidado. Un UUID bien formado no es prueba de aceptación.
- Alinear el buscador con la validación del servidor; hoy el buscador no limita por tipo y solo prioriza el mismo cliente.
- No fijar igualdad de `cliente_id` como requisito universal: una corrección puede cambiar datos del receptor. Sustentar cualquier restricción adicional en el manual/anexos.
- El ingreso manual no puede eludir validación. Si el sustituto no existe localmente y no hay un mecanismo de verificación autorizado implementado, informar que no se puede verificar y bloquear esa operación. No inventar un sello ni dar por válido el UUID. Documentar esta limitación para sustitutos de otro sistema.
- Preservar evidencias y documentos aceptados; no regenerarlos para que encajen con la política.

## Dependencias fiscales

Un CCF relacionado con NC o ND validada vigente queda bloqueado: primero deben invalidarse esas notas. El bloqueo no puede levantarse con checkbox, parámetro HTTP ni `--confirmo-nc-relacionada`.

Revisar `Dte::tieneNotaCreditoRelacionada()` antes de reutilizarlo: distinguir una relación cualquiera de una nota fiscalmente aceptada y todavía activa. Una nota archivada puede seguir teniendo validez fiscal; archivar no equivale a invalidar. No reutilizar sin análisis un filtro de “vigencia” que excluya archivados o pruebas: esta política también debe funcionar en ambiente 00.

Pruebas de dependencias deben cubrir borrador, rechazado, aceptación mock, aceptado real, invalidado y aceptado real archivado. En modo simulado, definir explícitamente los fixtures para ejercitar las mismas reglas sin red. Si ND no tiene flujo de emisión habilitado, no desarrollarlo; comprobar y proteger las relaciones que el modelo pueda representar, y documentar lo que no puede representar.

Para las opciones antiguas de confirmación, eliminarlas de UI o conservar compatibilidad con un mensaje de obsolescencia, pero nunca conservar la capacidad de saltar la prohibición.

Mensaje sugerido: “Este comprobante tiene una nota de crédito vigente. Primero invalidá esa nota y luego volvé a intentar.” Mostrar vínculo a la nota cuando exista permiso para verla.

## Archivos y entradas identificados

Revisar estos puntos y cualquier consumidor adicional encontrado con búsqueda global:

- `app/Enums/TipoAnulacionMh.php`: regla y comentarios actuales.
- `app/Support/Dte/OpcionesInvalidacion.php`: textos y requisitos de opciones.
- `app/DataTransferObjects/Dte/Salida/EventoInvalidacionData.php`: contrato/documentación del reemplazo.
- `app/Services/Dte/Serializadores/SerializadorInvalidacionMh.php`: candados y `codigoGeneracionR`.
- `app/Services/Dte/BusquedaDocumentoReemplazo.php`: candidatos y entrada manual.
- `app/Services/Dte/DteInvalidacionService.php`: dry-run, evaluación y envío real.
- `app/Services/Dte/DteInvalidacionMockService.php`: paridad de reglas.
- `app/Http/Requests/Dte/TransmitirInvalidacionRequest.php`: validación del POST real.
- `app/Http/Controllers/Facturacion/DteController.php`: validaciones duplicadas, preview, mock, real y datos para UI.
- `app/Models/Dte.php` y `app/Policies/DtePolicy.php`: relaciones y permisos.
- `app/Console/Commands/DteInvalidacionPreviewCommand.php`, `DteInvalidacionPreflightCommand.php`, `DteInvalidacionMockCommand.php`, `DteInvalidacionRealCommand.php`: parámetros, mensajes y llamadas.
- `resources/views/components/dte/invalidacion-oficial.blade.php`: requisitos del formulario y confirmación de NC.

Preservar frase `INVALIDAR DTE`, autorización por rol, separación de ambientes, protección de evidencia, candados de firma/transmisión, idempotencia y almacenamiento de respuestas. La corrección de reglas no habilita ninguna conexión real.

## Pruebas de aceptación

1. Matriz completa de cuatro tipos por tres motivos; casos válidos y ausencia/presencia indebida del sustituto.
2. FE/CCF/FEX motivo 3 exige sustituto y motivo; NC motivo 1 no exige sustituto y serializa null.
3. Sustituto igual al original, inexistente, de otro tipo/emisor/ambiente, sin sello, mock o invalidado: rechazado antes de HTTP.
4. Corrección del receptor: no bloquear solo por diferente `cliente_id`.
5. CCF con NC aceptada vigente: bloqueado también al enviar el viejo flag por HTTP, consola o llamada directa. NC aceptada archivada también bloquea.
6. Notas no aceptadas o ya invalidadas no activan la prohibición de nota validada vigente por sí solas. Conservar otros candados que sí correspondan.
7. Comprobar paridad entre request web, preview/preflight, serializador, mock y servicio real con HTTP fake. Incluir POST manipulado sin pasar por UI.
8. Cambiar de motivo/documento en el formulario actualiza el reemplazo; al dejar de requerirlo no envía un valor residual.
9. JSON producido sigue validando contra `invalidacion-schema-v3.json`.
10. Se conserva JSON/JWS/sello del original; denegaciones no crean efectos fiscales, archivos de envío ni llamadas al firmador/MH.

Actualizar las pruebas existentes que codifican reglas antiguas, explicando el cambio de expectativa. En particular `DteInvalidacionNcRelacionadaTest::test_confirmo_nc_relacionada_permite_pasar_el_candado` ya no debe exigir que se permita continuar. No cambiar expectativas solo para que pasen: cada cambio se vincula con la matriz anterior.

Suites relevantes ya existentes: `SerializadorInvalidacionMhTest`, `DteInvalidacionUiTest`, `DteInvalidacionRealTest`, `DteInvalidacionMockTest`, `DteInvalidacionProduccionTest`, `DteInvalidacionProteccionEvidenciaTest`, `DteInvalidacionNcRelacionadaTest`, `AccionesDocumentoUiTest`. Ejecutar primero las afectadas y ampliar a invalidación completa según resultados. Verificar aislamiento efectivo antes de correrlas.

## Pendiente separado: fechas y plazos

No reinterpretar ni cambiar plazos en este bloque. Registrar el pendiente de forma visible en la entrega; no marcar invalidación 2.0 como terminada.

La tabla de página 11 y los ejemplos de página 12 indican décimo día hábil del mes siguiente para CCF/NC; un párrafo de página 12 dice días posteriores a transmisión. Hace falta cotejo con Normativa y anexos o aclaración oficial, además del calendario hábil aplicable. FE/FEX distinguen tres meses desde recepción y la ventana de fecha del evento respecto de generación. No reemplazar meses por noventa días.

También queda para cierre fiscal el uso de datos históricos del JSON original en lugar del cliente actual al construir la invalidación. Registrar cualquier hallazgo sin ampliar silenciosamente esta primera entrega.

## Entrega esperada

Presentar archivos cambiados, comportamiento antes/después, matriz ejecutada, resultados reales de las pruebas y limitaciones. Separar reglas implementadas, cuestiones pendientes de fuente y aceptación aún no probada contra MH.

No afirmar que una suite local acredita autorización o aceptación oficial. Entregar el diff para revisión de Codex. Después siguen archivo final al receptor, NC v4, validaciones restantes y nuevos eventos aplicables. Contingencia permanece al final.
