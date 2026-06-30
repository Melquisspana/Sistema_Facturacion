# Estado de la fase: Facturación (DTE)

**Fecha:** 2026-06-16
**Estado:** ✅ Estable — fase marcada como cerrada para su alcance actual.

> Documento informativo. No describe integración oficial con el Ministerio de
> Hacienda (MH): el sistema produce documentos **internos/preliminares**, no DTE
> válidos ante el MH todavía.

---

## 1. Qué quedó terminado

- **CCF (03)**: creación con cliente contribuyente y sala/sucursal, catálogo de
  productos disponibles con precio aplicado (sala → cliente → general), generación
  (consume correlativo interno, asigna número interno `INT-03-…`) e inmutabilidad
  posterior.
- **Factura consumidor final (01)**: creación, IVA incluido en el precio, sin
  retención.
- **Factura de exportación (11)**: creación, IVA 0%, flete y seguro.
- **Nota de crédito (05)** con modalidades:
  - Devolución de productos / Faltante de entrega → acreditan líneas del CCF original.
  - Avería → permite cualquier producto activo del catálogo (no se limita al CCF).
  - Pronto pago / Descuento posterior / Ajuste comercial / Otro → conceptos manuales.
  - Creación desde un CCF generado (pregunta el tipo, no asume devolución) o como
    documento independiente.
- **Descuento global del cliente/sucursal**: interpretado como **porcentaje** (0–100),
  no monto fijo. Prioridad sala → cliente → 0. Se convierte a monto sobre el subtotal
  y se prorratea; el porcentaje aplicado se congela en el documento.
- **Retención de IVA (1%)**: automática, solo CCF, solo si cliente/sucursal es agente
  de retención y la base gravada **neta** (después del descuento) supera el umbral
  configurable ($100). No se pide manualmente.
- **Anulación / invalidación interna**: transición generado → invalidado, con motivo
  y observación; las NC anuladas no cuentan para el saldo acreditable.
- **Listado de facturación**: filtros (texto, tipo, estado, cliente, fechas) + chips
  rápidos; columnas Número, Relacionado (NC → su CCF original, nunca a sí misma),
  Cliente + sala, etc.
- **Totales unificados**: partial único `resources/views/facturacion/partials/totales.blade.php`
  reutilizado en edit, show y edit-nc (3 bloques: Ventas · Descuentos e impuestos ·
  Total final). Solo presentación, no recalcula.
- **Impresión preliminar interna** (no es DTE válido ante el MH).
- **Productos reales de Calleja** cargados por código de barra con precio especial
  Calleja (seeder idempotente).

---

## 2. Flujos probados (manual + pruebas de feature)

- [x] Crear CCF de Calleja con sala.
- [x] Agregar productos desde el catálogo.
- [x] Confirmar descuento 5% (porcentaje, no $5).
- [x] Confirmar retención solo si la base gravada neta pasa de $100.
- [x] Generar CCF.
- [x] Verificar que el CCF generado ya no se puede editar.
- [x] Crear nota de crédito desde el CCF (selector de tipo, sin asumir devolución).
- [x] Probar devolución (acredita líneas del CCF original).
- [x] Probar avería (catálogo de productos libres).
- [x] Probar pronto pago (conceptos manuales).
- [x] Anular documento.
- [x] Revisar impresión preliminar.
- [x] Filtrar listado por CCF / NC / generados / borradores.

---

## 3. Tests pasando

- **Suite completa: 488 passed (1473 assertions).**
- Suites que cubren los flujos anteriores: 126 passed (384 assertions).
- Comandos de verificación:
  ```
  php artisan test
  npm.cmd run build
  php artisan view:clear
  php artisan cache:clear
  ```
- Nota de entorno: NO usar `npm run dev` (rompe estilos). Usar `npm.cmd run build`
  + `view:clear` + `cache:clear`.

---

## 4. Backup creado

- Carpeta: `C:\laragon\backups\Facturacion_20260616_181211\` (686 archivos, ~3.8 MB,
  sin `vendor`/`node_modules`).
- Zip: `C:\laragon\backups\Facturacion_20260616_181211.zip` (~1.1 MB).

---

## 5. Qué NO se ha tocado todavía (fuera de alcance de esta fase)

- **JSON oficial del MH** (estructura/esquemas oficiales).
- **Conexión con Hacienda** (ambientes, autenticación, recepción).
- **Firma electrónica** del DTE.
- **Transmisión / envío** al MH y manejo de sello de recepción real.
- **PDF definitivo** (la impresión actual es preliminar/interna).
- **Inventario** (los movimientos por avería/devolución no afectan stock).
- **Prontos pagos automáticos** (los conceptos de pronto pago son manuales).

---

## 5.b Limpieza de datos realizada (2026-06-16)

Auditoría de datos reales + limpieza **segura** (solo datos; no se tocó código,
vistas, migraciones, seeders ni reglas de facturación):

- **3 filas basura de importación soft-deleted** (no tenían DTE asociado): salas
  ` NOMBRE`, `LISTADO DE BODEGAS CON SU RESPECTIVA DIRECCION` y `CALLEJA, S.A. DE C.V.`
  (eran cabeceras/títulos de planilla colados como salas de Calleja).
- **28 salas normalizadas** (colapso de espacios dobles + trim de extremos), p. ej.
  `Súper  Selectos Gigante` → `Súper Selectos Gigante`.
- **0 duplicados reales** tras normalizar (mismo cliente + nombre normalizado): no
  se fusionó ni soft-deleteó ninguna sala por duplicado.
- **137 salas activas de Calleja** (más 3 soft-deleted). Todas las activas mantienen
  `permite_ccf=true` y `permite_nota_credito=true` (decisión de negocio: sin
  restricciones por sala y sin regla especial de "Oficina Central" por ahora).
- Tests tras la limpieza: **488 passed (1473 assertions)**.

Pendientes registrados (no tocados):
- Cliente **Calleja soft-deleted #8** (mismo documento que el activo #10): borrado
  delicado, queda pendiente (ver paso 6).
- **Política de precio general vs precio especial Calleja**: hoy el precio general
  coincide con el precio especial Calleja en los productos cargados. A **revisar por
  negocio** (no se modificaron precios).

---

## 5.c Backups y restauración validados (2026-06-16)

Preparación operativa de copias de seguridad (solo scripts/documentación; no se
tocó código funcional, facturación ni cálculos):

- **Backup real creado correctamente** con `spatie/laravel-backup` (`backup:run`):
  ZIP con dump de la base + archivos de `storage/app`, en
  `storage\app\private\Dulces La Negrita\`.
- **Restauración probada en base temporal** `dulces_negrita_restore_test`
  (nunca sobre la base real):
  - **31 tablas** detectadas.
  - Conteos verificados: **clientes 9, productos 23, dtes 25, users 1**.
  - **Base temporal eliminada** al finalizar la prueba.
  - **Base real `dulces_negrita` intacta** (verificado por separado).
- **Scripts creados** (en `scripts\`): `backup-run.bat`, `backup-clean.bat`,
  `backup-restore-test.bat`.
- **Documentación creada**: `docs\BACKUPS_WINDOWS.md` y `docs\RESTORE_BACKUP_WINDOWS.md`.
- Auditoría de datos (precios, salas, importaciones CSV) ahora registrada con
  activity log; visible en la pantalla de Auditoría.
- Tests: **496 passed (1504 assertions)**.

Nota operativa: la tarea programada de Windows (Programador de tareas) **no** se creó
automáticamente; los scripts y la guía quedan listos para programarla cuando se decida.

---

## 5.d Auditoría de usuarios y accesos (2026-06-17)

Revisión de usuarios para preparar accesos reales (solo lectura + confirmación de
protecciones; no se tocó código):

- **Auditoría de usuarios realizada.** Hoy existe **un solo usuario**:
  `admin@dulceslanegrita.test` (rol administrador, activo) — el **admin temporal**
  sembrado por `UsuarioAdminInicialSeeder`. Es el único administrador activo y usa
  un correo de prueba (`.test`) con contraseña por defecto.
- **Protecciones confirmadas (ya existían y tienen test verde)** para no quedar sin
  administrador:
  - No se puede **inactivar** al último administrador activo.
  - No se puede **quitar el rol** de administrador al último.
  - No se puede **eliminar** al último administrador activo.
  - No te podés **eliminar a vos mismo**.
  - Con **dos** administradores sí se puede inactivar/eliminar uno.
  - Usuario **inactivo no inicia sesión**; contraseña débil rechazada; registro
    público deshabilitado; solo administrador gestiona usuarios.
  - Acciones de usuario quedan **auditadas** (crear, cambiar rol, cambiar contraseña).
- No se implementó código nuevo: ninguna protección faltaba.
- Tests: **496 passed (1504 assertions)**.

**Pendiente operativo (lo hace el operador desde la interfaz):**
1. Crear el **usuario administrador real** (correo real + contraseña fuerte) → quedan
   dos administradores activos.
2. Iniciar sesión con el usuario real y verificar el acceso.
3. **Inactivar o eliminar el admin temporal** (o al menos cambiar su contraseña). El
   sistema garantiza que siempre quede ≥1 administrador activo.

---

## 5.e Limpieza de DTE de prueba y panel de salud (2026-06-17)

Se agregó el panel **"Salud del sistema"** (`/admin/salud-sistema`, solo
administrador, solo lectura) y, a partir de sus alertas, se limpiaron datos viejos
de prueba **de forma segura** (solo borradores; no se tocó código ni cálculos):

- **2 notas de crédito sin tipo eliminadas** (soft-delete): DTE #5 y #6 — eran
  borradores viejos sin `tipo_nota_credito`, sin líneas y con total 0.
- **9 borradores vacíos con total 0 eliminados** (soft-delete): DTE #2, #3, #10,
  #11, #12, #15, #18, #22, #23 — todos en estado borrador, sin líneas, total 0,
  sin número oficial / JSON / sello / PDF y no referenciados por otros documentos.
- En total se retiraron **11 DTE de prueba** (de 25 a 14 activos). Cada borrado
  verificó previamente todas las condiciones de seguridad; soft-delete es reversible.
- **Documentos generados (10) y anulado (1) intactos**: el `DteObserver` impide
  borrar cualquier cosa fuera de borrador.

Resultado del panel de salud tras la limpieza:

- **Sin alertas de datos viejos**: "Documentos borrador con total 0" = 0 y
  "Notas de crédito sin tipo" = 0.
- **Solo quedan alertas de entorno** (dependen de `.env`, no tocado):
  - 🔴 `APP_DEBUG=true` (cambiar a `false` para producción).
  - 🟠 `APP_ENV=local` (entorno de desarrollo).
  - 🟠 1 administrador activo (conviene tener ≥2).

Tests tras la limpieza: **508 passed (1545 assertions)**.

---

## 5.f Fase JSON oficial preliminar cerrada (2026-06-17)

Se construyó la **generación del JSON oficial preliminar** de los DTE contra los
schemas oficiales del MH. **No** incluye firma, transmisión ni Hacienda real (sigue
fuera de alcance, ver §5).

**Schemas oficiales detectados** (`resources/dte/schemas/`):

| Tipo | Archivo |
|------|---------|
| 01 Factura consumidor final | `fe-f-v2` |
| 03 Comprobante de Crédito Fiscal | `fe-ccf-v4` |
| 05 Nota de crédito | `fe-nc-v4` |
| 11 Factura de exportación | `fe-fex-v3` |
| Invalidación | `invalidacion-schema-v3` |

**Catálogos MH importados:** del **CAT-001 al CAT-033**, **1680 registros** en
`catalogos_mh` (desde el Excel oficial). Dos correcciones de etiqueta detectadas y
corregidas en la importación:
- **CAT-018** corregido como **Plazo**.
- **CAT-031** corregido como **INCOTERMS**.

**Construido en esta fase:**
- **Serializadores oficiales** para los 4 tipos (01, 03, 05, 11): cada uno arma el
  array oficial según su schema (Factura con IVA incluido y receptor opcional /
  consumidor final; Exportación con país obligatorio, flete y seguro; NC con
  documento relacionado obligatorio que apunta al código de generación oficial del
  CCF original).
- **Validador real contra schema** con **`opis/json-schema`** (draft-07 completo;
  `justinrainbow` se removió por incompatibilidad con draft-07).
- **Comando `dte:json-preview {id}`**: vista previa de solo lectura (mapea, serializa
  y valida) — no persiste numeración ni archivo; con `--fake-identificacion` rellena
  numeración temporal solo en memoria.
- **Comando `dte:generar-json {id}`**: asigna numeración oficial (una sola vez,
  congelada), serializa, valida contra el schema y guarda el archivo +
  `json_generado_path`, todo en transacción (rollback si el schema falla).

**Documentos reales probados:**
- **CCF #17 (03)**: generado, validado contra `fe-ccf-v4` y guardado.
- **NC #19 (05)**: generada, validada contra `fe-nc-v4`, guardada y **relacionada al
  CCF #17** (su `documentoRelacionado` y la línea apuntan al código de generación
  oficial del CCF #17).
- **Factura 01 y Exportación 11**: cubiertas por **tests con datos de fábrica**,
  porque **no hay documentos reales** de esos tipos en la BD.

**Bug `multipleOf` corregido:** opis rechazaba montos legítimos (p. ej. 120.68)
porque a su escala por defecto (14 decimales) el double expone su error binario. Se
ajustó **`numberScale` del validador a 8** (absorbe el error del float en montos
`multipleOf 0.01` y respeta los campos de línea `multipleOf 1e-8`, sin dejar pasar
violaciones reales). **No se tocaron los montos ni `CalculadoraDte`** — solo la
tolerancia del validador.

**Tests:** **544 passed.**

**Confirmación:** **no hay firma, no hay transmisión, no hay sello, no hay Hacienda
real.** Cada generación imprime `*** SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A
HACIENDA ***`.

> ⚠️ **Nota de cuidado:** los JSON generados son **preliminares locales**. **No
> equivalen a un DTE emitido ante Hacienda** hasta completar **firma, transmisión,
> recepción/sello y PDF definitivo**.

---

## 5.g Generación de JSON oficial preliminar desde la interfaz (2026-06-17)

Se llevó la generación del JSON oficial preliminar a la **vista del DTE**
(`/facturacion/{dte}`), reutilizando el `DteJsonService` ya existente (el mismo del
comando `dte:generar-json`). Sigue **sin firma, sin transmisión y sin Hacienda real**.

- **Ruta creada:** `POST /facturacion/{dte}/json/generar` (nombre
  `facturacion.json.generar`), autorizada por `DtePolicy::generarJson`.
- **Botón:** **"Generar JSON oficial preliminar"** en la pantalla del documento.
- **Aparece solo si:**
  - el documento está en estado **generado**,
  - **no** tiene `json_generado_path` todavía,
  - el usuario es **administrador o facturación**.
- **Usa el `DteJsonService` existente** (mismo flujo y validaciones que el comando;
  `force=false`, nunca regenera desde la UI).
- **Si valida contra el schema**, guarda `numero_control`, `codigo_generacion` y
  `json_generado_path` (todo en una transacción; si el schema falla, rollback y
  mensaje de error claro, sin dejar nada a medias).
- **No firma · No transmite · No guarda sello · No cambia el estado a aceptado.**
- **No regenera** si ya existe JSON (la policy exige `json_generado_path` vacío → 403).
- **consulta/contador no pueden generar** (POST → 403) **ni ven el botón**.
- Tras generar, redirige al `show` con el mensaje
  **"JSON generado localmente. SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA"**,
  donde ya aparecen los botones **Ver JSON** y **Descargar JSON** (sección §5.f).
- **Tests: 564 passed.** Cubre: botón visible solo si generado y sin JSON, oculto si
  ya hay JSON o si es borrador, consulta/contador sin acceso, generación válida vía
  `DteJsonService`, no firma/transmite/sello, no cambia estado y no regenera.

> ⚠️ Igual que §5.f: el JSON sigue siendo **preliminar local**, no un DTE emitido
> ante Hacienda.

---

## 5.h Firmador local: health check y POST fake validados (2026-06-17)

Se validó la **conectividad con el firmador local del MH** (servicio Java/Spring
incluido en `resources/firmador/`), **sin firmar ningún DTE real**.

- El firmador local corre en **`http://localhost:8113`**.
- **Health check** `GET /firmardocumento/status` → **200** `"Application is running...!!!"`.
- **POST fake** a `/firmardocumento/` con payload de prueba (NIT `00000000000000`,
  `passwordPri` fake, `dteJson` inventado) → **200**, `status: ERROR`, código **803**
  `"No existe llave publica para este nit"`.
- Ese error controlado **confirma que el endpoint POST está vivo y procesa
  peticiones**, sin firmar ningún DTE real.
- **`DTE_FIRMA_ENABLED` sigue en `false`.** No se usó certificado real, no se usó
  contraseña real, no se leyó ningún DTE real, no se modificó la BD, no se transmitió
  a Hacienda.
- Soporte en Laravel (solo diagnóstico, no firma):
  - `DteFirmaService::healthCheck()` y `DteFirmaService::postTest()`.
  - Comandos `php artisan dte:firma-check {id}` (precondiciones del DTE + health check)
    y `php artisan dte:firma-post-test` (POST fake controlado).
  - Config `dte.firma` = {enabled:false, url, status_path, endpoint_path, timeout}.
- **Tests: 586 passed.**
- Guía para la futura firma real local: `docs/FIRMADOR_LOCAL.md`.

> ⚠️ Firmar localmente **NO** equivale a transmitir a Hacienda: el DTE solo está
> "emitido" tras firma + transmisión + recepción/sello + PDF definitivo.

---

## 5.i Primera firma local real exitosa (2026-06-17)

Se realizó la **primera firma LOCAL real** de un DTE con el firmador local del MH.
**No se transmitió nada a Hacienda.**

- DTE **#30** firmado localmente (CCF 03).
- numeroControl: `DTE-03-M001P001-000000000000012`
- codigoGeneracion: `B58C589F-F27A-43EE-8EE8-A6E9B4C968BF`
- Archivo firmado (JWS): `storage/app/dte/firmados/dte-03-30-B58C589F-F27A-43EE-8EE8-A6E9B4C968BF.jws`
- Mensaje confirmado: **FIRMADO LOCALMENTE / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA**.

Implementación usada (`DteFirmaService::firmar()` + `php artisan dte:firmar {id}`):
lee `json_generado_path`, hace POST al firmador local, recibe el JWS (`status: OK`),
lo guarda en `dte/firmados/` y setea `json_firmado_path`, todo en transacción.
**NO transmite, NO guarda `sello_recepcion`, NO cambia el estado a aceptado.** La
contraseña del certificado se lee de `.env` y nunca se imprime.

En la **vista del DTE** (`/facturacion/{dte}`), si existe `json_firmado_path`, aparece
el panel **"DTE firmado localmente"** (numeroControl, codigoGeneracion, ruta del JWS,
aviso de no transmisión) con botones **Ver / Descargar JWS firmado** (solo
administrador/facturación; `DtePolicy::verJsonFirmado`).

> ⚠️ Firmar localmente **NO** es emitir: el DTE solo queda emitido tras **transmisión
> + recepción/sello + PDF definitivo** (fases aún fuera de alcance).

---

## 5.j Preparación de transmisión DTE simulada / bloqueada (2026-06-17)

Se construyó la **infraestructura de transmisión a Hacienda (recepción)**, pero
**DESHABILITADA por defecto** y probada **solo con respuestas simuladas** (`Http::fake`).
**No se transmitió nada a Hacienda**, no se usaron credenciales reales, no se guardó
sello y no se cambió el estado a aceptado.

- **Bloqueo por configuración:** `dte.transmision.enabled` (env `DTE_TRANSMISION_ENABLED`,
  default **false**). Con `false`, ningún servicio/comando hace request HTTP.
- **`DteTransmisionService`**:
  - `diagnosticar($dte)` — solo lectura (JSON, JWS, sello, estado, si está habilitada).
  - `prepararPayloadRecepcion($dte)` — arma el payload interno (ambiente, idEnvio,
    version, tipoDte, codigoGeneracion, numeroControl, documento=JWS). Campos oficiales
    por confirmar quedan marcados con `TODO` (manual técnico del MH).
  - `transmitir($dte)` — si está deshabilitada, lanza excepción clara sin HTTP; si está
    habilitada, hace el POST e **interpreta** la respuesta (aceptado / rechazado /
    token inválido / respuesta malformada / error de conexión) **sin persistir sello ni
    cambiar estado** (eso es la fase real).
- **Comandos**:
  - `php artisan dte:transmision-check {id}` — diagnóstico (NO TRANSMITE / SOLO DIAGNÓSTICO).
  - `php artisan dte:transmitir {id}` — bloqueado si `enabled=false`:
    *"Transmisión deshabilitada. No se envió nada a Hacienda."* (sin request HTTP).
- **Precondiciones**: generado, con numero_control + codigo_generacion + json_firmado_path
  y archivo JWS existente, sin sello, no aceptado, no invalidado/anulado.
- Credenciales/token **solo desde `.env`**, nunca en código ni en logs.
- **Tests: 624 passed** (incluye respuestas simuladas aceptada/rechazada/error/token/
  malformada y todos los bloqueos).
- **La transmisión real queda para una fase posterior**, tras confirmar el manual
  técnico del MH (endpoints, autenticación, formato exacto del payload y persistencia
  del sello/estado).

> ⚠️ No se transmitió ningún DTE (ni el #30 ni ningún otro). Firmar/transmitir local
> **NO** es emitir ante Hacienda.

---

## 5.k Checkpoint de seguridad antes de transmisión real (2026-06-17)

Antes de avanzar a la transmisión real se hizo una **revisión de seguridad de secretos**.
**No se transmitió nada a Hacienda, no se guardó sello y no se cambió el estado a aceptado.**

- **Firma real local** ya fue validada una vez (DTE #30, ver §5.i).
- **La transmisión sigue DESHABILITADA** (`DTE_TRANSMISION_ENABLED=false`) y la firma
  también (`DTE_FIRMA_ENABLED=false`). Ningún comando/servicio transmite ni firma.
- **Secretos protegidos:**
  - El proyecto **aún no está bajo control de versiones** (sin `.git`): no hay riesgo
    de secretos versionados todavía.
  - `.gitignore` ignora `.env` y todo el material cripto: `/resources/firmador/`,
    `*.crt`, `*.p12`, `*.key`, `*.pem`.
  - **No hay credenciales reales en código, config, docs ni tests**: solo placeholders
    y sentinelas claramente fake (`FAKE_..._NO_REAL`, `***`).
  - Los certificados del emisor y de prueba viven solo dentro de `resources/firmador/`
    (ignorada), además cubiertos por las reglas `*.crt`/`*.p12`.
- **Mensajes de error** sobre credenciales dicen "configure DTE_..." / "no configurada"
  **sin mostrar nunca el valor**.
- **Comando nuevo `php artisan dte:seguridad-check`**: revisa `APP_DEBUG`,
  `DTE_FIRMA_ENABLED`, `DTE_TRANSMISION_ENABLED`, si `.env` está ignorado, si las reglas
  del firmador/cripto están en `.gitignore` y si hay material cripto fuera de rutas
  ignoradas — **sin imprimir ningún secreto** (solo "configurada (oculta)"/"no configurada").
- **`.env.example`** actualizado con placeholders seguros (firma + transmisión), todos
  vacíos o `false`.
- **Regla operativa:** las credenciales reales (contraseña del certificado, usuario/
  token de Hacienda) **no se guardan en docs ni en código**; van solo en el `.env` local.
- **El sistema actual de facturación (el oficial en uso) no fue tocado.**

> ⚠️ La transmisión real (endpoints, autenticación, sello, estado) sigue pendiente y
> fuera de alcance hasta confirmar el manual técnico del MH.

---

## 5.l Análisis del Manual Tecnológico de Transmisión (2026-06-17)

Se analizó el *Manual Técnico para la Integración Tecnológica del Sistema de
Transmisión v2* (`docs/referencias/`) para extraer la especificación exacta de la
futura transmisión. **No se transmitió nada a Hacienda** (todo sigue con
`DTE_TRANSMISION_ENABLED=false` y `Http::fake` en tests).

**Endpoints (TEST / PROD):**
- Autenticación (POST): `https://apitest.dtes.mh.gob.sv/seguridad/auth` /
  `https://api.dtes.mh.gob.sv/seguridad/auth`.
- Recepción uno-a-uno (POST): `.../fesv/recepciondte`. Lote: `.../fesv/recepcionlote`.
- Consulta DTE (POST) `.../fesv/recepcion/consultadte`; consulta lote (GET)
  `.../fesv/recepcion/consultadtelote/{codigoLote}`; contingencia `.../fesv/contingencia`;
  invalidación `.../fesv/anulardte`. **Sin barra final** en las URLs.

**Autenticación:** `content-Type: application/x-www-form-urlencoded`, body `user`+`pwd`,
respuesta `{ status:"OK", body:{ token:"Bearer eyJ...", ... } }`. Token con vigencia
**48 h (pruebas) / 24 h (producción)**.

**Recepción uno-a-uno:** headers `Authorization` (token con `Bearer`), `User-Agent`,
`content-Type: application/JSON`. Body: `ambiente, idEnvio (Integer), version (Integer),
tipoDte, documento (JWS firmado), codigoGeneracion`. **`numeroControl` NO va en el body.**
Respuesta por `estado`: `PROCESADO` (aceptado, con `selloRecibido`) / `RECHAZADO` (HTTP
400, sin sello), con `observaciones`. Sello = campo **`selloRecibido`**.

**Ajustes aplicados a `DteTransmisionService` (estructura, sin transmitir):**
- `prepararPayloadRecepcion()`: quitado `numeroControl`; campos exactos del manual.
- `transmitir()`: headers `Authorization` (con `Bearer`) + `User-Agent`; URL sin
  trailing slash; clasificación por `estado` aunque el HTTP sea 400; captura de
  `observaciones`. **Sigue sin persistir sello ni cambiar estado.**
- `config('dte.transmision')`: agregado `user_agent`.

Guía detallada: **`docs/TRANSMISION_DTE.md`**.

> ⚠️ La transmisión real sigue pendiente (autenticación/login, persistir sello y
> estado, reintentos) y **deshabilitada**. No se usaron credenciales reales.

---

## 5.m Preparación de autenticación/token de transmisión — simulada/bloqueada (2026-06-17)

Se construyó la **autenticación contra el servicio de seguridad del MH** para obtener
el token de transmisión, pero **BLOQUEADA por defecto** y probada **solo con
`Http::fake`**. **No se autenticó contra Hacienda real, no se transmitió nada, no se
usaron credenciales reales.**

- **`DteTransmisionAuthService`** (`obtenerToken()`):
  - Si `dte.transmision.enabled = false` → lanza `DteTransmisionDeshabilitadaException`
    **antes de cualquier HTTP**.
  - Login según el Manual (4.1): POST **form-urlencoded** `{ user, pwd }` + `User-Agent`
    a `…/seguridad/auth` (host por ambiente: `apitest` pruebas / `api` producción).
  - Acepta el token con prefijo `Bearer` (lo normaliza si no lo trae).
  - **Cachea** el token (Cache de Laravel) con TTL **47 h (pruebas) / 23 h (producción)**,
    por debajo de la vigencia oficial (48 h / 24 h). **No** se guarda en base de datos.
  - Maneja credenciales faltantes, token ausente, HTTP 401, respuesta malformada y
    timeout con mensajes claros **sin exponer usuario/contraseña/token**.
- **`DteTransmisionService::transmitir()`** ahora obtiene el token del auth service y lo
  envía en `Authorization`. Sigue **deshabilitado** y **sin persistir sello ni estado**.
- **Comandos nuevos**:
  - `php artisan dte:auth-check` — diagnóstico (ambiente, URL, habilitada/bloqueada,
    usuario/contraseña configurados o no — **sin mostrarlos**); aviso *"NO AUTENTICA /
    SOLO DIAGNÓSTICO"* cuando está deshabilitada.
  - `php artisan dte:auth-test` — bloqueado si `enabled=false` (sin HTTP); cuando se use
    real, muestra solo *"Token obtenido"* + vigencia, **nunca el token**.
- **Tests**: bloqueo antes de HTTP, login OK con `Http::fake`, fallos (sin user/pwd, sin
  token, 401, malformada), cache del token, comandos sin secretos, e integración
  transmitir→token del login en `Authorization`. **No imprime contraseñas ni tokens.**
- Guía: `docs/TRANSMISION_DTE.md` (sección de autenticación).

> ⚠️ La transmisión real sigue **deshabilitada**. No se autenticó ni transmitió a
> Hacienda; el token/credenciales nunca se guardan en docs, logs ni base de datos.

---

## 5.n Candados de seguridad antes de transmisión real (2026-06-17)

Se agregaron **candados fuertes** para evitar que por accidente se transmita un DTE
real o se choque con el **sistema actual en uso**. **No se transmitió nada, no se usaron
credenciales reales, el sistema actual sigue protegido.**

**Candados evaluados ANTES de cualquier HTTP** (`DteTransmisionService::evaluarCandados()`):
`DTE_TRANSMISION_ENABLED`, `DTE_TRANSMISION_REAL_CONFIRMATION`, `DTE_TRANSMISION_DRY_RUN`,
`DTE_TRANSMISION_ALLOW_PRODUCTION` y la convivencia con el sistema actual (ver §5.o).

**Comandos:** `dte:transmitir-dry-run {id}` (ensayo: arma el payload, no HTTP/sello/estado)
y `dte:preflight-real {id}` (checklist BLOQUEADO/LISTO, no transmite, sin secretos).
`dte:transmitir {id}` valida todos los candados antes de cualquier HTTP.

---

## 5.o Sistema actual vs sistema nuevo — modos de operación (2026-06-17)

Corrección de lenguaje y enfoque: **no es un "sistema viejo"** sino el **sistema actual /
oficial en uso**. El sistema nuevo convive como **paralelo**, **respaldo** o futuro
**principal**, sin riesgo de duplicar documentos, correlativos ni transmisiones.

**Variables `.env` (renombradas/nuevas; valores seguros):**
```
DTE_TRANSMISION_ENABLED=false
DTE_TRANSMISION_REAL_CONFIRMATION=false
DTE_TRANSMISION_ALLOW_PRODUCTION=false
DTE_TRANSMISION_DRY_RUN=true
DTE_SISTEMA_ACTUAL_ACTIVO=true      # antes: DTE_SISTEMA_VIEJO_ACTIVO (eliminada)
DTE_MODO_OPERACION=paralelo         # paralelo | respaldo | principal
```

**Modos de operación** (`DteTransmisionService::evaluarCandados()`):
- **paralelo** (actual): el sistema actual factura oficialmente; el nuevo solo genera
  JSON, firma local y dry-run. **Transmisión real bloqueada siempre.**
- **respaldo**: transmite **solo con confirmación manual fuerte** (`REAL_CONFIRMATION=true`)
  y revisión de correlativos; advierte que el sistema actual sigue activo.
- **principal**: el nuevo sería el oficial. Solo se permite si **todos** los demás
  candados están OK. **No activar** hasta definir la migración completa.
- Con `DTE_SISTEMA_ACTUAL_ACTIVO=true` y modo ≠ `principal`, la transmisión real queda
  bloqueada por defecto.

**Comando nuevo:** `php artisan dte:modo-operacion` — muestra modo, sistema actual activo,
firma/transmisión habilitadas, dry-run, producción permitida y el resultado
(**PARALELO SEGURO** / **RESPALDO BLOQUEADO** / **PRINCIPAL LISTO/BLOQUEADO**), sin secretos.
`dte:preflight-real` ahora muestra "Sistema actual en uso", "Modo de operación" y
"Riesgo de correlativos: requiere revisión manual"; resultado **BLOQUEADO** en modo paralelo.

> ⚠️ **No se debe transmitir desde dos sistemas sin coordinar correlativos, punto de
> venta y ambiente.** Por ahora el sistema nuevo opera en **modo paralelo seguro**; no
> activar producción ni `principal` hasta definir la migración. Ya no quedan referencias
> a "sistema viejo" en código, config ni `.env.example`.

---

## 5.p Pantalla admin de estado técnico/preflight DTE (2026-06-17)

Se agregó un panel visual en la vista del DTE (`/facturacion/{dte}`) para que
administradores/facturación revisen el estado técnico y los candados **sin usar la
terminal**. **Es solo diagnóstico visual: no transmite, no autentica contra Hacienda
real, no cambia estado, no guarda sello, no muestra secretos.**

- **Panel "Estado técnico DTE"**: tipo, estado interno, numeroControl, codigoGeneracion,
  JSON generado (sí/no + ruta), JWS firmado (sí/no + ruta), sello de recepción (no),
  estado aceptado (no), invalidado/anulado.
- **Panel "Preflight de transmisión"**: `DTE_TRANSMISION_ENABLED`,
  `DTE_TRANSMISION_DRY_RUN`, `DTE_TRANSMISION_REAL_CONFIRMATION`,
  `DTE_TRANSMISION_ALLOW_PRODUCTION`, `DTE_MODO_OPERACION`, `DTE_SISTEMA_ACTUAL_ACTIVO`,
  y el resultado: **BLOQUEADO** / **DRY-RUN DISPONIBLE** / **LISTO PARA TRANSMISIÓN**.
  Reusa `DteTransmisionService::preflight()/evaluarCandados()` (no duplica reglas).
- **Botones (solo gestores)**: Ver/Descargar JSON, Ver/Descargar JWS, y **Ejecutar
  dry-run visual** (ruta `POST /facturacion/{dte}/dry-run`). **No hay botón de
  transmitir real.**
- **Dry-run visual**: arma el payload final y muestra un resumen seguro (tipoDte,
  ambiente, version, codigoGeneracion, JWS sí/no + preview truncado, endpoint, auth
  sí/no). **NO muestra token/contraseña/JWS completo, NO hace HTTP, NO guarda sello,
  NO cambia estado.**
- **Roles**: admin y facturación ven el panel; **consulta/contador no ven el panel,
  ni las rutas crudas (`json_generado_path`/`json_firmado_path`), ni los botones**;
  invitado va a login. La policy `DtePolicy::verEstadoTecnico` gobierna el acceso.
- **Tests: 669 passed** (admin/facturación ven el panel; consulta/contador no ven
  rutas ni botones; invitado redirige; dry-run sin HTTP/sello/estado; modo paralelo
  muestra BLOQUEADO; no se imprime ningún secreto).

> ⚠️ El sistema nuevo sigue en **modo paralelo** (el sistema actual es el oficial en
> uso). Esta pantalla no transmite ni autentica contra Hacienda real.

---

## 5.q Prueba controlada de autenticación real en ambiente testing (2026-06-17)

Se habilitó la posibilidad de probar **solo el login/token** contra el ambiente de
**pruebas** de Hacienda, **sin transmitir ningún DTE**. Nueva variable candado:
**`DTE_AUTH_TEST_REAL_ENABLED`** (default `false`).

- **`DteTransmisionAuthService::pruebaAuthTesting()`** hace login real **solo si**:
  `DTE_AUTH_TEST_REAL_ENABLED=true`, ambiente `testing`, la URL contiene
  `apitest.dtes.mh.gob.sv`, y usuario+contraseña configurados. Si no, **no hace HTTP**.
  Bloquea producción y URLs que no sean apitest. El token vive **solo en Cache** (TTL
  testing) y **nunca se imprime**.
- **`php artisan dte:auth-test`** — con el flag en `false` bloquea sin HTTP; con todo OK
  intenta el login solo contra testing y muestra resultado seguro (ambiente, URL,
  usuario sí/no, password sí/no, token obtenido sí/no, token cacheado sí/no). Nunca
  imprime el token. No hace POST a `/fesv/recepciondte`.
- **`php artisan dte:auth-check`** ahora muestra `DTE_AUTH_TEST_REAL_ENABLED` activo/bloqueado
  (sin imprimir usuario/contraseña/token).
- **La transmisión sigue bloqueada:** aunque `dte:auth-test` funcione, `dte:transmitir 30`
  y `dte:preflight-real 30` siguen mostrando **BLOQUEADO** (enabled=false, modo paralelo).
- **Tests: 675 passed** (auth-test bloquea con flag false / producción / URL no apitest;
  obtiene token fake sin imprimirlo y sin POST a recepción; transmitir sigue bloqueado;
  no se guarda sello ni se cambia estado).

> ⚠️ Solo prueba login/token en **testing**: no transmite DTE, no usa producción, no
> guarda sello, no cambia estado y no afecta el sistema actual en uso.

---

## 5.r PDF / representación gráfica preliminar del DTE (2026-06-18)

Se creó la **representación gráfica en PDF** (dompdf) para los 4 tipos (CCF, Factura,
Nota de Crédito, Exportación), dejando **claro cuando un documento NO ha sido
transmitido**. **No se transmitió nada, no se usaron credenciales reales, no se cambió
estado ni se guardó sello; el sistema sigue en modo paralelo.**

- Plantilla `resources/views/facturacion/pdf.blade.php`: emisor, receptor, tipo,
  número de control, código de generación, fecha/hora, condición, tabla de productos,
  totales (subtotal/IVA/descuentos/retención/total), documento relacionado (NC) y
  apartado de exportación (flete/seguro).
- **Sección técnica** visible: JSON generado sí/no, firmado localmente sí/no, sello de
  recepción sí/no, estado Hacienda (no transmitido / aceptado / rechazado, según datos
  internos).
- **Marcas de seguridad** (sin `sello_recepcion`):
  - "DOCUMENTO NO TRANSMITIDO A HACIENDA / SIN SELLO DE RECEPCIÓN".
  - Si está firmado localmente: "FIRMADO LOCALMENTE / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA".
  - El archivo y el documento se marcan como **PRELIMINAR**; nunca dice "DTE aceptado"
    ni "emitido ante Hacienda" sin sello.
- **Sin QR oficial** mientras no haya datos oficiales: queda un espacio reservado para
  sello, fecha de procesamiento y QR cuando exista `sello_recepcion` (no se inventan).
- **Rutas** (solo lectura, `DtePolicy::view`): `GET /facturacion/{dte}/pdf` (ver) y
  `GET /facturacion/{dte}/pdf/descargar` (descargar). Botones **Ver PDF preliminar** /
  **Descargar PDF preliminar** en la vista del DTE.
- **Roles**: admin/facturación ven y descargan; consulta/contador pueden ver (permitido
  por la política `view` actual, igual que la impresión); invitado → login.
- **Tests: 685 passed** (PDF se genera para DTE con JSON y firmado; muestra "SIN SELLO
  DE RECEPCIÓN" y "NO ENVIADO A HACIENDA"; no cambia estado, no guarda sello, no
  transmite, no imprime credenciales).

> ⚠️ El PDF **no equivale a un DTE emitido** mientras no haya sello de recepción. No se
> transmitió nada a Hacienda ni se usaron credenciales reales; el sistema nuevo sigue
> en **modo paralelo**.

---

## 5.s Segunda pasada de diseño del PDF + emisor real (2026-06-18)

Segunda pasada **solo de presentación** del PDF preliminar (sin tocar cálculos,
numeración, firma, transmisión ni datos del DTE).

- **Emisor real:** el PDF mostraba el NIT/NRC placeholder (`0000-000000-000-0`) porque
  el establecimiento del DTE quedó enlazado a una empresa duplicada/sembrada. Ahora
  `DteController::resolverEmisorParaPdf()` detecta el NIT placeholder y usa la **empresa
  real del sistema** (NIT no placeholder, preferentemente activa). Solo presentación:
  no cambia la relación ni los datos del DTE. La plantilla además cae a `config/company`
  para campos vacíos. **Fuente de datos del emisor: tabla `empresas` (registro real,
  NIT que coincide con el certificado del firmador), con respaldo en `config/company.php`.**
  El emisor muestra razón social, nombre comercial, NIT, NRC, dirección, establecimiento,
  punto de venta, teléfono y correo.
- **Numeración:** muestra `numero_control` y `codigo_generacion` cuando existen; solo
  dice "pendiente" (estilo discreto) si están vacíos.
- **Rediseño:** encabezado más compacto con logo integrado al nombre, caja de metadatos
  más liviana (tipo DTE destacado, filas limpias, "pendiente" discreto), tarjetas
  receptor/condición de igual altura, tabla más fina, totales compactos con TOTAL
  destacado, **estado técnico discreto al final**, franjas de advertencia más elegantes
  (más delgadas pero claras), y el QR condicional intacto (sin sello → recuadro
  "QR oficial pendiente"; nunca QR inventado).
- **Tests: 690 passed** (numeración presente/pendiente, emisor real resuelto, marcas,
  estado técnico, QR condicional, logo ausente no rompe, no cambia estado/sello, no
  transmite, no imprime credenciales).

> ⚠️ Solo cambió presentación del PDF. No se transmitió nada a Hacienda, no se usaron
> credenciales reales, no se modificaron cálculos ni estados; el sistema sigue en modo
> paralelo.

---

## 6. Próximos pasos recomendados

1. **Esquemas oficiales del MH**: colocar los JSON schemas oficiales en
   `resources/dte/schemas` y habilitar la validación pre-JSON ya preparada.
2. **Mapeo a JSON del MH**: completar los DTO de salida y el mapeador con la
   estructura oficial confirmada (NC con/sin documento relacionado incluido).
3. **Firma y transmisión**: integrar firmador y el cliente de recepción del MH
   (ambiente de pruebas primero), persistiendo sello y estado real.
4. **PDF definitivo**: generar el PDF con representación gráfica oficial una vez
   exista el JSON válido.
5. **Inventario**: decidir e implementar el impacto de devoluciones/averías en stock
   (hoy explícitamente fuera de alcance).
6. **Higiene de datos de desarrollo**: depurar los clientes "Calleja" *soft-deleted*
   duplicados (id 7 y 8) que impiden re-correr el seeder completo en la BD de dev
   (el Calleja activo es id=10). Requiere confirmación antes de borrar datos.
7. **Numeración oficial**: cuando exista `numero_control` oficial del MH, el listado
   y las vistas ya están preparados para mostrarlo en lugar del número interno.
8. **Política de precios**: revisar (negocio) si el precio general debe diferir del
   precio especial Calleja; hoy coinciden en los productos cargados. No se modificó.

---

> **Importante:** Esta fase queda marcada como estable. No modificar la lógica de
> CCF, Factura 01, Exportación, Nota de crédito, anulación ni los cálculos fiscales
> sin una nueva decisión explícita.
