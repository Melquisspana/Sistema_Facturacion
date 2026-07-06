# Día 2 — Segunda jornada de validación y salida al rol principal

Guía **corta** para la **segunda jornada** de validación del sistema nuevo antes de darle el
**rol principal** (emisión oficial). Complementa el piloto (`docs/PILOTO_PREPRODUCCION.md`):
el piloto ya cerró **10/10 casos aprobados** en un día; el criterio de salida exige **repetir
una ronda en un segundo día distinto** antes de producción.

> **Solo procedimiento.** No cambia lógica del sistema (facturación, DTE, firma, transmisión,
> correo, colas ni PDF). Es una lista de pasos para repetir la comparación y decidir go/no-go.

---

## 0. Regla de oro (leer primero)

- Durante el Día 2 el sistema nuevo **sigue en modo paralelo seguro**: genera, calcula, arma
  JSON/PDF, firma mock y hace dry-run, **sin transmitir a Hacienda**. **Conta Portable sigue
  siendo el emisor oficial.**
- El objetivo del Día 2 es **reconfirmar** que el sistema nuevo produce **los mismos números y
  documentos** que Conta Portable, en un día distinto y con operación real de referencia.
- Solo **después** de un Día 2 limpio se evalúa el **go/no-go** (sección 4) para pasar al rol
  principal. Ante cualquier duda no explicada, **manda Conta Portable**.

---

## 1. Casos a repetir en el Día 2

Repetir un subconjunto representativo (no hace falta los 10; sí cubrir cada familia). Comparar
campo por campo contra Conta Portable, con la leyenda de `docs/PILOTO_PREPRODUCCION.md` §2.

| # | Caso a repetir | Qué reconfirma |
|---|----------------|----------------|
| 1 | **CCF normal** (sin retención) | Cálculo base, IVA 13%, total |
| 2 | **CCF Calleja con OC + sala** | Sala de entrega, OC en apéndice, precio especial, descuento 5%, retención |
| 3 | **NC devolución o avería** | Relación al CCF real-aceptado, líneas/saldo, IVA v3, descuento (avería hereda 5%) |
| 4 | **NC pronto pago** | Concepto manual (sin producto), unidad CAT-014 99, IVA, total |
| 5 | **FEX / exportación** | País (CAT-020), actividad (CAT-019), `tipoPersona` (2 jurídica), IVA 0%, total |
| 6 | **Correo + PDF** | El correo sale (worker) con PDF+JSON; el PDF abre para imprimir |

> Usar, cuando aplique, los mismos insumos del piloto (CCF real-aceptados #66/#71 para NC;
> cliente de exportación #16 para FEX) o los equivalentes reales del día.

---

## 2. Checklist ANTES de iniciar (una vez al empezar el Día 2)

Revisar en orden. Si algo falla, resolver **antes** de comparar.

- [ ] **Backup reciente** — hay un `.zip` de **hoy** en `storage\app\private\Dulces La Negrita\`
      (si no, `scripts\backup-run.bat`).
- [ ] **Worker de colas activo** — en **Salud del sistema** (`/admin/salud-sistema`) el worker
      aparece **activo** (si no, `start-queue.bat` / tarea `queue-worker-auto.bat`).
- [ ] **0 jobs fallidos** — contador de la barra en 0 (o `php artisan queue:failed`).
- [ ] **Modo paralelo confirmado** — badge del navbar en verde **PARALELO SEGURO** (o
      `php artisan dte:modo-operacion`). Si aparece **rojo** (producción) o **ámbar** (apitest),
      parar y revisar antes de comparar.
- [ ] **`APP_DEBUG=false`** — confirmado (`php artisan tinker --execute="var_dump(config('app.debug'));"`).
- [ ] **Firmador disponible** — en modo mock durante el paralelo; confirmar que la firma local
      responde (los documentos avanzan Generado→Firmado en las pruebas de firma). La firma/
      transmisión **reales** siguen candadas y solo por consola.
- [ ] **Correo SMTP probado** — enviar un CCF de prueba **al correo de prueba controlado**
      (no al cliente) y confirmar que llega con **PDF + JSON**; job 0→1→0, 0 fallidos.

---

## 3. Criterios de aprobación (por documento)

Un documento del Día 2 se da por **✅ OK** si:

- [ ] **Total** coincide con Conta Portable: diferencia **$0.00**, tolerancia máxima **±$0.01**
      por redondeo. Cualquier diferencia mayor = **❌ fallo a investigar**.
- [ ] **PDF correcto** — datos del receptor, líneas, totales y marcas ("NO TRANSMITIDO / SIN
      SELLO" mientras es preliminar) coinciden con lo emitido en Conta Portable.
- [ ] **JSON válido** — se genera el JSON oficial preliminar sin errores de schema (CCF `fe-ccf`,
      NC v3, FEX `fe-fex`).
- [ ] **Sin transmisión accidental** — el documento queda **sin sello** (no transmitido); el
      badge sigue **PARALELO SEGURO** durante toda la jornada.

La jornada se considera **Día 2 limpio** si **todos** los casos repetidos quedan ✅, sin
diferencias > $0.01 ni errores de flujo.

---

## 4. Plan de go / no-go

### ✅ Se PUEDE pasar al rol principal cuando:
- Los **10 casos** quedaron ✅ en **dos días distintos** (piloto Día 1 + este Día 2), sin
  diferencias de total > $0.01 ni errores de flujo.
- El correo sale con adjuntos y **0 jobs fallidos** ambos días.
- No hubo ninguna transmisión accidental (badge verde toda la jornada).
- Backups del día verificados y restauración probada (`docs/RESTORE_BACKUP_WINDOWS.md`).

### ⛔ NO pasar (o VOLVER a Conta Portable de inmediato) si:
- Cualquier **total** difiere de Conta Portable en **más de $0.01** sin explicación.
- El sistema nuevo **no genera** (error de validación/schema).
- El **worker** no levanta o los correos no salen y hay que facturar ya.
- El badge aparece **rojo** (producción) sin haberlo decidido, o hay señal de transmisión no
  planificada.
- Dudas sobre cliente/sala/OC/producto/precio que no se resuelven en el momento.

### 📸 Qué capturar si algo falla (para diagnosticar después)
- Captura del documento en el sistema nuevo (con totales) **y** del equivalente en Conta Portable.
- **Número interno** del documento nuevo (`INT-…`) y número del documento de Conta Portable.
- El **mensaje de error** exacto (si lo hubo) al generar/enviar.
- Datos de la operación: cliente, sala, OC, líneas (producto, cantidad, precio).
- Si es de correo: estado del envío (enviado/fallido) y el error mostrado.

*(Mismo detalle que `docs/PILOTO_PREPRODUCCION.md` §14.)*

---

## 5. Primer día en producción real (después del go)

Arranque **gradual y con red**, no todo de golpe:

- **Empezar con 1–2 documentos reales** (los más simples: un CCF normal), no un lote grande.
- **Conta Portable como respaldo** disponible: si algo no cuadra, se factura ahí. El sistema
  nuevo no transmite en paralelo, así que volver a Conta **no** genera duplicados ante Hacienda.
- **Revisar Salud del sistema después de cada envío** (`/admin/salud-sistema`): worker activo,
  correos sin fallidos, sin alertas de datos, badge en el modo esperado.
- **Coordinar correlativos, punto de venta y ambiente** antes del primer documento real: no
  transmitir desde dos sistemas sin coordinar (ver `docs/TRANSMISION_DTE.md`).
- **Backup de cierre** al terminar el día (`scripts\backup-run.bat`) y revisar que no queden
  correos fallidos sin reintentar.

> El paso de "modo paralelo" a "emitir real" es un cambio de configuración candado (fuera de
> esta guía operativa); hacerlo **solo** cuando el go de la sección 4 esté confirmado y con la
> coordinación de correlativos/ambiente resuelta.

---

## 6. Registro de preflight ejecutado (2026-07-06)

Ejecución del checklist de arranque (§2), solo lectura salvo las pruebas técnicas anotadas. No
se transmitió nada a Hacienda; el modo se mantuvo **PARALELO SEGURO** en todo momento.

| Chequeo | Resultado |
|---|---|
| Backup reciente | ✅ `.zip` de hoy en `storage\app\private\Dulces La Negrita\` |
| Worker activo | ✅ heartbeat activo (último pulso pocos segundos) |
| 0 jobs fallidos | ✅ pendientes 0 · fallidos 0 |
| Modo PARALELO SEGURO | ✅ paralelo · transmisión real bloqueada |
| APP_DEBUG=false | ✅ |
| **Firmador real local** | ✅ **vivo y firma real** (ver prueba técnica abajo) |
| **SMTP** | ✅ **send-test enviado** al correo de prueba controlado (ver abajo) |
| Conta Portable | confirmación manual del operador |

**Prueba técnica de firmador (real local, sin mock):**
- Health check `GET .../firmardocumento/status` → **HTTP 200** ("Application is running").
- Se generó un **CCF throwaway** (interno **#101**, `DTE-03-M001P001-000000000000051`, cliente
  contribuyente de prueba, 1 línea) **solo** para probar la firma — **no** es del piloto.
- Firma real con el **NIT del emisor** (`DTE_FIRMA_NIT`, desde `.env`) → **`firmo=true`**,
  estado Generado→**Firmado**, **JWS real** (3 partes, sin marcador MOCK), **sin sello**
  (**no transmitido**). Confirma que el firmador tiene la llave del emisor cargada y firma.
- El documento #101 queda como **evidencia técnica** (firmado, no transmitido); no se usa para
  el piloto ni se emite.

**Send-test SMTP:**
- Se encoló el correo del CCF #101 **únicamente** al correo de prueba controlado
  (`melquicedeespana@gmail.com`, **no** al cliente). Registro **DteEnvio #23**.
- Flujo de cola verificado: **jobs 0 → 1 → 0**, **0 fallidos**; `DteEnvio` quedó **enviado**
  (`error=null`) por SMTP (Gmail). Verificado por el operador: **el correo llegó** con PDF+JSON.

### Registro de casos — Día 2 (2026-07-06)

Segunda jornada de comparación contra Conta Portable (modo **PARALELO SEGURO**, sin transmitir).

| Caso | Documento nuevo | Total nuevo | Total Conta | Dif. | Estado |
|------|-----------------|-------------|-------------|------|--------|
| 1 CCF normal (sin retención) | CCF #104 · `DTE-03-…053` | 52.09 | 52.09 | $0.00 | ✅ APROBADO |
| 2 CCF Calleja OC + sala | CCF #105 · `DTE-03-…054` | 122.36 | 122.36 | $0.00 | ✅ APROBADO |

**Caso 1 — detalle:** receptor Villarreal de De la Torre; CANILLITAS ×20 + MANI DULCE ×15 +
DULCE DE MIEL ×10; gravado 46.10, IVA 5.99, retención 0.00, **total 52.09** = Conta **52.09**
(diferencia $0.00). JSON tipoDte 03 v4 válido; PDF preliminar OK; **sin sello** (no transmitido);
jobs 0 / fallidos 0. **✅ APROBADO.**

**Caso 2 — detalle:** Calleja (#10) · sala **Súper Selectos Aguilares** (#199) · OC
**OC-DIA2-0001** (en apéndice); CANILLITAS ×60 + MANI DULCE ×50; gravado bruto 115.00, descuento
5% -5.75, neto 109.25, IVA 14.20, **retención IVA 1% 1.09**, **total 122.36** = Conta **122.36**
(diferencia $0.00). JSON tipoDte 03 v4 válido; PDF con sala/OC/descuento/retención; **sin sello**
(no transmitido); jobs 0 / fallidos 0. **✅ APROBADO.**

---

## 7. Alcance y seguridad

- Este documento **no toca** código, facturación, DTE, firma, transmisión, correo, colas ni
  PDF: es solo un procedimiento operativo para el Día 2 y el arranque.
- Mientras dure la validación, el sistema nuevo opera en **modo paralelo**; **Conta Portable es
  el emisor oficial**.
- Ningún paso requiere ni muestra contraseñas o secretos.

---

> **Resumen:** repetí en un **segundo día** la comparación de los casos de cada familia
> (CCF, Calleja, NC devolución/avería, NC pronto pago, FEX, correo/PDF) con el checklist de
> arranque; si todo queda **✅ (±$0.01)** sin transmisión accidental, hay **go**; arrancá
> producción con **1–2 documentos reales** y Conta Portable como respaldo, revisando Salud del
> sistema tras cada envío.
