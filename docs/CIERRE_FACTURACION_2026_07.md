# Cierre del módulo de Facturación — julio 2026

> Auditoría final antes de migrar el sistema a otra PC. Este documento resume el
> estado validado de cada tipo de documento, los errores corregidos en el camino,
> y las reglas que deben respetarse hasta que se tome una decisión explícita
> sobre habilitar producción real para Factura consumidor final y Factura de
> Exportación.

## 1. Estado final por tipo de documento

| Documento | Estado |
|---|---|
| **CCF (Comprobante de Crédito Fiscal)** | Real, operativo y aceptado en producción. Correlativo productivo actual: **1120**. |
| **Nota de Crédito (05)** | Real, operativa y aceptada en producción. |
| **Factura consumidor final (01)** | **Validada en APITEST** (DTE #127, aceptada por Hacienda), **habilitada en código bajo los mismos candados que protegen a CCF** y con su **correlativo productivo ya creado** (tipo 01/ambiente 01, `ultimo_numero=0`, primer número previsto **1**). Operativamente lista para producción, igual que CCF. **Todavía no se ha emitido ninguna Factura tipo 01 real en producción.** |
| **Factura de Exportación (11)** | **Validada en APITEST** (DTE #130, aceptada por Hacienda), **habilitada en código bajo los mismos candados que protegen a CCF** y con su **correlativo productivo ya creado** (tipo 11/ambiente 01, `ultimo_numero=0`, primer número previsto **1**). Operativamente lista para producción, igual que CCF. **Todavía no se ha emitido ninguna FEX real en producción.** |

## 2. DTE de validación

- **DTE #127** — Factura consumidor final, ambiente 00 (testing), aceptada por Hacienda. Código MH `001`, mensaje `RECIBIDO`. Sello de recepción real de APITEST.
- **DTE #130** — Factura de Exportación, ambiente 00 (testing), aceptada por Hacienda. Código MH `001`, mensaje `RECIBIDO`. Sello de recepción real de APITEST.

Ambos documentos quedaron **firmados y transmitidos únicamente contra APITEST**, con producción restaurada inmediatamente después de cada prueba. No se transmitió producción real para ninguno de los dos tipos.

## 3. Errores corregidos durante esta ronda de validación

1. **Distrito vacío en Factura tipo 01** — `SerializadorFacturaMh` no propagaba `emisor.direccion.distrito`; causó el rechazo real de DTE #125 (código 096).
2. **Montos brutos incorrectos en Factura tipo 01** — `ventaGravada`/`totalGravada` se enviaban netos (sin IVA) en vez de brutos (con IVA incluido), como exige el schema real de Hacienda para Factura consumidor final; causó el rechazo real de DTE #126 (código 003).
3. **Separación de credenciales de producción y APITEST** — antes de esta ronda, un mismo par de credenciales servía para ambos ambientes; ahora `testing` no tiene fallback a producción (falla explícita si faltan) y `production` usa su propio par.
4. **Puerto del firmador desalineado (8113 vs 8080)** — la app esperaba el firmador en `localhost:8080` (`DTE_FIRMADOR_URL`), pero el JAR arranca por defecto en `8113`; `firmador-auto.bat` no forzaba el puerto. Corregido fijando `--server.port=8080` en el script oficial de arranque, más los defaults consistentes en `config/dte.php` y `.env.example`.
5. **Distrito vacío en Factura de Exportación (FEX)** — mismo patrón que el error 1, pero en `SerializadorExportacionMh`, que hardcodeaba `'distrito' => ''` en vez de leer `$e->distrito`; detectado antes de transmitir (DTE #128 quedó como evidencia, sin firmar).
6. **Tributo C3 faltante en FEX** — `cuerpoDocumento[].tributos` y `resumen.tributos` se enviaban como `null`; Hacienda exige el código `C3` ("Impuesto al Valor Agregado (exportaciones) 0%") aunque la tasa sea 0%. Causó el rechazo real de DTE #129 (código 005). Corregido con el código fijo `C3` en `SerializadorExportacionMh`.

## 4. Commits relevantes

```
487dcf3 Corregir distrito en factura consumidor final
2e054c7 Separar credenciales de produccion y apitest
7d13b19 Corregir montos brutos en factura consumidor final
b606a7d Fijar puerto 8080 del firmador
184a158 Corregir distrito en factura de exportacion
5c61330 Agregar tributo C3 a factura de exportacion
```

## 5. Estado de producción

- CCF producción: **1120** (intacto durante toda esta ronda).
- Próximo CCF de producción: **1121**.
- Certificado productivo activo (verificado por MD5 conocido, sin exponer el archivo).
- `DTE_AMBIENTE=01`, `DTE_TRANSMISION_AMBIENTE=produccion`, URL productiva `https://api.dtes.mh.gob.sv`.
- **Habilitación operativa de tipo 01 y FEX (esta ronda):** los guards TEMPORALES específicos por tipo ("en revisión") fueron RETIRADOS de `DteController::firmarTransmitir()`, `DteFirmarCommand` y `DteTransmitirCommand`. Tipo 01 y FEX ahora siguen exactamente el mismo camino que CCF, protegidos por los candados GENERALES (nunca tocados):
  - máquina de estados (`DtePolicy`: no se firma un documento rechazado/aceptado/anulado; no se retransmite un aceptado);
  - frase exacta `EMITIR PRODUCCION` para cualquier emisión real (`DteController::firmarTransmitir`/`generarTransmitirProduccion`);
  - preflight ESPECÍFICO por tipo integrado en "Generar y transmitir producción": CCF sigue usando `PreflightEmisionProduccion`, tipo 01 usa `PreflightEmisionProduccionFactura` (misma lógica de `dte:preflight-factura`), FEX usa `PreflightEmisionProduccionExportacion` (misma lógica de `dte:preflight-fex`) — antes esa pantalla estaba disponible solo para CCF (`DtePolicy::generarTransmitirProduccion` ahora acepta los tres tipos);
  - credenciales de `testing` sin fallback a producción (fallan antes de cualquier HTTP si faltan) y viceversa;
  - certificado y URL efectiva se siguen resolviendo por ambiente, no por tipo de documento.
- **Todavía NO se ha emitido ninguna Factura tipo 01 ni FEX real en producción** — la habilitación es de código/candados, no una emisión.
- Toda emisión real (cualquier tipo) sigue exigiendo escribir exactamente `EMITIR PRODUCCION`; sin la frase, o con una frase incorrecta, no se firma, no se transmite y no se envía correo.

## 6. Regla de negocio

Tipo 01 y FEX quedan operativamente listas (mismos candados que CCF, correlativo productivo propio), pero la primera emisión REAL en producción de cada tipo requiere una decisión y ejecución explícitas y separadas — no ocurre por default ni como efecto de este cambio. **La migración a la otra PC se realiza después de este commit.**

## 7. Hallazgo — RESUELTO

Durante la revisión de PDF/impresión de #127 y #130 se detectó que ni el PDF (`facturacion.pdf`), ni la vista de impresión (`facturacion.imprimir`), ni la ficha (`facturacion.show`) mostraban una marca visible de "ambiente de pruebas". El PDF decidía su presentación únicamente según si el documento tenía sello (`$tieneSello`): al tener sello real de APITEST, #127 y #130 se imprimían como PDF "limpio", visualmente idénticos a un documento aceptado en producción real.

**Corrección aplicada:** se agregó una advertencia — "AMBIENTE DE PRUEBAS · Documento sin validez fiscal en producción" — a los tres lugares, usando **únicamente** `$dte->ambiente` como fuente de verdad (nunca el sello ni el estado):

- `resources/views/facturacion/pdf.blade.php` — cinta fija antes de la cinta de estado existente.
- `resources/views/facturacion/imprimir.blade.php` — mismo criterio, mismo lugar.
- `resources/views/facturacion/show.blade.php` — nuevo componente `<x-ambiente-pruebas-aviso>` junto a la tarjeta de estado.

**Ambiente `00`**: la advertencia aparece siempre (aceptado, firmado, generado o rechazado; con o sin sello real). **Ambiente `01`**: no se muestra nada y el documento conserva exactamente su apariencia oficial anterior. Verificado con #127, #130 y el CCF productivo #117 (id interno) — este último sin ninguna advertencia. Cubierto por `tests/Feature/Dte/AmbientePruebasAvisoTest.php` (Factura 01, FEX 11, CCF y NC).

## 8. Próxima fase (no iniciada todavía)

- Migrar el sistema a la otra PC.
- Arranque controlado en el nuevo entorno (Laragon, PHP, firmador local en puerto 8080, worker de cola).
- Configurar acceso remoto.

Esta fase **no se inicia en este documento ni en esta sesión** — queda pendiente de instrucción explícita por separado, luego de este commit.

## 9. Cierre de la habilitación productiva — Factura tipo 01 y FEX tipo 11

Con el correlativo productivo creado para ambos tipos, Factura consumidor final (01) y Factura de Exportación (11) quedan **operativamente listas para producción bajo exactamente los mismos candados que CCF**:

- **Correlativo productivo Factura (01/01):** fila creada, `establecimiento_id=1`, `punto_venta_id=1`, `ultimo_numero=0`, `activo=true`. Primer número que produciría: **1**.
- **Correlativo productivo FEX (11/01):** fila creada, `establecimiento_id=1`, `punto_venta_id=1`, `ultimo_numero=0`, `activo=true`. Primer número que produciría: **1**.
- Sin historial real previo para ninguno de los dos tipos en ambiente 01 (a diferencia de CCF, no hubo un sistema externo emitiéndolos en paralelo).
- Firmador local operativo: proceso único, puerto 8080, responde `200` ("Application is running...!!").
- Backup del día verificado y reconocido por el preflight de producción.
- **Todavía no se ha emitido ninguna Factura tipo 01 ni FEX real en producción** — la habilitación es de código, candados y numeración; la primera emisión real sigue siendo una decisión y ejecución separadas.
- Toda emisión productiva (cualquier tipo) exige escribir exactamente la frase `EMITIR PRODUCCION`; sin ella, o con una frase distinta, no se firma, no se transmite y no se envía correo.
