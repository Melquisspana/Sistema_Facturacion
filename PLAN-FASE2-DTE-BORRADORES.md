# Fase 2 — Motor de borradores DTE (diseño técnico)
## Sistema de Facturación Electrónica · Dulces La Negrita

> **Alcance de esta fase: SOLO borradores.** Sin conexión a Hacienda, sin JSON
> oficial, sin firma, sin PDF, sin inventario. El objetivo es modelar el
> documento fiscal, calcular sus totales correctamente y dejarlo *listo para
> emitir* en una fase posterior.

---

## 0. Principios rectores

1. **El borrador es lo único editable.** Cualquier estado emitido (generado,
   firmado, enviado, aceptado, rechazado, invalidado) es **inmutable**.
2. **El documento es autónomo respecto a los catálogos.** Cada línea guarda un
   *snapshot* del producto; si luego cambia el producto o el cliente, el
   documento no cambia.
3. **El cálculo es el corazón y el mayor riesgo.** Se aísla en un servicio puro,
   sin base de datos, y se prueba primero.
4. **Preparado, no conectado.** Se dejan columnas y servicios reservados
   (número de control, código de generación, sello, rutas JSON/PDF) en
   `nullable`/interfaz, pero no se implementa Hacienda todavía.
5. **Reutilizar lo construido:** enums (`TipoDte`, `EstadoDte`, `CondicionPago`,
   `TipoImpuesto`, `AmbienteHacienda`), `correlativos`, `clientes`, `productos`,
   `config/dte.php`.

---

## 1. Tablas necesarias

### 1.1 `dtes` — cabecera del documento
```
id
tipo_dte            CHAR(2)   -- enum TipoDte: 01 FC, 03 CCF, 05 NC, 11 FEX
estado              VARCHAR   -- enum EstadoDte (default 'borrador')
ambiente            CHAR(2)   -- enum AmbienteHacienda (00 pruebas)
-- Emisor / punto de emisión
establecimiento_id  FK establecimientos
punto_venta_id      FK puntos_venta (nullable)
correlativo_id      FK correlativos (nullable) -- qué serie le tocará al emitir
-- Receptor
cliente_id          FK clientes (nullable: factura a consumidor final sin cliente)
-- Relación (NC/ND apuntan a su documento original)
dte_relacionado_id  FK dtes (self, nullable)
-- Identificadores MH (NULL hasta la fase de generación)
numero_control      VARCHAR(31) nullable, unique
codigo_generacion   CHAR(36)    nullable, unique   -- UUID, se asigna al generar
sello_recepcion     VARCHAR     nullable           -- lo devuelve Hacienda
-- Datos de la operación
condicion_operacion TINYINT     -- enum CondicionPago: 1 contado, 2 credito, 3 otro
numero_orden_compra VARCHAR     nullable           -- regla 6/7: vive en el documento
fecha_emision       DATE
hora_emision        TIME
observaciones       TEXT nullable
motivo              TEXT nullable                  -- usado por NC
moneda              CHAR(3) default 'USD'
-- Totales (todos DECIMAL(11,2) salvo nota)
total_no_sujeto, total_exento, total_gravado
descuento_no_sujeto, descuento_exento, descuento_gravado
descuento_global, total_descuento
subtotal
iva                 -- IVA 13% (en CCF va aparte; en Factura es informativo)
iva_retenido        -- retención IVA 1% (CCF, cuando aplica)
retencion_renta     -- reservado, normalmente 0
monto_total_operacion
total_pagar
total_letras        VARCHAR nullable
-- FEX
flete, seguro       DECIMAL(11,2) nullable
-- Archivos (reservados; se llenan en fases posteriores)
json_generado_path, json_firmado_path, pdf_path  VARCHAR nullable
fecha_procesamiento_mh DATETIME nullable
-- Trazabilidad
created_by          FK users
generado_by, enviado_by, invalidado_by  FK users nullable  -- reservados
timestamps
softDeletes         -- solo se borran borradores
INDEX(estado), INDEX(tipo_dte, fecha_emision), INDEX(cliente_id)
```

### 1.2 `dte_lineas` — detalle (con snapshot)
```
id
dte_id              FK dtes (cascade)
numero_linea        INT
-- Referencia blanda al producto (no cambia el documento si el producto cambia)
producto_id         FK productos (nullable, nullOnDelete)
-- SNAPSHOT del producto al momento de capturar
codigo              VARCHAR
codigo_barra        VARCHAR nullable
descripcion         VARCHAR
unidad_medida_id    FK unidades_medida (nullable)
unidad_codigo       VARCHAR nullable   -- snapshot CAT-014
unidad_nombre       VARCHAR            -- snapshot
tipo_producto       CHAR(1)            -- snapshot enum TipoProducto
tipo_impuesto       VARCHAR            -- snapshot enum TipoImpuesto (gravado/exento/no_sujeto)
-- Cantidades y precios capturados
cantidad            DECIMAL(11,4)
precio_unitario     DECIMAL(11,6)      -- más decimales para no perder centavos al prorratear IVA
descuento_monto     DECIMAL(11,2) default 0
descuento_porcentaje DECIMAL(5,2) nullable  -- opcional; si se usa, calcula el monto
-- Resultado del cálculo (lo escribe la CalculadoraDte)
venta_no_sujeta     DECIMAL(11,2) default 0
venta_exenta        DECIMAL(11,2) default 0
venta_gravada       DECIMAL(11,2) default 0
iva_linea           DECIMAL(11,2) default 0   -- requerido en Factura (IVA incluido)
total_linea         DECIMAL(11,2) default 0
-- Para NC: de qué línea del documento original proviene
dte_linea_original_id FK dte_lineas (nullable)
timestamps
INDEX(dte_id)
```

### 1.3 `dte_estado_historial` — bitácora de transiciones (solo INSERT)
```
id, dte_id FK, estado_anterior VARCHAR nullable, estado_nuevo VARCHAR,
user_id FK nullable, comentario VARCHAR nullable, created_at
```

### 1.4 Reservadas para fases posteriores (NO se crean ahora)
`dte_transmisiones` (request/response a Hacienda), `dte_invalidaciones`
(eventos de anulación). Se mencionan para que el diseño las contemple, pero
pertenecen a la fase de integración.

---

## 2. Modelos

```
app/Models/
├── Dte.php                 # cabecera; casts de enums; relaciones; guard de inmutabilidad
├── DteLinea.php            # detalle + snapshot
└── DteEstadoHistorial.php  # bitácora

app/DataTransferObjects/    # objetos de cálculo, sin BD
├── LineaCalculo.php        # entrada: tipo_impuesto, cantidad, precio, descuento
├── LineaResultado.php      # salida: gravada/exenta/no_sujeta/iva/total por línea
└── TotalesDte.php          # salida: todos los totales del documento
```

- `Dte` castea: `tipo_dte`→TipoDte, `estado`→EstadoDte, `ambiente`→AmbienteHacienda,
  `condicion_operacion`→CondicionPago, montos→`decimal:2`.
- `Dte` usa `LogsActivity` (auditoría) y `SoftDeletes`.
- Accesores: `$dte->esEditable()` (= estado borrador), `$dte->saldoDisponible()`
  (para NC: total original − notas previas).

---

## 3. Relaciones

```
empresa 1─N establecimiento 1─N punto_venta
establecimiento 1─N correlativo
dtes  N─1 establecimiento / punto_venta / correlativo
dtes  N─1 cliente            (nullable)
dtes  1─N dte_lineas
dtes  1─N dte_estado_historial
dtes  N─1 dtes  (dte_relacionado_id; una NC referencia su CCF original)
dte_lineas N─1 producto      (nullable, snapshot la independiza)
dte_lineas N─1 dte_lineas    (dte_linea_original_id, para NC)
users 1─N dtes (created_by)
```

---

## 4. Estados

Enum `EstadoDte` ya existe:
`borrador → generado → firmado → enviado → aceptado | rechazado`, y desde
aceptado `→ invalidado`.

**En esta fase solo se usa `borrador`.** La máquina de estados y el guard de
inmutabilidad se construyen ahora, pero las transiciones hacia `generado` en
adelante se habilitan en la fase de generación/Hacienda.

```
Reglas de inmutabilidad (defensa en capas):
1. DtePolicy::update/delete → solo si estado == borrador (y rol adecuado).
2. Observer en Dte y DteLinea → al actualizar/eliminar, si el Dte NO está en
   borrador, lanza DocumentoInmutableException.
3. DteStateMachine::transicionar($dte, $nuevoEstado, $user) → única vía para
   cambiar estado; valida la transición y escribe dte_estado_historial.
```

Transiciones válidas (definidas en código, no estados libres):
```
borrador  → generado | (eliminar)
generado  → firmado | rechazado
firmado   → enviado
enviado   → aceptado | rechazado | contingencia
aceptado  → invalidado
rechazado → (fin; se corrige creando documento nuevo)
```

---

## 5. Flujo — crear CCF borrador (tipo 03)

```
1. El usuario (rol facturación/admin) elige tipo CCF y un cliente CONTRIBUYENTE.
   - Validación: el cliente debe tener NIT, NRC y actividad económica.
2. Selecciona establecimiento + punto de venta (define qué correlativo aplica).
   - Se guarda correlativo_id; NO se consume número todavía.
3. Agrega líneas: por cada producto se construye el SNAPSHOT (sección 11).
   - El usuario indica cantidad, precio (editable, default del producto),
     descuento.
4. CalculadoraDte recalcula en vivo (modo "IVA separado"):
   - Por línea: venta_gravada = cantidad*precio − descuento (precio SIN IVA).
   - resumen: total_gravado = Σ gravadas; iva = total_gravado * 0.13.
   - iva_retenido = total_gravado * 0.01 si aplica retención (sección 9).
   - monto_total_operacion = total_gravado + iva (+ exento + no_sujeto).
   - total_pagar = monto_total_operacion − iva_retenido − retencion_renta.
5. Si el cliente tiene requiere_orden_compra = true → numero_orden_compra
   OBLIGATORIO (sección 8); la etiqueta se toma de cliente.etiqueta_orden_compra.
6. condicion_operacion (contado/crédito/otro) obligatoria.
7. Guarda como estado = borrador. Historial registra la creación.
   numero_control y codigo_generacion quedan NULL (se asignan al generar).
```

## 6. Flujo — crear Factura de Exportación borrador (tipo 11, FEX)

```
Igual mecánica de captura, con diferencias de contenido:
1. El cliente debe ser tipo EXPORTACION (país extranjero, sin depto/municipio).
2. CalculadoraDte en modo "exportación":
   - IVA tasa 0%: las ventas van como gravadas a 0 → iva = 0.
   - Campos propios: flete, seguro (se suman al total).
   - moneda USD.
3. No aplica retención de IVA ni orden de compra de CCF.
4. total_pagar = total_gravado(0% IVA) + flete + seguro.
5. Estado borrador; correlativo de tipo 11; numeración MH pendiente.
```

> La Factura normal (tipo 01, consumidor final) usa modo **"IVA incluido"**: el
> precio ya trae IVA; `iva_linea = venta_gravada * 0.13 / 1.13` (informativo) y
> el IVA no se suma aparte. El cliente puede ir nulo (consumidor final).

## 7. Flujo — Nota de Crédito borrador (tipo 05) relacionada

```
1. Se crea DESDE un CCF en estado ACEPTADO (en esta fase, como aún no hay
   "aceptado", se permite relacionar a un CCF existente para diseñar el flujo;
   la restricción "aceptado" se activa cuando exista la emisión real).
2. dte_relacionado_id = id del CCF original.
3. El usuario elige qué líneas del original se acreditan (total/parcial); cada
   dte_linea de la NC guarda dte_linea_original_id.
4. Validación de SALDO: Σ(NC previas + esta NC) ≤ montos del CCF original,
   por línea y por total ($dte->saldoDisponible()).
5. motivo obligatorio (devolución, descuento posterior, error de precio…).
6. Mismos cálculos que CCF (IVA separado). Correlativo de tipo 05.
7. El CCF original NO cambia; su vista mostrará las NC asociadas y el saldo.
```

---

## 8. Orden de compra por cliente (reglas 6/7/8)

- El cliente ya tiene `requiere_orden_compra` (bool) y `etiqueta_orden_compra`.
- **El número NO se guarda en el cliente** (cambia cada factura): se guarda en
  `dtes.numero_orden_compra`.
- Validación condicional: si `tipo_dte == CCF` **y** `cliente.requiere_orden_compra`
  → `numero_orden_compra` requerido.
- La UI muestra el campo con la etiqueta `cliente.etiqueta_orden_compra`
  (por defecto "Orden de compra").
- **Futuro (no ahora):** ese número irá en el bloque **apéndice** del JSON DTE.
  Se deja la columna lista; no se genera JSON.

---

## 9. Retención de IVA (1%)

- Aplica en **CCF** cuando el **receptor es agente de retención** (gran
  contribuyente) y la operación gravada supera el umbral legal (≈ $100).
- Diseño:
  - Agregar a `clientes` un flag `es_agente_retencion` (bool, default false)
    — *pequeña migración en esta fase, es un dato del receptor*.
  - En el documento: `iva_retenido = total_gravado * 0.01` cuando
    `cliente.es_agente_retencion` y `total_gravado ≥ umbral` (umbral en
    `config/dte.php`). Se permite ajuste/anulación manual por el usuario con
    permiso.
  - Solo CCF; Factura y FEX → `iva_retenido = 0`.
- `retencion_renta` se deja reservado (normalmente 0 en este negocio).
- `total_pagar = monto_total_operacion − iva_retenido − retencion_renta`.

---

## 10. Descuentos

- **Por línea:** `descuento_monto` (o `descuento_porcentaje` que calcula el
  monto). Se resta del importe de la línea **antes** de calcular IVA.
- **Global:** `descuento_global` opcional a nivel documento; la Calculadora lo
  prorratea sobre las ventas gravadas (y deja `descuento_gravado/exento/no_sujeto`
  para el resumen, como exige el MH).
- Validación: el descuento de una línea no puede superar su importe; el global
  no puede superar el subtotal.
- Orden de cálculo: `importe_linea = cantidad*precio` → `− descuento_linea`
  → clasificar por tipo_impuesto → aplicar descuento global prorrateado → IVA.

---

## 11. Snapshots de productos

Al agregar una línea desde un producto, se **copian** a `dte_lineas`:
`codigo, codigo_barra, descripcion(nombre), unidad_medida_id + unidad_codigo +
unidad_nombre, tipo_producto, tipo_impuesto, precio_unitario`.

- `producto_id` se guarda como **referencia blanda** (`nullOnDelete`): sirve para
  reportes, pero el documento ya no depende de él.
- El precio del snapshot es editable en el borrador (puede diferir del maestro).
- Una línea puede ser **manual** (sin `producto_id`): el usuario escribe
  descripción, unidad, precio y tipo de impuesto a mano (útil para conceptos no
  catalogados). La Calculadora trata ambas igual.

---

## 12. Uso de correlativos

- El borrador **referencia** el correlativo aplicable
  (`tipo_dte + establecimiento + punto_venta + ambiente`) vía `correlativo_id`.
- **No consume número en el borrador.** La asignación del número real
  (`numero_control` formato `DTE-03-M001P001-000000000000001`) ocurre en la
  **fase de generación**, dentro de una transacción con **bloqueo de fila**
  (`SELECT ... FOR UPDATE`) sobre `correlativos.ultimo_numero`, para que nunca
  haya saltos ni duplicados.
- En el borrador se puede **mostrar de forma informativa** el "próximo número"
  (`correlativo->siguiente_numero`), aclarando que es provisional.
- `CorrelativoService::asignarSiguiente()` se **diseña** ahora (interfaz) pero
  se **implementa** en la fase de generación.

---

## 13. Validaciones (Form Requests)

**Cabecera:**
- `tipo_dte` ∈ {01, 03, 05, 11} habilitados.
- CCF/NC: `cliente_id` requerido y cliente CONTRIBUYENTE con NIT+NRC+actividad.
- FEX: cliente tipo EXPORTACIÓN.
- Factura (01): cliente opcional (consumidor final).
- `establecimiento_id` requerido; `punto_venta_id` debe pertenecer al
  establecimiento; `correlativo_id` debe coincidir con tipo+estab+pv+ambiente.
- `condicion_operacion` requerido (enum).
- `numero_orden_compra` requerido si CCF y `cliente.requiere_orden_compra`.
- NC: `dte_relacionado_id` requerido, debe ser CCF, y `motivo` requerido.

**Líneas:**
- Al menos 1 línea.
- `cantidad > 0`, `precio_unitario ≥ 0`, `descuento ≤ importe de la línea`.
- `tipo_impuesto` ∈ enum; coherencia (un producto exento no genera IVA).
- Para NC: cada línea ligada al original no puede exceder el saldo.

**Inmutabilidad / consistencia:**
- Editar/eliminar solo si `estado == borrador` (Policy + Observer).
- Tras recalcular, los totales guardados deben coincidir con la Calculadora
  (la Calculadora es la única fuente; nunca se confía en montos del cliente HTTP).

---

## 14. Servicios a crear

```
app/Services/Dte/
├── CalculadoraDte.php        # PURO, sin BD. Entrada: líneas + modo (CCF/Factura/
│                             # FEX) + flags (retención, descuento global).
│                             # Salida: LineaResultado[] + TotalesDte. ← se prueba 1º
├── DteBorradorService.php    # Crea/actualiza borrador: valida editable, arma
│                             # snapshots, llama a CalculadoraDte, persiste cabecera
│                             # + líneas + historial en transacción.
├── SnapshotProductoService.php  # Producto → arreglo de snapshot de línea.
├── DteStateMachine.php       # Transiciones válidas + escribe historial.
└── CorrelativoService.php    # INTERFAZ ahora; implementación en fase de generación.

app/Policies/DtePolicy.php    # ver/crear/editar(borrador)/eliminar(borrador) por rol.
app/Enums/                    # (revisar/crear) ModoCalculoDte si conviene.

# RESERVADOS — NO crear todavía:
# DteGeneratorService, DteValidatorService, DteFirmadorService,
# HaciendaApiService, DtePdfService, DteEmailService, eventos de dominio.
```

`CalculadoraDte` es deliberadamente **sin estado y sin BD** para poder cubrirla
con pruebas unitarias exhaustivas (es donde un error cuesta dinero real).

---

## 15. Qué NO implementar todavía

| No ahora | Por qué |
|---|---|
| JSON oficial MH / esquemas / validación contra schema | Es la fase de generación |
| Número de control final + código de generación (UUID) + consumo de correlativo con lock | Solo al generar, no en borrador |
| Firma (firmador MH) | Fase de integración |
| HaciendaApiService / transmisión / sello / contingencia | Fase de integración |
| PDF / QR / representación gráfica | Fase posterior |
| Correo al cliente | Fase posterior |
| Invalidación / anulación (tabla y flujo) | Se diseña, se construye después |
| Nota de Débito (06), Retención (07), Sujeto Excluido (14) | Fuera del alcance inicial |
| Inventario (descuento de stock) | Módulo futuro; solo se respeta `maneja_inventario` |
| Eventos de dominio (DteAceptado, etc.) | Se agregan al llegar inventario/Hacienda |

---

## 16. Plan de construcción por pasos pequeños

> Cada paso termina con verificación y tests; se avanza con "continúa".

**PASO 1 — Esquema de datos**
Migraciones `dtes`, `dte_lineas`, `dte_estado_historial` (con columnas reservadas
nullable). Enum auxiliar `ModoCalculoDte` si se decide. Sin lógica.

**PASO 2 — Modelos + inmutabilidad**
Modelos con casts/relaciones, `SoftDeletes`, `LogsActivity`. `DtePolicy`.
Observer de inmutabilidad + `DteStateMachine` (solo registra historial; sólo
permite operar en borrador). Tests de inmutabilidad.

**PASO 3 — CalculadoraDte (¡primero la matemática!)**
Servicio puro + DTOs. Cubrir con pruebas unitarias: CCF (IVA separado), Factura
(IVA incluido), FEX (0% + flete/seguro), descuentos por línea y global,
retención de IVA, redondeos a 2 decimales. Sin UI, sin BD.

**PASO 4 — Snapshots + DteBorradorService**
`SnapshotProductoService` + servicio que arma el borrador en transacción
(cabecera + líneas + historial) usando la Calculadora. Tests de servicio.

**PASO 5 — Migración menor en clientes**
`es_agente_retencion` (bool) para la retención de IVA. Test.

**PASO 6 — CCF borrador (UI)**
Controller + Form Request + componente Livewire para agregar/quitar líneas con
recálculo en vivo + vistas index/crear/editar/ver. Validación de orden de compra
y retención. Tests feature.

**PASO 7 — Factura (01) borrador**
Reutiliza el PASO 6 en modo "IVA incluido"; cliente opcional. Tests.

**PASO 8 — Factura de exportación (11) borrador**
Modo exportación (0% IVA, flete/seguro, cliente extranjero). Tests.

**PASO 9 — Nota de crédito (05) borrador**
Relación al CCF original + selección de líneas + control de saldo. Tests.

**PASO 10 — Endurecimiento**
Listado unificado de DTE con filtros (tipo/estado/cliente/fecha), permisos finos,
revisión de inmutabilidad de punta a punta, y "próximo número" informativo.

**Cierre de Fase 2:** documentos en estado borrador, con líneas, snapshots,
totales correctos (CCF/Factura/FEX/NC), orden de compra y retención, todo
inmutable fuera de borrador y cubierto por tests — **listo para la Fase 3
(generación, correlativo real, JSON, firma y Hacienda).**

---

## Apéndice — Decisiones que conviene confirmar al iniciar el PASO 1
1. **Modo de cálculo por tipo:** CCF/NC = IVA separado; Factura 01 = IVA incluido;
   FEX = 0%. (Estándar MH El Salvador — confirmar contra la documentación vigente
   al construir la Calculadora.)
2. **Decimales:** montos a 2; precio_unitario a 6 y cantidad a 4 para no perder
   centavos en el prorrateo (los totales finales se redondean a 2).
3. **Umbral de retención de IVA** (≈ $100) configurable en `config/dte.php`.
4. **NC sobre "aceptado":** mientras no exista emisión real, la NC se permitirá
   relacionar a un CCF en borrador/cualquier estado para poder diseñar el flujo;
   la restricción "solo aceptado" se activa en la Fase 3.
