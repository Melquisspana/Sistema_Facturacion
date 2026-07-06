# Piloto de preproducción — DTE nuevo vs Conta Portable

Guía y **checklist operativa** para hacer un piloto real del sistema nuevo de
facturación **al lado de Conta Portable** (el sistema actual/oficial en uso), antes de
pasar a producción completa.

> **Solo procedimiento.** No cambia lógica del sistema (facturación, DTE, firma,
> transmisión, correo, colas ni PDF). Es una lista de pasos para comparar resultados.

---

## 0. Regla de oro del piloto (leer primero)

- **Conta Portable sigue siendo el sistema oficial.** Lo que se factura de verdad
  (correlativo real, emisión ante Hacienda) **se hace en Conta Portable**, como siempre.
- El sistema nuevo corre en **modo paralelo**: genera el documento, calcula totales,
  arma el JSON oficial preliminar y el PDF, firma **localmente (mock)** y hace
  **dry-run**. **No transmite a Hacienda.** La transmisión/invalidación reales quedan
  **solo por consola** y bloqueadas por candados (ver `docs/TRANSMISION_DTE.md` y
  `docs/INVALIDACION_DTE.md`).
- Por eso el piloto es una **comparación de resultados**: se carga la **misma operación**
  en los dos sistemas y se verifica que el nuevo produce **los mismos números y el mismo
  documento** que Conta Portable. **No** se transmite desde dos sistemas a la vez.
- Ante cualquier duda o diferencia que no se explique, **manda Conta Portable** (plan B,
  sección 14).

---

## 1. Antes de empezar el piloto (una sola vez al día)

Revisar en este orden. Si algo falla, resolver antes de facturar en el piloto.

| # | Qué | Cómo | OK si… |
|---|-----|------|--------|
| 1 | **Worker de correos activo** | Abrir `start-queue.bat` (o tarea `queue-worker-auto.bat`) | En **Salud del sistema** (`/admin/salud-sistema`) el worker aparece **activo** |
| 2 | **Backup reciente** | `scripts\backup-run.bat` | Aparece un `.zip` de hoy en `storage\app\private\Dulces La Negrita\` |
| 3 | **Sin jobs fallidos** | Contador en la barra superior / `php artisan queue:failed` | Contador en **0** (o reintentados, ver §14) |
| 4 | **Salud del sistema sin alertas rojas de datos** | `/admin/salud-sistema` | Sin borradores basura ni NC sin tipo; alertas de entorno conocidas (APP_DEBUG, etc.) |
| 5 | **Conta Portable disponible** | Abrir Conta Portable | Se puede emitir normalmente |
| 6 | **Modos seguros** (visible siempre) | Franja superior del navbar (badge **DTE: …**), o `php artisan dte:modo-operacion` | Badge verde **PARALELO SEGURO**: firma/transmisión reales bloqueadas |

> El sistema nuevo **no** debe transmitir en el piloto. El badge del navbar (visible en
> toda pantalla para administrador/facturación) debe decir **PARALELO SEGURO**.
> Colores del badge/franja:
> - **Verde** = paralelo, sin transmisión: ideal para el piloto.
> - **Ámbar** = transmisión a **apitest (pruebas)** habilitada (`DTE_TRANSMISION_TEST_ENABLED`);
>   envía a Hacienda **de pruebas**, NO a producción. No es peligroso, pero para un piloto
>   "limpio" conviene apagarlo (`DTE_TRANSMISION_TEST_ENABLED=false`) y que quede verde.
> - **Rojo** = transmisión real a **PRODUCCIÓN** posible ahora mismo → **parar** y avisar.
>
> El chip **PRUEBAS / MOCK** junto al badge es normal durante el piloto (firma/transmisión
> simuladas). Más detalle en **Salud del sistema → "Transmisión DTE"**.

---

## 2. Qué se compara en cada caso (leyenda común)

Para **todos** los casos, comparar el documento del sistema nuevo contra el de Conta
Portable campo por campo:

| Campo | Debe coincidir |
|-------|----------------|
| **Cliente** | Nombre / razón social, NIT/NRC, tipo (contribuyente/exportación) |
| **Sala / sucursal** | La sala de entrega (cuando aplica, p. ej. Calleja) |
| **Orden de compra (OC)** | Número de OC y que aparezca en el documento cuando es obligatoria |
| **Productos** | Códigos y descripciones, y el **orden** de las líneas |
| **Cantidades** | Cantidad por línea |
| **Precios** | Precio unitario por línea (precio aplicado: sala → cliente → general) |
| **IVA** | IVA por documento (13% CCF/Factura; **0%** exportación; en NC según estructura) |
| **Retención** | Retención 1% **solo** si aplica (CCF, cliente agente, base neta > umbral) |
| **Total** | Total a pagar (permitir diferencia de **± $0.01** por redondeo) |
| **PDF** | Datos, totales y layout del PDF preliminar coinciden con lo emitido |
| **Correo** | El correo sale (worker activo) con **PDF + JSON** adjuntos |
| **Estado DTE** | El estado interno esperado (Borrador → Generado, etc.) y el **badge** correcto |

**Criterio general de cada caso:**
- ✅ **Aprobado**: todos los campos coinciden (totales dentro de ± $0.01) y el flujo
  termina sin error.
- ❌ **Fallido**: cualquier diferencia de cliente/sala/OC/producto/cantidad/precio/IVA/
  retención, diferencia de total **> $0.01**, error al generar, o el correo no sale con
  el worker activo. → registrar (sección 13) y aplicar plan B (sección 14).

> **Tolerancia de centavos:** una diferencia de ±1 centavo por redondeo es aceptable.
> Cualquier diferencia mayor es un fallo a investigar.

---

## 3. Caso 1 — CCF normal (sin retención)

**Objetivo:** un CCF a cliente contribuyente que **no** es agente de retención (o cuya
base neta no supera el umbral), sin retención.

**Datos a revisar antes:** cliente contribuyente válido (NIT/NRC/actividad), productos
con precio, si requiere OC.

**Pasos en el sistema nuevo:**
1. Nuevo CCF → elegir cliente (y sala si aplica).
2. Agregar productos (catálogo o **escáner de código de barras**), poner cantidades.
3. Confirmar totales en pantalla (subtotal, IVA 13%, total). **Sin** línea de retención.
4. **Generar** el CCF (queda en estado **Generado**, número interno `INT-03-…`).
5. (Opcional) Generar JSON oficial preliminar y ver PDF preliminar.

**Comparar contra Conta Portable:** misma factura en Conta Portable → todos los campos de
la leyenda (§2). Verificar especialmente **IVA 13%** y **total**, y que **no** haya
retención en ninguno de los dos.

**Aprobado si:** coinciden todos los campos y el total (± $0.01) **y** ninguno aplica
retención. **Fallido si:** aparece retención donde no debe, o difieren totales.

---

## 4. Caso 2 — CCF con retención (1%)

**Objetivo:** CCF donde **sí** aplica retención de IVA 1% (cliente/sucursal agente de
retención y base gravada **neta** > umbral configurable, hoy $100).

**Datos a revisar antes:** cliente marcado como **agente de retención**; monto que deje
la base **neta** (después de descuento) por encima del umbral.

**Pasos en el sistema nuevo:**
1. Nuevo CCF con ese cliente.
2. Agregar productos hasta superar el umbral de base neta.
3. Confirmar que aparece la **retención 1%** automática (no se pide a mano) y el total
   **después** de retención.
4. Generar.

**Comparar contra Conta Portable:** el monto de **retención** y el **total con
retención** deben coincidir; además cliente/productos/IVA como en la leyenda.

**Aprobado si:** la retención 1% y el total neto coinciden (± $0.01). **Fallido si:** la
retención difiere, falta, o aparece cuando la base no supera el umbral.

---

## 5. Caso 3 — CCF Calleja con OC y sala

**Objetivo:** CCF real de Calleja con **orden de compra obligatoria** y **sala de
entrega**.

**Datos a revisar antes:** cliente **Calleja** (activo), **sala** correcta, **número de
OC** de la operación; que la regla de OC exija la orden para ese cliente/sala.

**Pasos en el sistema nuevo:**
1. Nuevo CCF → Calleja → elegir la **sala**.
2. Cargar el **número de OC** (el sistema lo exige; sin OC no deja generar).
3. Agregar productos (precio especial Calleja) y cantidades.
4. Confirmar que la **sala** y la **OC** aparecen en el documento/apéndice.
5. Generar.

**Comparar contra Conta Portable:** además de la leyenda, verificar **sala**, **OC** y el
**precio especial Calleja** por línea, y el orden de las líneas.

**Aprobado si:** cliente + sala + OC + precios especiales + total coinciden. **Fallido
si:** falta la OC/sala, o los precios especiales difieren de Conta Portable.

---

## 6. Caso 4 — Duplicar CCF y generar desde el duplicado

**Objetivo:** validar la función **Duplicar CCF** y que el duplicado genere igual que uno
hecho a mano.

**Datos a revisar antes:** un CCF existente (idealmente el del Caso 3) para duplicar.

**Pasos en el sistema nuevo:**
1. Abrir un CCF → **Duplicar**.
2. Revisar que el **borrador nuevo** copió cliente, sala, OC (según la regla), líneas,
   cantidades y precios (snapshot), y que quedó **en borrador** (número nuevo).
3. Ajustar si hace falta y **Generar**.

**Comparar contra Conta Portable:** el documento resultante debe dar los **mismos totales
e IVA** que la operación equivalente en Conta Portable.

**Aprobado si:** el duplicado conserva los datos correctos y genera con los mismos
números que Conta Portable. **Fallido si:** el duplicado pierde/duplica líneas, cambia
precios o arrastra numeración indebida.

---

## 7. Caso 5 — Enviar correo y abrir PDF para imprimir

**Objetivo:** validar el envío de correo (encolado) + apertura del PDF para imprimir.

**Datos a revisar antes:** **worker activo** (§1.1); cliente con **correo** válido.

**Pasos en el sistema nuevo:**
1. Sobre un CCF generado, pulsar **"Enviar correo y abrir PDF"**.
2. El PDF se abre **al instante** (no espera al SMTP). Imprimir desde ahí si se necesita.
3. El correo queda **encolado**; con el worker activo sale en segundo plano con
   **PDF + JSON** adjuntos.
4. Confirmar en la ficha que el envío pasa a **enviado** (o **fallido** con el error).

**Comparar contra Conta Portable:** el PDF preliminar debe mostrar los mismos datos y
totales que el documento de Conta Portable; el cliente recibe un correo con los adjuntos.

**Aprobado si:** el PDF abre correcto y el correo sale (estado enviado) con adjuntos.
**Fallido si:** el correo queda fallido con el worker activo, o el PDF difiere de Conta
Portable. → revisar worker/fallidos (§14).

> Si el worker está **apagado**, el correo **queda en cola** (no se pierde) y sale al
> encenderlo. Eso no es un fallo del documento.

---

## 8. Caso 6 — Nota de crédito por **devolución**

**Objetivo:** NC (05) por devolución de productos / faltante, que **acredita líneas** del
CCF original.

**Datos a revisar antes:** el **CCF original** relacionado y las líneas/cantidades a
devolver. (Para emisión real, la NC exige un CCF **aceptado por Hacienda**; en el piloto
paralelo se valida el **cálculo y el documento**, no la transmisión.)

**Pasos en el sistema nuevo:**
1. Desde el CCF → crear **Nota de crédito** → tipo **Devolución/Faltante**.
2. **Acreditar** las líneas del CCF (cantidades a devolver).
3. Confirmar que el total de la NC **no supera** el saldo del CCF y el IVA cuadra.
4. Generar.

**Comparar contra Conta Portable:** líneas acreditadas, cantidades, IVA y total de la NC,
y que quede **relacionada** al CCF original (número del CCF).

**Aprobado si:** líneas/cantidades/IVA/total y la relación al CCF coinciden. **Fallido
si:** la NC supera el saldo, difiere el total, o no queda relacionada al CCF correcto.

---

## 9. Caso 7 — Nota de crédito por **avería**

**Objetivo:** NC (05) por avería, que permite **cualquier producto activo** del catálogo
(no se limita a las líneas del CCF).

**Datos a revisar antes:** productos averiados y sus cantidades; el CCF relacionado y su
**saldo gravado disponible**.

**Pasos en el sistema nuevo:**
1. Desde el CCF → **Nota de crédito** → tipo **Avería**.
2. Agregar los productos averiados (catálogo libre) con sus cantidades.
3. Confirmar que el total gravado **no supera** el saldo disponible del CCF.
4. Generar.

**Comparar contra Conta Portable:** productos, cantidades, IVA y total; el tope de saldo
respecto al CCF.

**Aprobado si:** productos/cantidades/IVA/total coinciden y respeta el saldo del CCF.
**Fallido si:** deja pasar más que el saldo, o difiere el total.

---

## 10. Caso 8 — Nota de crédito por **pronto pago**

**Objetivo:** NC (05) por pronto pago / descuento posterior / ajuste, con **conceptos
manuales** (no acredita líneas del CCF).

**Datos a revisar antes:** el concepto y monto del pronto pago/descuento acordado; el CCF
relacionado.

**Pasos en el sistema nuevo:**
1. Desde el CCF → **Nota de crédito** → tipo **Pronto pago / Descuento posterior**.
2. Cargar el/los **conceptos manuales** con su monto.
3. Confirmar IVA y total de la NC.
4. Generar.

**Comparar contra Conta Portable:** concepto, base, IVA y total de la NC, y la relación al
CCF.

**Aprobado si:** concepto/base/IVA/total y la relación coinciden. **Fallido si:** difiere
el monto/IVA o la relación al CCF.

---

## 11. Caso 9 — Anulación / invalidación

**Objetivo:** validar el flujo de **invalidación** en el sistema nuevo.

> ⚠️ **Importante:** en la interfaz, la invalidación es **solo mock + dry-run visual**.
> La invalidación **real** ante Hacienda se hace **solo por consola** y bloqueada por
> candados (ver `docs/INVALIDACION_DTE.md`). En Conta Portable la anulación se hace como
> siempre. En el piloto se compara el **evento/datos**, no una transmisión real.

**Datos a revisar antes:** el documento a invalidar (tipo de anulación, motivo, y
documento de reemplazo si el tipo lo exige).

**Pasos en el sistema nuevo:**
1. En la ficha del documento → sección **Invalidación**.
2. Elegir **tipo de anulación** (CAT-024) y motivo/reemplazo según corresponda.
3. Ejecutar **Dry-run visual** → revisar candados, endpoint (apitest), y el evento
   serializado.
4. (Opcional) **Firmar invalidación (MOCK)** → queda la evidencia (sello MOCK), **sin
   transmitir**, **sin cambiar el estado** y **sin tocar el sello original**.

**Comparar contra Conta Portable:** que el motivo/tipo de anulación y el documento
relacionado coincidan con la anulación real hecha en Conta Portable.

**Aprobado si:** el dry-run/mock refleja los datos correctos y **no** transmite ni altera
el documento original. **Fallido si:** el evento tiene datos incorrectos, o intenta
transmitir/cambiar estado (no debe).

---

## 12. Caso 10 — FEX / exportación con cliente completo

**Objetivo:** Factura de exportación (11), IVA **0%**, con cliente extranjero **completo**
(país CAT-020 + **actividad económica**).

**Datos a revisar antes:** cliente de exportación con **país** válido y **actividad
económica** (si falta cualquiera, el sistema lo bloquea al guardar y antes de generar —
ver `validación temprana FEX`); flete/seguro si aplican.

**Pasos en el sistema nuevo:**
1. Nueva **Factura de exportación** → cliente de exportación.
2. Agregar productos; cargar **flete/seguro** si aplica.
3. Confirmar **IVA 0%** y el total (con flete/seguro).
4. Generar (el JSON usa `codPais`/`nombrePais` correctos de CAT-020).

**Comparar contra Conta Portable:** país del receptor, actividad, IVA 0%, flete/seguro y
total de exportación.

**Aprobado si:** país/actividad/IVA 0%/flete/seguro/total coinciden. **Fallido si:** el
país o la actividad quedan vacíos, el IVA no es 0%, o difiere el total.

---

## 13. Registro de resultados del piloto

Llenar una fila por cada caso probado (podés copiar esta tabla a una planilla):

| Caso | Fecha | Operador | Documento nuevo (N°) | Documento Conta Portable | Total nuevo | Total Conta | ✅/❌ | Notas / diferencia |
|------|-------|----------|----------------------|--------------------------|-------------|-------------|-------|--------------------|
| 1 CCF sin retención | 2026-07-05 | operador | INT-03-M001P001-…044 | Conta Portable (misma operación) | 52.09 | 52.09 | ✅ APROBADO | Diferencia $0.00. Detalle en §13.1 |
| 2 CCF con retención | 2026-07-05 | operador | INT-03-M001P001-…045 | Conta Portable (misma operación) | 117.15 | 117.15 | ✅ APROBADO | Diferencia $0.00. Detalle en §13.2 |
| 3 CCF Calleja OC+sala | | | | | | | | |
| 4 Duplicar CCF | | | | | | | | |
| 5 Correo + PDF | | | | | | | | |
| 6 NC devolución | | | | | | | | |
| 7 NC avería | | | | | | | | |
| 8 NC pronto pago | | | | | | | | |
| 9 Invalidación | | | | | | | | |
| 10 FEX exportación | | | | | | | | |

**Criterio para "pasar a producción":** los 10 casos en ✅ en al menos **2 días
distintos** de operación real, sin diferencias de total > $0.01 ni errores de flujo.

### 13.1 Caso 1 — CCF sin retención · **✅ APROBADO** (2026-07-05)

**Resultado:** comparado contra Conta Portable (misma operación) → **coincide**.
Sistema nuevo **$52.09** = Conta Portable **$52.09** · diferencia **$0.00** · **APROBADO**.

Primera corrida del Caso 1 en el **sistema nuevo**, en **modo paralelo** (sin transmitir a
Hacienda). Preflight OK: modo **PARALELO SEGURO**, worker **activo**, **0** jobs fallidos,
backup reciente, `APP_DEBUG=false`.

**Documento nuevo:** CCF interno #88 · N° interno `INT-03-M001P001-000000000000044` ·
numeroControl `DTE-03-M001P001-000000000000044` · estado **Generado** · **sin sello**
(no transmitido) · JSON oficial preliminar validado contra `fe-ccf-v4`.

| Campo | Valor del sistema nuevo | Conta Portable |
|-------|-------------------------|:--------------:|
| Cliente (receptor) | Villarreal de De la Torre · NIT 0614-010101-101-1 · NRC 123456-7 | _pendiente_ |
| Sala / sucursal | (sin sala; cliente directo) | _pendiente_ |
| Orden de compra | no requiere | _pendiente_ |
| Producto 1 | CANILLITAS · cant 20 · precio **sin IVA** 1.0500 · gravado 21.00 | _pendiente_ |
| Producto 2 | MANI DULCE · cant 15 · precio **sin IVA** 1.0400 · gravado 15.60 | _pendiente_ |
| Producto 3 | DULCE DE MIEL · cant 10 · precio **sin IVA** 0.9500 · gravado 9.50 | _pendiente_ |
| Total gravado (sin IVA) | **46.10** | _pendiente_ |
| IVA 13% (resumen.tributos) | **5.99** | _pendiente_ |
| Retención IVA 1% | **0.00** (cliente no es agente de retención) | _pendiente_ |
| Total a pagar | **52.09** | **52.09** ✓ |
| Total en letras | CINCUENTA Y DOS 09/100 DÓLARES | coincide |
| PDF | preliminar OK: cliente, 3 productos, totales 46.10 / 5.99 / 52.09; marcas "NO TRANSMITIDO / SIN SELLO"; sin marca BORRADOR (está generado) | coincide |
| Estado DTE | Generado (badge unificado) · sin transmisión real | n/a (Conta Portable es el emisor oficial) |

**Resultado del caso:** ✅ **APROBADO** — todos los campos coinciden; total idéntico
(**$52.09** = **$52.09**, diferencia **$0.00**). Conta Portable emitió la misma operación
oficialmente; el sistema nuevo solo comparó (sin transmitir a Hacienda).

> El precio base es **SIN IVA** (así lo usa el CCF: el IVA se calcula aparte). No se
> transmitió nada a Hacienda; Conta Portable sigue siendo el emisor oficial.

### 13.2 Caso 2 — CCF con retención · **✅ APROBADO** (2026-07-05)

**Resultado:** comparado contra Conta Portable (misma operación) → **coincide**.
Sistema nuevo **$117.15** = Conta Portable **$117.15** · diferencia **$0.00** · **APROBADO**.

Corrida del Caso 2 en el **sistema nuevo**, en **modo paralelo** (sin transmitir a
Hacienda). Preflight OK: modo **PARALELO SEGURO**, worker **activo**, **0** jobs fallidos,
backup reciente (hoy), `APP_DEBUG=false`.

**Cliente usado:** dedicado del piloto, creado para este caso (contribuyente **agente de
retención**, sin orden de compra, sin precios especiales) — se usó porque el único agente de
retención existente era Calleja (complejo, es el del Caso 3).

**Documento nuevo:** CCF interno #89 · N° interno `INT-03-M001P001-000000000000045` ·
numeroControl `DTE-03-M001P001-000000000000045` · estado **Generado** · **sin sello**
(no transmitido) · JSON oficial preliminar validado contra `fe-ccf-v4`.

| Campo | Valor del sistema nuevo | Conta Portable |
|-------|-------------------------|:--------------:|
| Cliente (receptor) | Distribuidora Mayorista Piloto, S.A. de C.V. · NIT 0614-200520-103-8 · NRC 234567-8 · **agente de retención** | _pendiente_ |
| Sala / sucursal | (sin sala; cliente directo) | _pendiente_ |
| Orden de compra | no requiere | _pendiente_ |
| Producto 1 | CANILLITAS · cant 60 · precio **sin IVA** 1.0500 · gravado 63.00 | _pendiente_ |
| Producto 2 | MANI DULCE · cant 40 · precio **sin IVA** 1.0400 · gravado 41.60 | _pendiente_ |
| Subtotal gravado (sin IVA) | **104.60** | _pendiente_ |
| IVA 13% (resumen.tributos) | **13.60** | _pendiente_ |
| Retención IVA 1% (resumen.ivaRete) | **1.05** (base gravada neta 104.60 > umbral $100) | _pendiente_ |
| Monto total operación | 118.20 (gravado + IVA) | _pendiente_ |
| Total a pagar | **117.15** (118.20 − 1.05 retención) | **117.15** ✓ |
| Total en letras | CIENTO DIECISIETE 15/100 DÓLARES | coincide |
| PDF | preliminar OK: cliente, 2 productos, totales 104.60 / 13.60 / 1.05 / 117.15; marcas "NO TRANSMITIDO / SIN SELLO"; sin marca BORRADOR | coincide |
| Estado DTE | Generado (badge unificado) · sin transmisión real | n/a (Conta Portable es el emisor oficial) |

**Resultado del caso:** ✅ **APROBADO** — todos los campos coinciden, incluida la
**retención**; total idéntico (**$117.15** = **$117.15**, diferencia **$0.00**). Conta
Portable emitió la misma operación oficialmente; el sistema nuevo solo comparó (sin
transmitir a Hacienda).

> Retención automática: solo CCF, receptor **agente de retención**, y base gravada **neta**
> > $100 (umbral configurable). El total a pagar descuenta la retención. Precio base **sin
> IVA**. No se transmitió nada a Hacienda.

---

## 14. Plan B — volver a Conta Portable

### 14.1 Cuándo volver a Conta Portable (de inmediato)
- Cualquier **total** difiere de Conta Portable en **más de $0.01** sin explicación.
- El sistema nuevo **no genera** (error de validación/schema al generar).
- El **worker** no levanta o los correos no salen y hay que facturar ya.
- Dudas sobre cliente/sala/OC/producto/precio que no se resuelven en el momento.
- **Recordatorio:** Conta Portable es el sistema oficial; ante la duda, **factura ahí**.
  El sistema nuevo **no** transmite, así que volver a Conta Portable **no** genera
  documentos duplicados ante Hacienda.

### 14.2 Qué capturar si algo falla (para diagnosticar después)
- **Captura de pantalla** del documento en el sistema nuevo (con totales) **y** del
  equivalente en Conta Portable.
- **Número interno** del documento nuevo (`INT-…`) y número del documento de Conta Portable.
- El **mensaje de error** exacto (si lo hubo) al generar/enviar.
- Datos de la operación: cliente, sala, OC, líneas (producto, cantidad, precio).
- Si es de correo: estado del envío (enviado/fallido) y el error mostrado.

### 14.3 Cómo revisar el sistema nuevo cuando algo falla
- **Salud del sistema** (`/admin/salud-sistema`, solo admin): estado del **worker**
  (activo/inactivo), correos **pendientes/fallidos**, y alertas de datos/entorno.
- **Worker de colas:** si aparece **inactivo**, abrir `start-queue.bat` (o la tarea
  `queue-worker-auto.bat`). Tras actualizar código: `restart-queue.bat`.
  (Detalle en `docs/COLA_CORREOS.md`.)
- **Jobs fallidos:** contador en la barra superior; en terminal
  `php artisan queue:failed`. Reintentar con `php artisan queue:retry all`. Desde la
  ficha del documento se puede **Reenviar** un correo fallido.
- **Backups:** confirmar que hay un `.zip` reciente en
  `storage\app\private\Dulces La Negrita\`; si no, correr `scripts\backup-run.bat`.
  Para validar que un backup restaura: `scripts\backup-restore-test.bat` (usa una base
  **temporal**, nunca la real — ver `docs/RESTORE_BACKUP_WINDOWS.md`).

---

## 15. Checklist diaria (imprimible)

Marcar al **empezar** el día del piloto:

- [ ] **Worker activo** — Salud del sistema muestra el worker en verde/activo.
- [ ] **Backup reciente** — hay un `.zip` de hoy en `storage\app\private\Dulces La Negrita\`.
- [ ] **Sin jobs fallidos** — contador de la barra en 0 (o reintentados).
- [ ] **Salud del sistema** — sin alertas rojas de datos (las de entorno son conocidas).
- [ ] **Firma/transmisión** — badge del navbar en verde **PARALELO SEGURO** (o
      `dte:modo-operacion`); el sistema nuevo **no** transmite. Si el badge aparece
      **rojo**, parar de inmediato. (La firma/transmisión reales, cuando apliquen, van
      solo por consola y con sus candados.)
- [ ] **Conta Portable disponible** — es el sistema oficial durante el piloto.

Al **terminar** el día:

- [ ] Registrar los casos probados en la tabla de la sección 13.
- [ ] Correr un **backup** de cierre (`scripts\backup-run.bat`).
- [ ] Revisar que no quedaron **correos fallidos** sin reintentar.

---

## 16. Alcance y seguridad

- Este documento **no toca** código, facturación, DTE, firma, transmisión, correo, colas
  ni PDF: es solo un procedimiento operativo.
- El sistema nuevo opera en **modo paralelo**; **no** transmite ni invalida ante Hacienda
  desde la interfaz. La transmisión/invalidación reales son **solo por consola**, con
  candados (ver `docs/TRANSMISION_DTE.md`, `docs/INVALIDACION_DTE.md`).
- **No** se debe transmitir desde dos sistemas sin coordinar correlativos, punto de venta
  y ambiente. Mientras dure el piloto, **Conta Portable es el emisor oficial**.
- Ningún paso de esta guía requiere ni muestra contraseñas o secretos.

---

> **Resumen:** cargá la misma operación en los dos sistemas, compará campo por campo con
> la leyenda de la sección 2, registrá ✅/❌, y ante cualquier diferencia no explicada
> **quedate con Conta Portable**. El objetivo del piloto es demostrar que el sistema nuevo
> produce **exactamente los mismos números y documentos** antes de darle el rol principal.
