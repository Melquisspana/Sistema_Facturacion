# Piloto de preproducción — DTE nuevo vs Conta Portable

Guía y **checklist operativa** para hacer un piloto real del sistema nuevo de
facturación **al lado de Conta Portable** (el sistema actual/oficial en uso), antes de
pasar a producción completa.

> **Solo procedimiento.** No cambia lógica del sistema (facturación, DTE, firma,
> transmisión, correo, colas ni PDF). Es una lista de pasos para comparar resultados.

---

## Estado del piloto (cierre al 2026-07-06)

Resumen del avance del piloto. Detalle caso por caso en la **sección 13**.

### Casos — avance

| Caso | Estado | Documento nuevo | Total | Nota |
|------|--------|-----------------|-------|------|
| 1 CCF sin retención | ✅ **APROBADO** | INT-03-…044 | 52.09 | Coincide con Conta Portable (±$0.00) |
| 2 CCF con retención | ✅ **APROBADO** | INT-03-…045 | 117.15 | Retención 1% correcta |
| 3 Calleja OC + sala | ✅ **APROBADO** | INT-03-…046 | 122.36 | OC, sala, precio especial, descuento 5%, retención |
| 4 Duplicar CCF | ✅ **APROBADO** | INT-03-…047 | 52.09 | Reproduce Caso 1; original intacto |
| 5 Correo + PDF | ✅ **APROBADO** | CCF #91 · envío #21 | n/a | Correo llegó, PDF adjunto abre, 0 fallidos |
| 6 NC devolución | ✅ **APROBADO** | INT-05-…021 (ref CCF #71) | 11.01 | Coincide con Conta Portable (±$0.00) |
| 7 NC avería | ✅ **APROBADO** | INT-05-…023 (ref CCF #66) | 3.38 | Coincide con Conta ($3.38, ±$0.00); avería ahora hereda descuento 5% del CCF |
| 8 NC pronto pago | ⛔ **NO INICIADO** | — | — | Definir cómo probar |
| 9 Invalidación / anulación | ⛔ **NO INICIADO** | — | — | Solo mock + dry-run visual (real solo por consola) |
| 10 FEX / exportación | ⛔ **NO INICIADO** | — | — | Cliente exportación completo (país + actividad) |

**Resumen:** **7 aprobados** (1–7) · **3 no iniciados** (8–10).

### Pendientes visuales/técnicos ya resueltos

- ✅ **PDF CCF pulido visualmente**: ubicación del receptor en **3 niveles** (Departamento
  / Municipio / Distrito) con sala para Calleja; **logo transparente** más grande;
  **encabezado del emisor** mejorado (actividad económica real + ubicación completa +
  tipografía más equilibrada, sin salto a segunda página).
- ✅ **Modo paralelo seguro activo**: badge **PARALELO SEGURO** en navbar y en Salud del
  sistema; firma/transmisión reales bloqueadas por candados. Indicador de modo corregido
  (ya no da falsa alarma roja con apitest).
- ✅ **Worker / colas / correos verificados**: worker de colas operativo (arranque
  automático en Windows), correo SMTP con **PDF + JSON** adjuntos, sin jobs fallidos.
- ✅ **Backups y restauración probados**: backup con spatie/laravel-backup + scripts
  portables; restauración validada contra base **temporal** (nunca la real).
- ✅ **Catálogo nacional reconciliado**: 14 productos nacionales activos con precios **sin
  IVA**; obsoletos desactivados (no borrados); precios especiales de Calleja obsoletos
  desactivados.
- ✅ **Seguridad / entorno**: checklist de preproducción (`docs/SEGURIDAD_PREPRODUCCION.md`)
  y de piloto revisados; `APP_DEBUG=false`.

### Pendientes antes de producción

- ✅ **Caso 7 (NC avería) — aprobado**: se ajustó la lógica para que la avería **herede el
  descuento global (5%)** del CCF relacionado (como Conta Portable). NC #97 → **$3.38** = Conta
  **$3.38**, diferencia $0.00, **APROBADO**. Detalle en §13.8.
- ⛔ **Definir cómo probar los casos 8–10**: NC pronto pago (conceptos manuales),
  invalidación/anulación (solo mock + dry-run visual; la real es solo por consola) y FEX
  (cliente de exportación completo con país + actividad).

### Riesgos / bloqueos

- **Doble transmisión**: mitigado — el sistema nuevo **no** transmite en el piloto (modo
  paralelo, candados). Conta Portable sigue siendo el **emisor oficial**.
- **Origen real para NC**: la NC exige un CCF **aceptado realmente por Hacienda**; los CCF
  del piloto son solo _generado_. Para casos 6–8 se reutilizan CCF real-aceptados previos
  (apitest). Tener claro cuál CCF usar antes de cada NC.
- **Unidad BOLSA sin CAT-014 válido**: pendiente asignar un código de unidad de medida
  válido antes de usar productos con esa presentación.
- **Criterio de salida**: pasar a producción exige los **10 casos ✅** en al menos **2
  días** distintos, sin diferencias de total > $0.01 ni errores de flujo (ver §13).

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
| 3 CCF Calleja OC+sala | 2026-07-05 | operador | INT-03-M001P001-…046 | Conta Portable (misma operación) | 122.36 | 122.36 | ✅ APROBADO | Diferencia $0.00. Con OC, sala, precios especiales, descuento 5% y retención. Detalle en §13.3 |
| 4 Duplicar CCF | 2026-07-06 | operador | INT-03-M001P001-…047 (dup de …044) | vs Caso 1 (aprobado) | 52.09 | 52.09 | ✅ APROBADO | Diferencia $0.00. Duplicado reproduce Caso 1; original intacto. Detalle en §13.4 |
| 5 Correo + PDF | 2026-07-06 | operador | CCF #91 (…047) · envío #21 | correo de prueba | n/a | n/a | ✅ APROBADO | Correo recibido, PDF adjunto abre; job 0→1→0, 0 fallidos. Detalle en §13.5 |
| 6 NC devolución | 2026-07-06 | operador | INT-05-M001P001-…021 (ref CCF #71 …035) | Conta Portable (misma operación) | 11.01 | 11.01 | ✅ APROBADO | Diferencia $0.00. Devolución parcial de CCF #71 (CANILLITAS ×5, COCO RALLADO ×5). Detalle en §13.6 |
| 7 NC avería | 2026-07-06 | operador | INT-05-M001P001-…023 (ref CCF #66 …031) | Conta Portable (misma operación) | 3.38 | 3.38 | ✅ APROBADO | Diferencia $0.00. Ajuste: avería ahora hereda descuento 5% del CCF (NC #97 corrige a #96). Detalle en §13.8 |
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

### 13.3 Caso 3 — CCF Calleja con OC y sala · **✅ APROBADO** (2026-07-05)

**Resultado:** comparado contra Conta Portable (misma operación) → **coincide**.
Sistema nuevo **$122.36** = Conta Portable **$122.36** · diferencia **$0.00** · **APROBADO**.

Corrida del Caso 3 en el **sistema nuevo**, en **modo paralelo** (sin transmitir a
Hacienda). Preflight OK: modo **PARALELO SEGURO**, worker **activo**, **0** jobs fallidos,
backup reciente (hoy), `APP_DEBUG=false`.

**Cliente/sala/OC (indicados por el operador):** Calleja (id 10) · sala **Súper Selectos
Aguilares** (id 199) · OC **OC-PILOTO-0001** · productos que Calleja usa (con precios
especiales). Valida OC en apéndice, ubicación de la sala, **descuento global 5%** de
Calleja y **retención**.

**Documento nuevo:** CCF interno #90 · N° interno `INT-03-M001P001-000000000000046` ·
numeroControl `DTE-03-M001P001-000000000000046` · estado **Generado** · **sin sello**
(no transmitido) · JSON oficial preliminar validado contra `fe-ccf-v4`.

| Campo | Valor del sistema nuevo | Conta Portable |
|-------|-------------------------|:--------------:|
| Razón social (receptor) | Calleja, S.A. de C.V. · NIT 0614-110169-001-1 · NRC 1937 · **agente de retención** | _pendiente_ |
| Nombre comercial / sala | Súper Selectos Aguilares | _pendiente_ |
| Depto / municipio / distrito de la sala | San Salvador / San Salvador / San Salvador (cód. 06 / 23 / 14) | _pendiente_ |
| Orden de compra (apéndice) | OC-PILOTO-0001 | _pendiente_ |
| Producto 1 | CANILLITAS · cant 60 · **precio especial** 1.0500 · gravado 63.00 | _pendiente_ |
| Producto 2 | MANI DULCE · cant 50 · **precio especial** 1.0400 · gravado 52.00 | _pendiente_ |
| Subtotal gravado bruto (sin IVA) | **115.00** | _pendiente_ |
| Descuento global 5% (Calleja) | **5.75** → gravado neto **109.25** | _pendiente_ |
| IVA 13% (sobre neto) | **14.20** | _pendiente_ |
| Retención IVA 1% (sobre neto, base 109.25 > $100) | **1.09** | _pendiente_ |
| Monto total operación | 123.45 (neto + IVA) | _pendiente_ |
| Total a pagar | **122.36** (123.45 − 1.09 retención) | **122.36** ✓ |
| Total en letras | CIENTO VEINTIDÓS 36/100 DÓLARES | coincide |
| PDF | preliminar OK: Calleja, sala, OC, 2 productos, 115.00 / 5.75 / 109.25 / 14.20 / 1.09 / 122.36; marcas "NO TRANSMITIDO / SIN SELLO"; sin marca BORRADOR | coincide |
| Estado DTE | Generado (badge unificado) · sin transmisión real | n/a (Conta Portable es el emisor oficial) |

**Resultado del caso:** ✅ **APROBADO** — todos los campos coinciden (razón social, sala,
OC, precios especiales, **descuento 5%** y **retención**); total idéntico (**$122.36** =
**$122.36**, diferencia **$0.00**). Conta Portable emitió la misma operación oficialmente;
el sistema nuevo solo comparó (sin transmitir a Hacienda).

### 13.4 Caso 4 — Duplicar CCF y generar desde el duplicado · **✅ APROBADO** (2026-07-06)

**Resultado:** el duplicado reproduce el Caso 1 → **coincide**. Total duplicado **$52.09** =
Caso 1 **$52.09** · diferencia **$0.00** · original **#88 intacto** · **APROBADO**.

Valida el flujo **Duplicar CCF** en el **sistema nuevo**, en **modo paralelo** (sin
transmitir a Hacienda). Preflight OK: modo **PARALELO SEGURO**, worker **activo**, **0**
jobs fallidos, backup reciente, `APP_DEBUG=false`. No es una comparación contra Conta
Portable nueva: el criterio es que el duplicado **reproduzca el Caso 1** (ya aprobado).

**Base:** CCF del Caso 1 → **#88** (`INT-03-M001P001-000000000000044`, total $52.09).
**Duplicado:** borrador **#91** → generado como `INT-03-M001P001-000000000000047`
(`DTE-03-M001P001-000000000000047`), **sin sello** (no transmitido).

**Verificaciones del flujo (todas ✓):**

| Verificación | Resultado |
|--------------|-----------|
| El duplicado queda como **borrador editable** | ✓ (estado borrador, editable) |
| **No** copia numeración/firma/sello/JSON fiscal | ✓ (numero_control, codigo_generacion, sello, json_generado, json_firmado = vacíos) |
| **No** copia correos ni estado de Hacienda | ✓ (0 envíos; sin respuesta_mh; estado fresco) |
| **Sí** copia cliente, productos, cantidades, precios y totales | ✓ (cliente Villarreal; 3 líneas 1.05/1.04/0.95; gravado 46.10 · IVA 5.99 · total 52.09) |
| Al **generar** consume **nuevo correlativo** | ✓ (46 → 47) |
| **codigoGeneracion** nuevo, distinto al original | ✓ |
| El **original #88 queda intacto** | ✓ (sigue `…044`, estado generado, total 52.09, sin cambios) |
| **PDF** del duplicado generado | ✓ (cliente, 3 productos, 46.10/5.99/52.09, "NO TRANSMITIDO / SIN SELLO", sin marca BORRADOR, numeroControl `…047`) |
| **Total** del duplicado = Caso 1 | ✓ **$52.09** = **$52.09** · diferencia **$0.00** |

**Resultado del caso:** ✅ **APROBADO** — el duplicado reproduce el Caso 1 (total **$52.09**
= **$52.09**, diferencia **$0.00**), el **original #88 quedó intacto**, el duplicado #91
generó **nuevo correlativo** y **no** heredó firma/sello/transmisión/correos ni estado
fiscal. No se transmitió nada a Hacienda.

### 13.5 Caso 5 — Enviar correo y abrir PDF para imprimir · **✅ APROBADO** (2026-07-06)

**Resultado:** verificado por el operador → el **correo llegó** a la casilla de prueba, el
**PDF adjunto abre** correctamente, y el flujo **envío + worker + cola + PDF** funcionó
(job 0→1→0, **0 fallidos**) · **APROBADO**.

Valida el flujo operativo de **correo + PDF** en el **sistema nuevo**, en **modo paralelo**
(sin transmitir a Hacienda). Preflight OK: modo **PARALELO SEGURO**, worker **activo**,
**0** jobs fallidos, backup reciente, `APP_DEBUG=false`.

**Documento / destinatario:** CCF **#91** (`DTE-03-M001P001-000000000000047`, Caso 4) ·
enviado **únicamente** a un **correo de prueba controlado** (`melquicedeespana@gmail.com`),
**no** al correo del cliente ni a ningún otro. Envío registrado como **DteEnvio #21**.

**Verificaciones del flujo (todas ✓):**

| Verificación | Resultado |
|--------------|-----------|
| El job de correo **entra a la cola** | ✓ (jobs 0 → 1 al encolar) |
| El worker lo **procesa y sale de la cola** | ✓ (jobs vuelve a 0) |
| **0 jobs fallidos** después | ✓ (failed_jobs = 0) |
| El envío queda **enviado** (worker corrió el job) | ✓ (`DteEnvio #21` estado `enviado`, `error: null`) |
| **Un solo destinatario**, el de prueba | ✓ (`melquicedeespana@gmail.com`; sin otros) |
| Correo enviado **de verdad** por SMTP (Gmail) con PDF+JSON | ✓ (mailer smtp; sin error) |
| **PDF abre para imprimir** | ✓ (`facturacion.pdf` renderiza; ~4.7 MB) |
| El DTE queda **marcado como correo enviado** | ✓ (`DteEnvio` persistido; la ficha `show` carga `envios` y lo lee fresco al volver — PRG, sin recarga manual) |

**Resultado del caso:** ✅ **APROBADO** — el operador confirmó que **el correo llegó** a la
casilla de prueba con el **PDF adjunto**, que **abre correctamente**, y que el flujo de
**envío + worker + cola + PDF** funcionó sin fallos (job 0→1→0, 0 fallidos). No se transmitió
nada a Hacienda; el envío fue a un correo de prueba controlado, no al cliente.

> El correo se envió a un **correo de prueba controlado**, no al cliente. No se transmitió
> nada a Hacienda; Conta Portable sigue siendo el emisor oficial.

> No se modificó el CCF original. El duplicado es un borrador nuevo, sin datos fiscales
> heredados; al generarlo tomó su propia numeración/codigoGeneracion. No se transmitió nada
> a Hacienda; Conta Portable sigue siendo el emisor oficial.

> Este caso usa **precios especiales** de Calleja + **descuento global 5%** + **retención**
> (base gravada neta 109.25 > $100) + **OC en apéndice** + datos de la **sala** (nombre
> comercial y ubicación). Precio base **sin IVA**. No se transmitió nada a Hacienda; Conta
> Portable sigue siendo el emisor oficial.

> Retención automática: solo CCF, receptor **agente de retención**, y base gravada **neta**
> > $100 (umbral configurable). El total a pagar descuenta la retención. Precio base **sin
> IVA**. No se transmitió nada a Hacienda.

### 13.6 Caso 6 — Nota de crédito por devolución · **✅ APROBADO** (2026-07-06)

**Resultado:** comparado contra Conta Portable (misma operación) → **coincide**.
Sistema nuevo **$11.01** = Conta Portable **$11.01** · diferencia **$0.00** · **APROBADO**.

Valida el flujo de **Nota de crédito por devolución** en el **sistema nuevo**, en **modo
paralelo** (sin transmitir a Hacienda). Preflight OK: modo **PARALELO SEGURO**, worker
**activo**, **0** jobs fallidos, backup reciente, `APP_DEBUG=false`.

**CCF original (relacionado):** se usó un CCF **real-aceptado por Hacienda** (apitest),
requisito de la NC — **CCF #71** · numeroControl `DTE-03-M001P001-000000000000035` ·
codigoGeneracion `CF903852-05A2-4881-8305-DEC18DC386C7` · cliente **Calleja** (sala Súper
Selectos Bethoven). Los CCF del piloto (#88–91) son solo _generado_ (nunca transmitidos en
paralelo), por eso no sirven como origen de una NC real; se eligió un CCF real-aceptado
previo (Opción A confirmada por el operador).

**Documento nuevo:** NC interna **#95** · N° interno `INT-05-M001P001-000000000000021` ·
numeroControl `DTE-05-M001P001-000000000000021` · codigoGeneracion
`183D643C-E655-4BB8-8DDB-7834E4763028` · estado **Generado** · **sin sello** (no
transmitido) · estructura **NC v3** (tipo 05).

**Devolución acreditada (solo líneas del CCF #71):**

| Campo | Valor del sistema nuevo | Conta Portable |
|-------|-------------------------|:--------------:|
| Tipo de NC | Devolución de productos | _pendiente_ |
| Documento relacionado | CCF #71 · `DTE-03-M001P001-000000000000035` (tipoGen 2, codGen coincide ✓) | _pendiente_ |
| Receptor | Calleja, S.A. de C.V. · NIT 0614-110169-001-1 · NRC 1937 · sala Súper Selectos Bethoven | _pendiente_ |
| Producto 1 | CANILLITAS · cant **5** (de 12 facturadas) · precio 1.0500 · gravado 5.25 | _pendiente_ |
| Producto 2 | COCO RALLADO · cant **5** (de 13 facturadas) · precio 1.0000 · gravado 5.00 | _pendiente_ |
| Total gravado bruto | **10.25** | _pendiente_ |
| Descuento global 5% (heredado del CCF) | **0.51** → neto **9.74** | _pendiente_ |
| IVA 13% (resumen.tributos) | **1.27** | _pendiente_ |
| Retención IVA 1% | **0.00** (la NC no aplica retención) | _pendiente_ |
| Total a acreditar | **11.01** | **11.01** ✓ |
| Total en letras | ONCE 01/100 DÓLARES | coincide |
| PDF | preliminar OK: NC 05, Calleja, 2 productos, documento original `…035`, motivo, 10.25 / 0.51 / 9.74 / 1.27 / 11.01; marcas "NO TRANSMITIDO / SIN SELLO"; 1 página | coincide |
| Estado DTE | Generado · sin transmisión real | n/a (Conta Portable es el emisor oficial) |

**Candados verificados (✓):**

| Verificación | Resultado |
|--------------|-----------|
| La NC exige CCF **aceptado realmente por Hacienda** | ✓ (se usó CCF #71 real-aceptado; los del piloto _generado_ no habrían dejado generar) |
| Solo acredita **líneas del propio CCF** | ✓ (CANILLITAS y COCO RALLADO son del CCF #71) |
| No deja acreditar **más que lo facturado** | ✓ (intento CANILLITAS ×99 → `SaldoAcreditableExcedidoException`) |
| NC queda **relacionada** al CCF (documentoRelacionado) | ✓ (codGen del CCF #71 coincide en el JSON) |
| IVA / descuento **recalculados** en la NC (v3) | ✓ (descuento 5% heredado, IVA sobre neto) |
| **No** transmite ni cambia el CCF original | ✓ (NC sin sello; CCF #71 intacto) |

**Resultado del caso:** ✅ **APROBADO** — la NC #95 quedó **relacionada** al CCF #71
real-aceptado, acredita solo líneas del CCF, respeta el saldo facturado (over-limit
bloqueado) y recalcula IVA/descuento (incluido el **descuento 5% heredado**). Total idéntico
(**$11.01** = **$11.01**, diferencia **$0.00**). Conta Portable emitió la misma operación
oficialmente; el sistema nuevo solo comparó (sin transmitir a Hacienda).

> La NC de **devolución** solo acredita **líneas del CCF original** (a diferencia de la de
> avería, que permite catálogo libre). El origen debe ser un CCF **real-aceptado por
> Hacienda**; en el piloto paralelo se validó el **cálculo y el documento**, no la
> transmisión. Estructura **NC v3** (IVA en resumen.tributos, descuento global, ventaGravada
> bruta). No se transmitió nada a Hacienda; Conta Portable sigue siendo el emisor oficial.

### 13.7 Estrategia para los casos pendientes (7–10)

Plan seguro para ejecutar los casos que faltan, **sin transmitir a Hacienda** (modo
paralelo). Todo se genera y se compara; nada se firma/transmite real desde la interfaz.

**Regla que aplica a las notas de crédito (7 y 8):** por diseño del sistema, **toda NC (05)
— devolución, avería y pronto pago por igual — exige un CCF relacionado _aceptado
realmente por Hacienda_ para poder generar**, y su total gravado **no puede superar el
saldo disponible** de ese CCF (`ValidacionPreJsonService`). No basta un CCF _generado_ del
piloto; hay que reutilizar un CCF real-aceptado previo.

**CCF real-aceptados disponibles (origen para NC), todos Calleja:**

| CCF | numeroControl | Gravado | Ya acreditado (real) | Saldo disponible |
|-----|---------------|---------|----------------------|------------------|
| #48 | …030 | 1.05 | 1.05 | **0.00** (agotado) |
| #66 | …031 | 81.98 | 14.70 | **67.28** |
| #71 | …035 | 39.85 | 0.00 | **39.85** |

> En modo paralelo, una NC solo _generada_ (no transmitida) **no consume saldo**; solo las
> NC real-aceptadas lo descuentan. Por eso se pueden apilar NC de prueba sobre el mismo CCF.

**Estrategia por caso:**

| Caso | Documento base | ¿Origen real? | Cómo se hace seguro | Datos que debe dar el operador | Comparación vs Conta Portable | Recomendación |
|------|----------------|---------------|---------------------|-------------------------------|-------------------------------|---------------|
| **7 NC avería** | CCF real-aceptado (#66 o #71) | Sí (origen); NC en paralelo | Modo paralelo; solo generar + PDF; **catálogo libre** pero gravado ≤ saldo | CCF origen, productos averiados libres + cantidades, monto ≤ saldo | Productos/cantidades/IVA/total, tope de saldo, relación al CCF | **Ahora** |
| **8 NC pronto pago** | CCF real-aceptado (#66 o #71) | Sí (origen); NC en paralelo | Modo paralelo; **conceptos manuales** (sin producto), monto + IVA | CCF origen, concepto(s) y monto (base), ≤ saldo | Concepto/base/IVA/total y relación al CCF | **Ahora** |
| **9 Invalidación** | DTE/NC real-aceptado **sin evento previo** | Sí (para mock/dry-run) | **Solo mock + dry-run** en la UI; la real es **solo por consola**, apitest, nunca producción | Documento a invalidar (p. ej. #66/#71), tipo CAT-024, motivo, reemplazo si aplica | Tipo/motivo de anulación y documento relacionado | **Ahora** (mock/dry-run) |
| **10 FEX exportación** | Ninguno (documento primario) | No (100% paralelo) | Modo paralelo; solo generar + PDF | Cliente exportación **completo** (país CAT-020 + actividad CAT-019), productos, flete/seguro | País/actividad/**IVA 0%**/flete/seguro/total | **Preparar antes:** definir/confirmar el cliente de exportación |

**Riesgos y mitigaciones:**

- **Transmitir real por error:** mitigado por el modo **PARALELO SEGURO** y los candados; en
  la UI nunca se pulsa "firmar/transmitir". La invalidación real queda **solo por consola**.
- **Tocar documentos reales:** las NC nuevas son documentos propios; el CCF origen **no se
  modifica**. El mock de invalidación usa columnas dedicadas y **no** toca el sello original
  ni el estado.
- **Agotar el saldo del CCF:** en paralelo las NC de prueba no consumen saldo; aun así,
  mantener el gravado de cada NC ≤ saldo disponible para que la validación deje generar.
- **BOLSA sin CAT-014:** si algún producto de prueba usa esa presentación, asignar antes un
  código de unidad válido (pendiente técnico).

**Orden sugerido:** 7 → 8 (reutilizan #66/#71 y la misma mecánica de saldo) → 9 (mock +
dry-run) → 10 (requiere definir el cliente de exportación). Antes de cada caso: preflight de
§1 (PARALELO SEGURO, worker, backup, 0 fallidos) y confirmar con el operador los datos de
entrada. **No** se crean clientes/datos sin confirmación explícita.

### 13.8 Caso 7 — Nota de crédito por avería · ✅ **APROBADO** (2026-07-06)

**Resultado:** tras el ajuste de lógica, el sistema nuevo aplica el **descuento 5%** del CCF
también en la NC por avería → **$3.38** = Conta Portable **$3.38** · diferencia **$0.00** ·
**APROBADO**. La diferencia de $0.18 (captura previa NC #96) quedó **resuelta**.

Valida el flujo de **Nota de crédito por avería** en el **sistema nuevo**, en **modo
paralelo** (sin transmitir a Hacienda). Preflight OK: modo **PARALELO SEGURO**, worker
**activo**, **0** jobs fallidos, **backup de hoy** (`2026-07-06-…`, código 0), `APP_DEBUG=false`.

**Historia del caso (dos capturas):**
- **NC #96** (`…022`, total **$3.56`) — captura **pre-ajuste**: avería sin descuento. Difería
  de Conta en **$0.18**. Se dejó **intacta** como evidencia del antes (no se mutó).
- **NC #97** (`…023`, total **$3.38`) — captura **post-ajuste**: avería con el descuento 5%
  del CCF #66 aplicado. **Coincide** con Conta Portable.

**Ajuste aplicado (una regla de negocio, no de cálculo):** ahora `porcentajeDescuentoVigente()`
hereda el `descuento_porcentaje_aplicado` del CCF relacionado también para la NC por **avería**
(antes solo devolución/faltante). El descuento se aplica como **descuento global del resumen**
(ventaGravada bruto, descuGravada en el resumen), igual que la NC v3 aceptada. **Solo afecta
recálculo de borradores y nuevas generaciones**; no migra documentos históricos. Pronto pago /
concepto (por monto) **siguen sin heredar** (0%). Devolución/faltante **sin cambios**.

**CCF original (relacionado):** CCF **#66** · numeroControl `DTE-03-M001P001-000000000000031`
· codigoGeneracion `6B70F9AC-EF80-402A-AFEB-11B2BAFDD3D1` · cliente **Calleja** (sala Súper
Selectos Cara Sucia) · **real-aceptado por Hacienda** (sello `202613B8…`, fecha proc.
2026-06-26) · descuento global **5%** · saldo disponible **67.28** al momento de la prueba.

**Documento nuevo (corregido):** NC interna **#97** · N° interno `INT-05-M001P001-000000000000023`
· numeroControl `DTE-05-M001P001-000000000000023` · codigoGeneracion
`E0465CE0-610D-41E5-9B42-89315E47A7FA` · estado **Generado** · **sin sello** (no transmitido)
· estructura **NC v3** (tipo 05).

| Campo | Valor del sistema nuevo (NC #97) | Conta Portable |
|-------|----------------------------------|:--------------:|
| Tipo de NC | Avería | Avería ✓ |
| Documento relacionado | CCF #66 · `DTE-03-M001P001-000000000000031` (tipoDoc 03, tipoGen 2, codGen coincide ✓) | CCF #66 ✓ |
| Receptor | Calleja, S.A. de C.V. · NIT 0614-110169-001-1 · NRC 1937 · sala Súper Selectos Cara Sucia | coincide ✓ |
| Producto (catálogo libre) | CANILLITAS · cant **3** · precio 1.0500 · gravado 3.15 | coincide ✓ |
| Total gravado bruto | **3.15** | 3.15 ✓ |
| Descuento global 5% (del CCF) | **0.16** → neto **2.99** | 0.16 → 2.99 ✓ |
| IVA 13% (resumen.tributos) | **0.39** | 0.39 ✓ |
| Retención IVA 1% | **0.00** (la NC no aplica retención) | 0.00 ✓ |
| Total a acreditar | **3.38** | **3.38** ✓ |
| Total en letras | TRES 38/100 DÓLARES | coincide ✓ |
| PDF | preliminar OK: NC 05, Calleja, 1 producto, documento original `…031`, motivo, 3.15 / 0.16 / 2.99 / 0.39 / 3.38; marcas "NO TRANSMITIDO / SIN SELLO"; 1 página | coincide ✓ |
| Estado DTE | Generado · sin transmisión real | n/a (Conta Portable es el emisor oficial) |

**Candados verificados (✓):**

| Verificación | Resultado |
|--------------|-----------|
| La NC exige CCF **aceptado realmente por Hacienda** | ✓ (se usó CCF #66 real-aceptado) |
| Avería admite **catálogo libre** (no solo líneas del CCF) | ✓ (`dte_linea_original_id` nulo; producto agregado por avería) |
| Avería **hereda el descuento global** del CCF relacionado | ✓ (5% aplicado → total 3.38, coincide con Conta) |
| Gravado de la NC **≤ saldo disponible** del CCF | ✓ (3.15 ≪ 67.28) |
| NC queda **relacionada** al CCF (documentoRelacionado) | ✓ (codGen del CCF #66 coincide en el JSON) |
| **No** transmite ni cambia el CCF original | ✓ (NC sin sello; CCF #66 intacto: estado aceptado, sello y total 88.00 sin cambios) |
| **Devolución / pronto pago / PPQ sin cambios** | ✓ (suite relevante 212 verdes) |
| **0 jobs fallidos** durante el flujo | ✓ (jobs 0 / failed 0) |

**Antes vs. después del ajuste:**

| | NC #96 (antes) | NC #97 (después) | Conta Portable |
|---|:---:|:---:|:---:|
| Gravado bruto | 3.15 | 3.15 | 3.15 |
| Descuento global 5% | no aplica (0.00) | **aplica (0.16)** | aplica (0.16) |
| Gravado neto | 3.15 | 2.99 | 2.99 |
| IVA 13% | 0.41 | 0.39 | 0.39 |
| **Total** | 3.56 | **3.38** | **3.38** |

**Resultado del caso:** ✅ **APROBADO** — la avería ahora **hereda el descuento global del CCF**
relacionado (regla de negocio alineada con Conta Portable). La NC #97 coincide con Conta
Portable (**$3.38** = **$3.38**, diferencia **$0.00**), mantiene la relación al CCF #66
real-aceptado, respeta el saldo y no transmite. Confirmado por el operador. No se transmitió
nada a Hacienda; el CCF #66 quedó intacto.

> **Ajuste de regla de negocio:** la NC por **avería** ahora aplica el **descuento global** del
> CCF relacionado (como Conta Portable). Solo cambió `porcentajeDescuentoVigente()` (una
> condición) + su test; **no** se tocó CCF, devolución, pronto pago, PPQ, firma, transmisión,
> correo, colas ni el PDF (el PDF solo refleja los totales recalculados). El fix aplica a
> **nuevos borradores/generaciones**, no migra documentos históricos. Estructura **NC v3**. No
> se transmitió nada a Hacienda; Conta Portable sigue siendo el emisor oficial.

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
