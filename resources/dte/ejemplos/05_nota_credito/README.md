# Nota de Crédito electrónica (tipo 05) — estructura VERSIÓN 3

> **Regla final, validada contra Hacienda (MH El Salvador).** No volver a v4.

## Por qué v3 y no v4

El MH **rechaza** la NC tipo 05 enviada con la estructura **v4**. Cualquier envío con
`resumen.totalIva` (y `totalIva` por línea) produce:

```
codigoMsg 020 · clasificaMsg 14 · [resumen.totalIva] CALCULO INCORRECTO
```

…sin importar el valor del IVA (se confirmó con el caso base `1.00 × 0.13 = 0.13`, que es
aritméticamente correcto y aun así fue rechazado). Tras 7 intentos rechazados con v4, se obtuvo
una **NC tipo 05 real aceptada** (ver `nc-aceptada-referencia.json`, sello
`20268264AF7ECDFD…`) que usa **estructura v3**. Al replicarla, la NC #60 fue **ACEPTADA**
(HTTP 200, sello `202625D3A736363D4BE9AE5094EC49FD3F8EAW7F`).

## Reglas de la estructura v3

| Sección | Regla |
|---|---|
| `identificacion.version` | **3** (no 4); **sin** `fusion` |
| IVA | **solo** en `resumen.tributos[0].valor = round(subTotal × 0.13, 2)` |
| `cuerpoDocumento[].totalIva` | **NO existe** (tampoco `ivaPerci`, `ivaRete`, `noGravado` por línea) |
| `resumen.totalIva` | **NO existe** (tampoco `totalPagar`, `totalNoGravado`, `codigoRetencionMH`, `pagos`) |
| `cuerpoDocumento[].ventaGravada` | **BRUTO** (antes de descuento) |
| `cuerpoDocumento[].montoDescu` | descuento de línea (0 cuando el CCF aplicó descuento global) |
| Descuento del CCF | **GLOBAL** en `resumen.descuGravada` / `totalDescu`; `subTotal = totalGravada − descuGravada` |
| `resumen` extra v3 | `subTotal`, `ivaPerci1`, `ivaRete1`, `reteRenta`, `montoTotalOperacion = subTotal + IVA` |
| `receptor` | **`nit`** directo (solo dígitos); **sin** `tipoDocumento`/`numDocumento` |
| dirección (emisor/receptor) | **sin** `distrito` (solo `departamento`, `municipio`, `complemento`) |
| `emisor` | con `tipoEstablecimiento` |
| `extension` | presente, con campos null |
| `condicionOperacion` | **1** (la NC es un ajuste, no una venta a crédito) |
| `documentoRelacionado` | obligatorio (CCF original); cada línea referencia su `codigoGeneracion` |

## Dónde vive la implementación

- Serializador: `app/Services/Dte/Serializadores/SerializadorNotaCreditoMh.php`
- Schema: `resources/dte/schemas/05_nota_credito/fe-nc-v3.json`
- Versión por tipo: `config/dte.php` → `json.versiones['05'] = 3`
- Descuento heredado (global) en NC por productos: `DteBorradorService::porcentajeDescuentoVigente()`
- Tests: `tests/Feature/Dte/SerializadoresMhMultiTipoTest.php` (invariantes v3)

## Archivos de referencia (en esta carpeta)

- `nc-aceptada-referencia.json` — NC tipo 05 real aceptada por el MH (referencia de oro).
- `nc60-enviado.json` — JSON oficial que enviamos en la NC #60 (aceptada).
- `nc60-respuesta-mh.json` — respuesta del MH (RECIBIDO + sello).
- `nc60-final.pdf` — representación gráfica con sello/QR.
- `comparar_nc.php` — comparador funcional (ignora montos/identificadores/datos; marca solo
  diferencias estructurales). Uso: `php comparar_nc.php <nuestra.json> <aceptada.json>`.
