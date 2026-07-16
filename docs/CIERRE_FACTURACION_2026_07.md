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
| **Factura consumidor final (01)** | Validada y **aceptada por Hacienda en APITEST** (DTE #127). Bloqueada para producción real hasta decisión explícita — guard "en revisión" activo. |
| **Factura de Exportación (11)** | Validada y **aceptada por Hacienda en APITEST** (DTE #130). Bloqueada para producción real hasta decisión explícita — guard "en revisión" activo. |

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
- Guards de producción para tipo 01 y FEX (tipo 11) **siguen activos** tanto en el flujo web (`DteController@firmarTransmitir`) como en consola (`DteFirmarCommand`, `DteTransmitirCommand`): bloquean firma/transmisión real en producción para ambos tipos mientras `emisionRealPosible()` sea verdadero, exigiendo revisión explícita.
- La frase de confirmación manual para emisión real a producción sigue siendo exactamente `EMITIR PRODUCCION`.
- Las credenciales de `testing` no tienen fallback a producción (fallan antes de cualquier HTTP si faltan).

## 6. Regla de negocio

**No habilitar Factura consumidor final (01) ni Factura de Exportación (11) en producción hasta una decisión separada y explícita.** Los guards "en revisión" existentes deben quitarse deliberadamente, con autorización expresa, no como efecto secundario de otro cambio.

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

Esta fase **no se inicia en este documento ni en esta sesión** — queda pendiente de instrucción explícita por separado.
