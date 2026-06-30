# Nota de Crédito por AVERÍA (tipo 05, estructura v3) — referencia real aceptada

> Primera NC por **avería con productos manuales** aceptada por Hacienda contra un **CCF real con saldo**.

## Documento de referencia (NC #67)

| Campo | Valor |
|---|---|
| Tipo | Nota de Crédito por **avería** |
| numeroControl | `DTE-05-M001P001-000000000000019` |
| codigoGeneracion | `F41B191D-C1EB-42AF-831B-9666A5F2F386` |
| **selloRecepcion** | `2026EFC8F4E4BA3042D682A7C5C4A7335488JCTV` |
| fecha procesamiento MH | `2026-06-26 06:17:14` |
| totalGravada | 14.70 |
| IVA (resumen.tributos[20].valor) | 1.91 |
| total (montoTotalOperacion) | 16.61 |
| CCF relacionado real | **#66** · `6B70F9AC-EF80-402A-AFEB-11B2BAFDD3D1` (sello real `202613B8…`) |
| saldo gravado del CCF #66 tras esta NC | 81.98 − 14.70 = **67.28** |

Cliente: Calleja, S.A. de C.V. · sala Súper Selectos Cara Sucia.

## Reglas de la NC por avería

1. **Productos manuales:** la avería permite agregar **cualquier producto del catálogo**, aunque
   NO esté en las líneas del CCF relacionado (a diferencia de devolución/faltante, que se limitan
   a las líneas del CCF). Ej.: la #67 acreditó COCO RALLADO, DULCE DE NANCE y Pepitoria.
2. **CCF realmente aceptado por el MH (obligatorio):** toda NC requiere un CCF (03) relacionado que
   esté **realmente aceptado por Hacienda** — no basta `estado = aceptado` local. Criterio
   (`Dte::aceptadoRealmentePorMh()`): estado aceptado + `sello_recepcion` real (NO `MOCK…`) +
   `fecha_procesamiento_mh` presente. Los CCF mock/simulados NO sirven (su codigoGeneracion no
   existe en el MH → `codigoMsg 014`).
3. **Saldo disponible:** el CCF debe tener **saldo gravado disponible** =
   `CCF.totalGravada − Σ totalGravada de NC reales aceptadas previas`. La NC **no puede superar**
   ese saldo (si lo supera, el MH rechaza con `codigoMsg 016` y `ValidacionPreJsonService` lo
   bloquea localmente antes de generar/firmar/transmitir).
4. **Estructura NC tipo 05 v3** (igual que el resto de NC aceptadas):
   - `identificacion.version = 3`, sin `fusion`; `condicionOperacion = 1`.
   - **NO** se usa `resumen.totalIva` ni `cuerpoDocumento[].totalIva`.
   - El **IVA va solo en `resumen.tributos[0].valor`** (código `20`), calculado sobre el subTotal.
   - `ventaGravada` bruto; receptor con `nit`; sin `distrito`; con bloque `extension`.
5. **Relación con el CCF real:**
   - `documentoRelacionado[0].numeroDocumento` = `codigoGeneracion` del CCF real.
   - `cuerpoDocumento[].numeroDocumento` = ese mismo `codigoGeneracion` (en **todas** las líneas,
     incluidas las de productos manuales de avería).

## Archivos en esta carpeta
- `nc67-enviado.json` — JSON oficial transmitido (estructura v3).
- `nc67-respuesta-mh.json` — respuesta del MH (RECIBIDO + sello).
- `nc67-final.pdf` — representación gráfica con sello/QR.

Ver también la referencia general de NC v3 en `../README.md` y la NC aceptada `../nc-aceptada-referencia.json`.
