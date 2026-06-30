# Sistema de Facturación Electrónica — Dulces La Negrita
## Documento de Arquitectura y Planificación Técnica

> Versión 1.0 — Junio 2026
> Sistema DTE (Documento Tributario Electrónico) — Ministerio de Hacienda, El Salvador

---

## 1. Arquitectura general del sistema

### 1.1 Stack tecnológico recomendado

| Componente | Tecnología | Justificación |
|---|---|---|
| Framework | Laravel 12 (PHP 8.3+) | LTS de facto, ecosistema maduro, seguridad integrada |
| Base de datos | **MySQL 8.0** | Ya disponible en Laragon; soporte JSON nativo suficiente para guardar DTEs. PostgreSQL es válido, pero MySQL simplifica tu entorno actual |
| Frontend | Blade + Livewire 3 (o Blade + Alpine.js) | Monolito simple, sin API pública innecesaria, menos superficie de ataque que SPA |
| Colas | Base de datos (fase 1) → Redis (cuando crezca) | El envío a Hacienda DEBE ser asíncrono y reintentable |
| Firmador | Servicio oficial del MH (`svfe-api-firmador`, Java, puerto local 8113) | Hacienda exige firmar con su herramienta; corre como servicio local junto al servidor |
| PDF | `barryvdh/laravel-dompdf` | Suficiente para representación gráfica del DTE con QR |
| Roles/Permisos | `spatie/laravel-permission` | Estándar de la industria |
| Auditoría | `spatie/laravel-activitylog` + tabla propia de historial DTE | Auditoría general + trazabilidad fiscal específica |
| Backups | `spatie/laravel-backup` | BD + archivos, notificaciones de fallo |
| QR | `simplesoftwareio/simple-qrcode` | QR obligatorio en la representación gráfica |

### 1.2 Diagrama de capas

```
┌─────────────────────────────────────────────────────────────┐
│  DISPOSITIVOS CLIENTE (PCs, tablets en la empresa)          │
│  Acceso vía VPN (Tailscale/WireGuard) — HTTPS               │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  SERVIDOR LOCAL (no expuesto a internet)                    │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ LARAVEL                                                │ │
│  │  Capa HTTP: Controllers + Form Requests + Policies     │ │
│  │  Capa Negocio: Services (DTE, Hacienda, PDF, Email)    │ │
│  │  Capa Datos: Eloquent Models + Repositorios implícitos │ │
│  │  Capa Async: Jobs en cola (envío MH, email, PDF)       │ │
│  └────────────────────────────────────────────────────────┘ │
│  ┌──────────────┐  ┌───────────┐  ┌───────────────────────┐ │
│  │ MySQL 8      │  │ Firmador  │  │ Storage local         │ │
│  │              │  │ MH :8113  │  │ (JSON, PDF, backups)  │ │
│  └──────────────┘  └───────────┘  └───────────────────────┘ │
└──────────────────────────┬──────────────────────────────────┘
                           │ ÚNICA conexión saliente a internet
┌──────────────────────────▼──────────────────────────────────┐
│  API MINISTERIO DE HACIENDA                                 │
│  Pruebas (ambiente 00) / Producción (ambiente 01)           │
│  Auth (JWT) → Recepción DTE → Eventos (invalidación,        │
│  contingencia) → Consulta                                   │
└─────────────────────────────────────────────────────────────┘
```

### 1.3 Acceso remoto seguro (decisión de infraestructura)

**Recomendación: Tailscale (WireGuard administrado).**

- El servidor **nunca** abre puertos hacia internet. Solo hace conexiones **salientes** (API de Hacienda y SMTP).
- Cada dispositivo autorizado instala Tailscale y entra a la red privada (tailnet). El acceso se controla por dispositivo y por usuario, con ACLs.
- Alternativas válidas:
  - **WireGuard puro** (gratuito, más trabajo manual de llaves/IPs).
  - **Cloudflare Tunnel + Cloudflare Access**: si algún día necesitas acceso desde dispositivos donde no puedes instalar VPN. El túnel también es solo-saliente.
- Dentro de la red: HTTPS obligatorio igualmente (certificado interno o el HTTPS automático de Tailscale).
- **No usar**: port forwarding en el router, DDNS con puerto 80/443 abierto, ni anydesk/escritorio remoto como método de acceso al sistema.

### 1.4 Principios de diseño

1. **El DTE es inmutable una vez firmado.** Nunca se edita un documento emitido; se invalida o se corrige con nota de crédito/débito. La BD debe reflejar esto.
2. **Todo intento de comunicación con Hacienda queda registrado** (request, response, timestamp, resultado), aunque falle.
3. **Estados como máquina de estados explícita**, con transiciones válidas definidas en código, no estados libres.
4. **Separación generación / firma / transmisión.** Son pasos independientes, reintentables por separado.
5. **Correlativo de número de control transaccional**: se asigna con bloqueo de fila (lock) para que nunca haya duplicados ni huecos accidentales.

---

## 2. Módulos principales

| # | Módulo | Fase | Contenido |
|---|---|---|---|
| 1 | **Seguridad y usuarios** | 1 | Login, roles (admin, facturación, consulta, contador), permisos granulares, auditoría, bloqueo por fuerza bruta |
| 2 | **Configuración del emisor** | 1 | Datos fiscales de Dulces La Negrita, establecimientos/puntos de venta, ambientes MH, certificados, correlativos |
| 3 | **Clientes (receptores)** | 1 | Nacionales y de exportación, datos fiscales completos |
| 4 | **Catálogo de productos** | 1 | Productos/servicios con tipo de impuesto, preparado para inventario |
| 5 | **Documentos DTE** | 2 | Emisión de FC, CCF, FEX, NC; estados; relación NC↔documento original |
| 6 | **Integración Hacienda** | 2-3 | Generación JSON, validación, firma, transmisión, eventos (invalidación, contingencia) |
| 7 | **PDF y correo** | 3 | Representación gráfica con QR, envío al cliente, historial de envíos |
| 8 | **Reportes y consulta** | 4 | Libro de ventas, consulta de DTEs, exportaciones para el contador |
| 9 | **Respaldos** | 1 (config) | Backup automático BD + archivos, local y nube |
| 10 | *Inventario* | Futuro | Solo se dejan los puntos de extensión |
| 11 | *Prontos pagos* | Futuro | Solo se dejan los puntos de extensión |

---

## 3. Modelos principales de Laravel

```
app/Models/
├── User.php                  # Usuarios del sistema
├── Empresa.php               # Datos del emisor (Dulces La Negrita)
├── Establecimiento.php       # Casa matriz / sucursales (códigos MH)
├── PuntoVenta.php            # Puntos de venta por establecimiento
├── Cliente.php               # Receptores (nacionales y exportación)
├── Producto.php              # Catálogo
├── UnidadMedida.php          # Catálogo MH de unidades (CAT-014)
├── ActividadEconomica.php    # Catálogo MH de actividades (CAT-019)
├── Dte.php                   # Documento tributario electrónico (núcleo)
├── DteDetalle.php            # Líneas del documento
├── DteEstadoHistorial.php    # Historial de transiciones de estado
├── DteTransmision.php        # Cada intento de envío a MH (request/response)
├── DteInvalidacion.php       # Eventos de invalidación
├── DteEnvioEmail.php         # Historial de correos enviados
├── Correlativo.php           # Control de número de control por tipo/punto venta
├── ContingenciaEvento.php    # Eventos de contingencia (fase posterior)
└── HaciendaToken.php         # Cache del JWT de autenticación MH
```

Notas:
- `Dte` es **un solo modelo** para todos los tipos (FC, CCF, FEX, NC...), diferenciado por `tipo_dte`. Evita duplicar lógica; las diferencias de estructura JSON viven en los *builders* del servicio generador.
- La relación NC → documento original se modela con `dte_relacionado_id` en `Dte` (self-reference), porque el JSON del MH exige el bloque `documentoRelacionado`.
- Catálogos del MH (tipos de documento, departamentos, municipios, unidades, actividades económicas, etc.) se cargan por **seeders** desde los catálogos oficiales.

---

## 4. Tablas principales de base de datos

### Seguridad y auditoría
```
users                  id, name, email, password, activo, ultimo_login_at,
                       intentos_fallidos, bloqueado_hasta, timestamps, softDeletes
roles / permissions / model_has_roles / model_has_permissions / role_has_permissions
                       (spatie/laravel-permission)
activity_log           (spatie/activitylog: quién hizo qué, sobre qué modelo,
                       valores antes/después, IP)
sessions               manejo de sesiones en BD
```

### Emisor y configuración
```
empresas               id, nit, nrc, nombre, nombre_comercial, actividad_economica_id,
                       departamento, municipio, direccion, telefono, correo,
                       tipo_establecimiento, logo_path, timestamps
establecimientos       id, empresa_id, codigo_mh (4 chars, ej. M001), nombre,
                       direccion, departamento, municipio, activo
puntos_venta           id, establecimiento_id, codigo_mh (4 chars, ej. P001),
                       nombre, activo
correlativos           id, tipo_dte (01/03/05/11...), establecimiento_id,
                       punto_venta_id, ultimo_numero (bigint), timestamps
                       → UNIQUE(tipo_dte, establecimiento_id, punto_venta_id)
hacienda_tokens        id, ambiente, token (encrypted), expira_en, timestamps
```

Credenciales y certificado del MH: **NO van en BD**. Van en `.env` / archivos protegidos (ver sección 12).

### Clientes
```
clientes               id, tipo_cliente ENUM(nacional, exportacion),
                       tipo_documento_identificacion (CAT-022: 36=NIT, 13=DUI, 02=carnet
                       residente, 03=pasaporte, 37=otro),
                       num_documento, nrc (nullable), nombre, nombre_comercial,
                       actividad_economica_id (nullable),
                       departamento, municipio, direccion,        ← nacionales
                       pais_id, complemento_direccion,            ← exportación
                       tipo_persona ENUM(natural, juridica),      ← exportación FEX
                       correo, telefono, activo,
                       timestamps, softDeletes
paises                 catálogo MH (CAT-020)
departamentos          catálogo MH (CAT-012)
municipios             catálogo MH (CAT-013, depende de departamento)
actividades_economicas catálogo MH (CAT-019)
```

### Productos
```
productos              id, codigo (interno, UNIQUE), nombre, descripcion,
                       unidad_medida_id, precio_unitario DECIMAL(11,8)*,
                       tipo_item ENUM(producto=1, servicio=2),
                       tipo_impuesto ENUM(gravado, exento, no_sujeto),
                       maneja_inventario BOOLEAN DEFAULT false,   ← gancho futuro
                       producto_inventario_ref VARCHAR nullable,  ← gancho futuro
                       activo, timestamps, softDeletes
unidades_medida        catálogo MH (CAT-014)
```
\* El MH acepta hasta 8 decimales en precios unitarios; los totales van a 2 decimales.

### Documentos DTE (núcleo del sistema)
```
dtes
  id                      bigint PK
  tipo_dte                CHAR(2)   -- 01=FC, 03=CCF, 05=NC, 06=ND, 11=FEX, 14=FSE
  codigo_generacion       UUID v4 MAYÚSCULAS, UNIQUE  -- identificador ante MH
  numero_control          VARCHAR(31), UNIQUE
                          -- formato: DTE-{tipo}-{cod estab+pv}-{correlativo 15 díg}
                          -- ej: DTE-03-M001P001-000000000000001
  ambiente                CHAR(2)   -- 00=pruebas, 01=producción
  version_json            INT       -- versión del esquema usado (FC=1, CCF=3, NC=3, FEX=1)
  estado                  ENUM(borrador, generado, firmado, enviado, aceptado,
                               rechazado, invalidado, contingencia)
  establecimiento_id      FK
  punto_venta_id          FK
  cliente_id              FK nullable (FC a consumidor final puede ir sin cliente)
  dte_relacionado_id      FK self nullable  -- NC/ND apunta al CCF original
  condicion_operacion     TINYINT  -- 1=contado, 2=crédito, 3=otro
  -- Montos (todos DECIMAL(11,2) salvo indicación)
  total_no_sujeto, total_exento, total_gravado,
  descuento_no_sujeto, descuento_exento, descuento_gravado, descuento_global,
  subtotal, iva (13%), iva_retenido, retencion_renta,
  monto_total_operacion, total_pagar
  -- FEX
  tipo_exportacion        nullable, incoterms nullable, flete, seguro
  -- Archivos y respuestas (rutas a storage, NO blobs en BD)
  json_generado_path      VARCHAR nullable
  json_firmado_path       VARCHAR nullable
  pdf_path                VARCHAR nullable
  sello_recepcion         VARCHAR nullable  -- sello que devuelve MH al aceptar
  fecha_emision           DATE
  hora_emision            TIME
  fecha_procesamiento_mh  DATETIME nullable
  observaciones           TEXT nullable
  motivo_contingencia     nullable
  created_by              FK users  -- quién lo creó
  enviado_by              FK users nullable  -- quién lo transmitió
  invalidado_by           FK users nullable
  timestamps
  → INDEX(estado), INDEX(tipo_dte, fecha_emision), INDEX(cliente_id)

dte_detalles
  id, dte_id FK, numero_item, producto_id FK nullable,
  codigo, descripcion,            -- snapshot del producto al momento de emitir
  cantidad DECIMAL(11,8), unidad_medida_codigo,
  precio_unitario DECIMAL(11,8), descuento_item DECIMAL(11,2),
  venta_no_sujeta, venta_exenta, venta_gravada DECIMAL(11,2),
  iva_item DECIMAL(11,2)          -- requerido en FC (IVA incluido en precio)
  → El detalle guarda COPIA de los datos del producto (snapshot), porque el
    documento fiscal no puede cambiar si luego se edita el producto.

dte_estado_historial
  id, dte_id FK, estado_anterior, estado_nuevo, user_id FK,
  comentario nullable, created_at
  → Trazabilidad completa: quién y cuándo movió cada documento de estado.

dte_transmisiones
  id, dte_id FK, tipo ENUM(envio, consulta, invalidacion, contingencia),
  endpoint, request_payload JSON, response_payload JSON nullable,
  http_status nullable, resultado ENUM(exito, rechazo, error_red, timeout),
  intento_numero, user_id FK nullable, created_at
  → CADA intento queda registrado, exitoso o no. Evidencia ante el MH.

dte_invalidaciones
  id, dte_id FK, dte_reemplazo_id FK nullable,  -- tipo 1: documento que sustituye
  tipo_invalidacion TINYINT,  -- 1=error con reemplazo, 2=rescisión, 3=otro
  motivo TEXT, codigo_generacion_evento UUID,
  responsable_nombre, responsable_doc, solicitante_nombre, solicitante_doc,
  json_evento_path, json_firmado_path, sello_recepcion nullable,
  estado ENUM(pendiente, firmado, enviado, aceptado, rechazado),
  user_id FK, timestamps

dte_envios_email
  id, dte_id FK, destinatario, cc nullable, asunto,
  estado ENUM(pendiente, enviado, fallido), error TEXT nullable,
  user_id FK, enviado_at nullable, timestamps
```

### Ganchos para módulos futuros (solo estructura mínima)
```
-- NO se crean todavía las tablas de inventario ni prontos pagos.
-- Lo único que queda preparado AHORA:
--   productos.maneja_inventario  (boolean, default false)
--   productos.producto_inventario_ref  (referencia externa nullable)
--   dtes: por su diseño ya permite consultar CCF por cliente/fecha (prontos pagos)
--   Evento de dominio "DteAceptado" / "NotaCreditoAceptada" que en el futuro
--   escuchará el módulo de inventario para descontar/revertir stock.
```

---

## 5. Relaciones entre tablas

```
empresas 1──N establecimientos 1──N puntos_venta
establecimientos 1──N correlativos (por tipo_dte y punto de venta)

clientes 1──N dtes
clientes N──1 actividades_economicas
clientes N──1 paises / departamentos / municipios

productos 1──N dte_detalles        (nullable: el detalle sobrevive al producto)
productos N──1 unidades_medida

dtes 1──N dte_detalles
dtes 1──N dte_estado_historial
dtes 1──N dte_transmisiones
dtes 1──N dte_envios_email
dtes 1──0..1 dte_invalidaciones
dtes 1──N dtes (self: dte_relacionado_id — una NC referencia su CCF;
                un CCF puede tener varias NC)
dte_invalidaciones N──0..1 dtes (dte_reemplazo_id)

users 1──N dtes (created_by) / (enviado_by) / (invalidado_by)
users 1──N dte_estado_historial
users N──N roles N──N permissions
```

### Máquina de estados del DTE

```
borrador ──(generar JSON)──► generado ──(firmar)──► firmado ──(transmitir)──► enviado
                                                                    │
                                              ┌─────────────────────┤
                                              ▼                     ▼
                                          aceptado             rechazado
                                              │                     │ (corregir y
                                              │                      reintentar como
                                  (evento invalidación               nuevo DTE o
                                   aceptado por MH)                  regenerar)
                                              ▼
                                         invalidado

  contingencia: estado paralelo cuando MH no está disponible
  (se emite localmente y se transmite después con evento de contingencia)
```

Reglas duras:
- `borrador` es el **único** estado editable/eliminable.
- De `aceptado` solo se sale a `invalidado` (vía evento aceptado por MH).
- `rechazado` no se reutiliza el mismo código de generación si MH ya lo registró: se genera documento nuevo.
- Toda transición pasa por un único método (`DteStateMachine`) que valida la transición y escribe `dte_estado_historial`.

---

## 6. Flujo completo: emitir un CCF (tipo 03)

```
1. CREACIÓN (usuario rol facturación)
   - Selecciona cliente (debe tener NIT + NRC + actividad económica → validado).
   - Agrega líneas: producto, cantidad, precio, descuento.
   - El sistema calcula: gravado/exento/no sujeto por línea, IVA 13% sobre
     gravado (en CCF el IVA va desglosado, NO incluido en precio), totales.
   - Estado: BORRADOR. Aún sin número de control.

2. GENERACIÓN (acción "Emitir")
   - Transacción BD con lock:
       a. Toma correlativo siguiente (SELECT ... FOR UPDATE sobre correlativos).
       b. Genera numero_control = DTE-03-M001P001-{correlativo}.
       c. Genera codigo_generacion = UUID v4 mayúsculas.
   - DTEGeneratorService construye el JSON según esquema fe-ccf-v3:
       identificacion, emisor, receptor, cuerpoDocumento[], resumen, extension.
   - DTEValidatorService valida contra el JSON Schema oficial + reglas de
     negocio (sumas cuadran, redondeos, campos condicionales).
   - Guarda JSON en storage/dte/{año}/{mes}/{codigo_generacion}.json
   - Estado: GENERADO. Historial registrado.

3. FIRMA
   - DTEFirmadorService hace POST al firmador local del MH (localhost:8113/firmardocumento)
     con NIT, password del certificado (desde config cifrada) y el JSON.
   - Respuesta: JWS (JSON firmado). Se guarda en storage.
   - Estado: FIRMADO.

4. TRANSMISIÓN (Job en cola: TransmitirDteJob)
   - HaciendaApiService:
       a. Obtiene token JWT (cacheado en hacienda_tokens; renueva si expiró).
       b. POST /fesv/recepciondte con {ambiente, idEnvio, version, tipoDte,
          documento: <JWS>, codigoGeneracion}.
       c. Registra dte_transmisiones (request + response SIEMPRE).
   - Respuesta PROCESADO → estado ACEPTADO; guarda sello_recepcion y
     fecha_procesamiento_mh.
   - Respuesta RECHAZADO → estado RECHAZADO; guarda observaciones del MH.
   - Error de red/timeout → reintentos automáticos del job (backoff exponencial,
     ej. 1m, 5m, 15m). Si MH está caído de forma prolongada → flujo de contingencia.

5. POST-ACEPTACIÓN (Jobs encadenados)
   - DTEPdfService genera la representación gráfica:
     datos del documento + sello de recepción + QR (enlace de consulta pública MH
     con ambiente, codGen y fechaEmi).
   - DTEEmailService envía al correo del cliente: PDF + JSON adjuntos.
     Registra dte_envios_email.
   - Evento de dominio DteAceptado (futuro: inventario descuenta stock).
```

## 7. Flujo completo: factura de exportación (tipo 11, FEX)

Igual al CCF en mecánica (generar → firmar → transmitir → PDF/email), con diferencias de **contenido**:

```
- Cliente: tipo "exportación" → exige tipo_persona, tipo y número de documento
  (pasaporte/NIT extranjero...), país (catálogo MH), descripción de dirección.
- JSON según esquema fe-fex-v1 (estructura distinta a CCF):
    · receptor con campos de extranjero
    · ventas gravadas con TASA 0% de IVA (exportación no causa IVA)
    · campos propios: tipoItemExpor, recintoFiscal, regimen (catálogos MH),
      incoterms, flete, seguro, moneda USD
- Validaciones específicas en DTEValidatorService (reglas FEX).
- Correlativo propio: DTE-11-M001P001-...
- PDF en formato exportación (puede incluir leyendas en inglés si se desea).
```

## 8. Flujo completo: nota de crédito (tipo 05)

```
1. ORIGEN OBLIGATORIO
   - La NC se crea DESDE un CCF en estado ACEPTADO (la NC electrónica solo
     aplica a CCF/ND/comprobantes con crédito fiscal, no a factura consumidor).
   - UI: "Crear nota de crédito" desde la vista del CCF.
   - dte_relacionado_id = id del CCF. El JSON lleva bloque documentoRelacionado
     {tipoDocumento: 03, tipoGeneracion: 2 (electrónico),
      numeroDocumento: codigo_generacion del CCF, fechaEmision}.

2. CONTENIDO
   - El usuario selecciona qué líneas del CCF se acreditan (total o parcial)
     y por qué (devolución, descuento posterior, error de precio...).
   - Validación dura: la suma de NC existentes + esta NC no puede exceder
     los montos del CCF original (control por línea y por total).
   - Restricción temporal del MH: la NC debe referenciar documentos dentro
     del plazo legal (validar configurable).

3. EMISIÓN
   - Mismo pipeline: correlativo propio (DTE-05-...), JSON fe-nc-v3,
     validar → firmar → transmitir → aceptado → PDF/email.

4. EFECTOS
   - El CCF original NO cambia de estado (sigue aceptado); la vista del CCF
     muestra sus NC asociadas y el "saldo" después de notas.
   - "Anular" una NC = invalidarla con el flujo de invalidación (sección 9);
     al invalidarse, libera el monto acreditado del CCF.
   - Evento NotaCreditoAceptada (futuro: inventario revierte stock si la NC
     fue por devolución — el motivo se guarda para distinguirlo).
```

## 9. Flujo completo: invalidación / anulación

```
1. SOLICITUD (permiso especial: solo admin o facturación con permiso "dte.invalidar")
   - Solo documentos en estado ACEPTADO.
   - Plazo legal del MH (validar configurable): FC/CCF normalmente hasta
     cierto número de días tras la emisión; el sistema bloquea fuera de plazo
     y muestra el motivo.
   - Usuario indica:
       · tipo de invalidación: 1 = error en documento (exige DTE de reemplazo),
         2 = rescisión de la operación, 3 = otro (exige motivo)
       · motivo, nombre y documento del responsable y del solicitante
       · si tipo 1: código de generación del DTE que lo reemplaza
         (el reemplazo se emite ANTES o en el mismo acto)

2. EVENTO DE INVALIDACIÓN
   - Se crea registro en dte_invalidaciones con su propio
     codigo_generacion_evento (UUID).
   - DTEGeneratorService construye el JSON del evento (esquema
     anulacion-schema-v2): identificacion, emisor, documento (datos del DTE
     a invalidar + su sello), motivo.
   - Firmar (mismo firmador) → transmitir a endpoint de anulación
     (/fesv/anulardte) → registrar en dte_transmisiones.

3. RESULTADO
   - Aceptado por MH → DTE pasa a estado INVALIDADO (con sello del evento);
     historial registra quién y por qué; el PDF se regenera con marca de agua
     "INVALIDADO" (se conserva también el PDF original).
   - Rechazado → la invalidación queda en estado rechazado con la respuesta
     del MH; el DTE sigue aceptado.
   - Notificación opcional por correo al cliente informando la invalidación.

4. REGLAS
   - Un DTE invalidado es INMUTABLE e imborrable (igual que todos los emitidos).
   - No se puede invalidar un CCF que tenga NC aceptadas vigentes
     (primero se invalidan las NC) — regla de consistencia propia del sistema.
```

---

## 10. Servicios necesarios

```
app/Services/Dte/
├── DteGeneratorService.php      # Orquesta la construcción del JSON por tipo
│   └── Builders/                #   un builder por esquema
│       ├── FacturaBuilder.php          (01, fe-fc-v1)
│       ├── CreditoFiscalBuilder.php    (03, fe-ccf-v3)
│       ├── NotaCreditoBuilder.php      (05, fe-nc-v3)
│       ├── FacturaExportacionBuilder.php (11, fe-fex-v1)
│       └── InvalidacionBuilder.php     (evento anulación)
├── DteValidatorService.php      # Valida contra JSON Schema oficial del MH
│                                # + reglas de negocio (totales, redondeos,
│                                #   campos condicionales por tipo)
├── DteFirmadorService.php       # Cliente HTTP del firmador local del MH (:8113)
├── HaciendaApiService.php       # Auth (token JWT con cache/renovación),
│                                # recepción DTE, anulación, contingencia,
│                                # consulta. Registra TODA transmisión.
├── DtePdfService.php            # Representación gráfica + QR + marca de agua
├── DteEmailService.php          # Envío al cliente con adjuntos + historial
├── CorrelativoService.php       # Asignación transaccional del número de control
├── DteStateMachine.php          # Transiciones de estado válidas + historial
└── ContingenciaService.php      # (fase posterior) eventos de contingencia

app/Jobs/
├── TransmitirDteJob.php         # Cola: firma+envío con reintentos/backoff
├── TransmitirInvalidacionJob.php
├── GenerarPdfDteJob.php
└── EnviarDteEmailJob.php

app/Events/                      # Ganchos para módulos futuros
├── DteAceptado.php              # → inventario (futuro) descuenta stock
├── NotaCreditoAceptada.php      # → inventario (futuro) revierte stock
└── DteInvalidado.php
```

Cada servicio se registra con interfaz + binding en un `DteServiceProvider`, de modo que en testing se puedan sustituir por *fakes* (no se le pega a Hacienda en tests).

---

## 11. Estructura recomendada de carpetas

```
Facturacion/
├── app/
│   ├── Enums/                   # TipoDte, EstadoDte, AmbienteMh, TipoInvalidacion...
│   ├── Events/
│   ├── Exceptions/              # DteValidationException, HaciendaApiException...
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   ├── ClienteController.php
│   │   │   ├── ProductoController.php
│   │   │   ├── DteController.php          # CRUD borradores + emitir
│   │   │   ├── DteInvalidacionController.php
│   │   │   ├── NotaCreditoController.php
│   │   │   └── Admin/ (usuarios, roles, configuración, auditoría)
│   │   ├── Middleware/
│   │   └── Requests/            # Form Requests: TODA validación de entrada aquí
│   ├── Jobs/
│   ├── Models/
│   ├── Policies/                # DtePolicy, ClientePolicy... (autorización)
│   ├── Providers/
│   └── Services/Dte/            # (sección 10)
├── config/
│   ├── dte.php                  # ambiente, urls MH, plazos, decimales, rutas storage
│   └── ...
├── database/
│   ├── migrations/
│   └── seeders/
│       ├── CatalogosMhSeeder.php    # departamentos, municipios, unidades,
│       │                            # actividades, países (catálogos oficiales)
│       └── RolesPermisosSeeder.php
├── resources/
│   ├── views/
│   │   ├── dte/                 # pantallas de emisión
│   │   ├── pdf/                 # plantillas Blade de representación gráfica
│   │   │   ├── ccf.blade.php
│   │   │   ├── factura.blade.php
│   │   │   ├── fex.blade.php
│   │   │   └── nota-credito.blade.php
│   │   └── emails/
│   └── schemas/                 # JSON Schemas oficiales del MH (versionados)
│       ├── fe-ccf-v3.json
│       ├── fe-fc-v1.json
│       ├── fe-nc-v3.json
│       ├── fe-fex-v1.json
│       └── anulacion-schema-v2.json
├── storage/
│   └── app/
│       ├── dte/{año}/{mes}/     # JSON generado, JSON firmado (privado)
│       ├── pdf/{año}/{mes}/
│       └── certificados/        # certificado .crt del MH (permisos restringidos)
├── tests/
│   ├── Feature/                 # flujos completos con HaciendaApiService fake
│   └── Unit/                    # builders, cálculos de totales, state machine
└── ARQUITECTURA.md              # este documento
```

---

## 12. Medidas de seguridad desde el inicio

### Autenticación y fuerza bruta
- Laravel Breeze/Fortify con sesiones (no tokens para la web interna).
- Rate limiting en login: 5 intentos → bloqueo temporal incremental; registro de intentos fallidos por usuario e IP en `activity_log`.
- Contraseñas: política de longitud mínima 12, hash Argon2id/bcrypt (nativo Laravel).
- Expiración de sesión por inactividad (configurable, ej. 30 min).
- 2FA (TOTP) al menos para rol administrador — barato de agregar con Fortify.

### Autorización
- **Policies en todos los modelos sensibles**: nadie toca un DTE sin pasar por `DtePolicy`.
- Permisos granulares, no solo roles: `dte.crear`, `dte.emitir`, `dte.invalidar`, `dte.consultar`, `clientes.gestionar`, `config.gestionar`, `auditoria.ver`, `reportes.exportar`.
- Roles iniciales:
  - **Administrador**: todo.
  - **Facturación**: crear/emitir DTE, gestionar clientes; NO invalidar (permiso aparte asignable), NO configuración.
  - **Consulta**: solo lectura de documentos y reportes.
  - **Contador**: lectura + exportar reportes/libros + ver auditoría.

### Inyección y XSS/CSRF
- **SQL Injection**: solo Eloquent/Query Builder con bindings; prohibido `DB::raw` con input del usuario; revisado en code review.
- **XSS**: Blade escapa por defecto (`{{ }}`); `{!! !!}` prohibido salvo contenido propio sanitizado; cabeceras `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` vía middleware.
- **CSRF**: middleware nativo de Laravel en todas las rutas web (activo por defecto).
- **Validación**: toda entrada pasa por Form Requests; nada se valida en el controlador "a mano".
- **Mass assignment**: `$fillable` estricto en todos los modelos.

### Secretos y certificados
- `.env` fuera de control de versiones; credenciales MH (usuario API, contraseña del certificado) **solo** en `.env`.
- Certificado `.crt` en `storage/app/certificados/` con permisos de archivo restringidos al usuario del servicio; nunca en repositorio.
- Campos sensibles en BD (ej. token MH) con cast `encrypted` de Laravel.
- Ambientes pruebas/producción separados por configuración: imposible enviar a producción con credenciales de prueba (validación cruzada `ambiente` ↔ URL ↔ credenciales al arrancar).

### Red e infraestructura
- Servidor sin puertos entrantes desde internet (sección 1.3, Tailscale/WireGuard).
- HTTPS interno obligatorio.
- MySQL escuchando solo en localhost.
- Firmador MH (:8113) accesible solo desde localhost.
- Firewall de Windows: denegar entrante por defecto, permitir solo la interfaz VPN.
- **Nota para producción**: Laragon está bien para desarrollo; para producción real se recomienda un servidor Linux (Ubuntu LTS) o, si se mantiene Windows, configurar Apache/PHP como servicios con cuentas de mínimo privilegio, y el scheduler/cola de Laravel como servicios de Windows (NSSM o Tareas Programadas).

### Auditoría
- `spatie/activitylog`: create/update/delete en User, Cliente, Producto, configuración, con valores antes/después, usuario e IP.
- `dte_estado_historial` + `dte_transmisiones`: trazabilidad fiscal específica e inmutable (sin UPDATE/DELETE — solo INSERT).
- Pantalla de auditoría para admin/contador con filtros por usuario, modelo, fecha.
- Logs de aplicación con rotación diaria; los errores de transmisión a MH generan notificación (correo al admin).

### Respaldos (módulo 9)
- `spatie/laravel-backup` programado por scheduler:
  - **BD**: dump diario completo + cada 4 horas en horario laboral (los DTEs son irrecuperables de otra forma).
  - **Archivos**: `storage/app/dte`, `pdf` y certificados — incluidos en el backup de archivos diario.
- Retención: 7 diarios, 4 semanales, 12 mensuales (configurable).
- Destinos: disco local distinto al del sistema **y** nube (S3, Backblaze B2 o Google Drive vía rclone). Regla 3-2-1: 3 copias, 2 medios, 1 fuera del sitio.
- Backups cifrados (zip con contraseña que soporta el paquete) antes de subir a nube.
- Notificación por correo si un backup falla.
- **Prueba de restauración trimestral** documentada (un backup no probado no es backup).

---

## 13. Plan de desarrollo por fases

### Fase 0 — Preparación (sin código de negocio)
- Tramitar con el MH: acceso al ambiente de **pruebas**, usuario API, certificado de firma. *(Esto toma tiempo: iniciarlo YA, en paralelo al desarrollo.)*
- Descargar: JSON Schemas oficiales, catálogos, manual del contribuyente DTE, firmador `svfe-api-firmador`.
- Instalar Laravel 12, MySQL, configurar repositorio git, entornos `.env`.

### Fase 1 — Fundaciones (núcleo no fiscal)
- Migraciones base + seeders de catálogos MH.
- Autenticación, roles/permisos, políticas, auditoría general, rate limiting.
- CRUD empresa/establecimientos/puntos de venta/correlativos.
- CRUD clientes (nacional/exportación) con validaciones fiscales (NIT, NRC, DUI con dígito verificador).
- CRUD productos.
- Configuración de backups funcionando desde el día 1.
- **Entregable: sistema con login, usuarios, clientes y productos operativos.**

### Fase 2 — Motor DTE en ambiente de pruebas (la fase crítica)
- Modelo Dte + detalles + state machine + historial.
- CorrelativoService transaccional.
- Builders FC (01) y CCF (03) + DteValidatorService contra schemas.
- Integración firmador local + HaciendaApiService (auth, recepción) contra **ambiente 00**.
- Job de transmisión con reintentos; registro de transmisiones.
- **Entregable: CCF y Factura aceptados por Hacienda en pruebas.**
- *(Nota: el MH exige un proceso de certificación/pruebas declaradas antes de autorizar producción — esta fase produce justamente esa evidencia.)*

### Fase 3 — Representación gráfica, correo, NC e invalidación
- DtePdfService con QR y plantillas por tipo.
- DteEmailService + historial de envíos.
- Nota de crédito (05) con relación al CCF y control de saldos.
- Flujo completo de invalidación.
- **Entregable: ciclo de vida completo del documento en pruebas.**

### Fase 4 — Exportación, contingencia y reportes
- Factura de exportación (11) con catálogos FEX.
- Flujo de contingencia (emisión offline + evento de contingencia + transmisión diferida).
- Reportes: libro de ventas contribuyente/consumidor, consulta avanzada, exportación CSV/Excel para el contador.
- Endurecimiento: revisión de seguridad, pruebas de los flujos con usuarios reales.

### Fase 5 — Paso a producción
- Certificación ante el MH (pruebas declaradas) y obtención de credenciales de producción.
- Despliegue en servidor definitivo + VPN configurada en los dispositivos.
- Migración de correlativos/numeración inicial, carga real de clientes y productos.
- Período de marcha blanca con doble verificación de los primeros documentos.

### Fases futuras (después de estabilizar facturación)
- **Inventario**: tablas de stock y movimientos; listeners sobre `DteAceptado` / `NotaCreditoAceptada`; activar `maneja_inventario` por producto.
- **Prontos pagos**: búsqueda de CCF, asociación de orden de compra y albarán, generación de formato, historial por cliente. Se apoya en datos que `dtes` ya guarda.
- Otros tipos DTE si el negocio los necesita: Nota de Débito (06), Sujeto Excluido (14), Comprobante de Retención (07).

---

## 14. Qué NO desarrollar todavía

| No hacer ahora | Por qué |
|---|---|
| **Inventario** (tablas de stock, kardex, movimientos) | Solo los ganchos: flag en producto + eventos de dominio. Desarrollarlo ahora duplica el riesgo de la fase crítica (Hacienda) |
| **Prontos pagos** | Depende de tener CCFs reales fluyendo; el diseño de `dtes` ya lo soporta |
| **API REST pública / app móvil** | Sin necesidad actual; cada endpoint expuesto es superficie de ataque. El monolito Blade dentro de la VPN cubre el caso de uso |
| **Multiempresa / multi-tenant** | Es una empresa. La tabla `empresas` existe por si acaso, pero nada de lógica multi-tenant |
| **Nota de Débito (06), Retención (07), Sujeto Excluido (14), Donación (15)** | Agregar builders después es barato gracias al diseño por builders; certificar 4 tipos (01, 03, 05, 11) ya es suficiente trabajo |
| **Dashboard con gráficas / BI** | Primero los datos correctos; las gráficas son triviales después |
| **Microservicios / Docker swarm / Redis cluster** | Sobre-ingeniería para el volumen de una empresa; el monolito con colas en BD escala de sobra |
| **Editor de plantillas PDF configurable** | Una plantilla Blade fija por tipo de documento es suficiente y mantenible |
| **Firma propia en PHP** (reimplementar el firmador) | El MH provee su firmador oficial; reimplementarlo es riesgo de certificación |
| **Borrado/edición de DTEs emitidos** | Jamás. Es un requisito legal, no técnico: solo invalidación y notas |

---

## Apéndice A — Referencias de tipos de DTE (catálogo CAT-002 MH)

| Código | Documento | Versión esquema | En alcance |
|---|---|---|---|
| 01 | Factura (consumidor final) | 1 | ✅ Fase 2 |
| 03 | Comprobante de Crédito Fiscal | 3 | ✅ Fase 2 |
| 05 | Nota de Crédito | 3 | ✅ Fase 3 |
| 06 | Nota de Débito | 3 | Futuro |
| 07 | Comprobante de Retención | 1 | Futuro |
| 11 | Factura de Exportación | 1 | ✅ Fase 4 |
| 14 | Factura de Sujeto Excluido | 1 | Futuro |

## Apéndice B — Checklist de trámites ante el MH (iniciar de inmediato)

1. Estar registrado en el portal de servicios del MH con usuario y NIT de la empresa.
2. Solicitar acceso al ambiente de pruebas DTE (portal de facturación electrónica).
3. Generar/descargar el certificado de firma electrónica del MH y su contraseña.
4. Obtener credenciales de API (usuario = NIT, contraseña de API distinta a la del certificado).
5. Descargar firmador oficial, esquemas JSON y catálogos vigentes.
6. Al terminar pruebas: presentar la declaración de pruebas para habilitar producción.

> ⚠️ Verificar versiones vigentes de esquemas y plazos de invalidación/transmisión en la normativa actual del MH al iniciar la Fase 2 — cambian con el tiempo y deben confirmarse contra la documentación oficial del portal de facturación.
