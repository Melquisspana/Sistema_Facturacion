# Transmisión DTE a Hacienda — guía (extraída del Manual Técnico v2)

> Estado: **PREPARADO pero DESHABILITADO** (`DTE_TRANSMISION_ENABLED=false`).
> **No se transmite nada a Hacienda.** Firmar/transmitir local NO es emitir: el DTE
> queda emitido solo tras **transmisión + sello de recepción** (y el PDF definitivo
> para entrega al receptor).
>
> Fuente: *Manual Técnico para la Integración Tecnológica del Sistema de Transmisión v2*
> (`docs/referencias/`). Los servicios son **REST**; todo en **UTF-8**, **TLS 1.2+**.

## Hito — primera transmisión real a apitest (30-jun-2026)

> **Punto estable.** Se validó de extremo a extremo la transmisión REAL contra el
> ambiente de pruebas del MH (`apitest.dtes.mh.gob.sv`) y luego se **restauró el modo
> seguro**. No se transmitió nada a producción.

- **DTE `id=71`** (CCF, cliente Calleja S.A. de C.V., total $42.78) **ACEPTADO por apitest**.
- **Sello de recepción real:** `2026D04751ECD4324D039CEAFD9A3516AAA7JESF`.
- **`respuesta_mh` guardada** (BD + `storage/app/dte/respuestas/…json`): `estado=PROCESADO`,
  `codigoMsg=001`, `descripcionMsg=RECIBIDO`, `fhProcesamiento=30/06/2026 22:07:54`.
  `Dte::aceptadoRealmentePorMh()` = **true** (sello real, no `MOCK`).
- **Flags restaurados a modo seguro** tras la prueba: `DTE_FIRMADOR_MOCK=true`,
  `MH_MOCK=true`, `DTE_TRANSMISION_TEST_ENABLED=false`. **Producción sigue bloqueada**
  (`DTE_TRANSMISION_ALLOW_PRODUCTION=false`, `DTE_TRANSMISION_REAL_CONFIRMATION=false`,
  modo `paralelo`).
- **PPQ y correos intactos**: `dte:transmitir` no envía correo; no se disparó envío automático.

**Procedimiento seguro usado** (repetible): diagnóstico read-only → `dte:auth-test` +
`dte:firma-post-test` → verificar certificado real (POST directo al firmador, sin persistir)
→ `dte:firmar {id}` con `DTE_FIRMADOR_MOCK=false` puntual (restaurar) → `dte:transmitir-dry-run {id}`
→ abrir los 3 flags (`DTE_FIRMADOR_MOCK`/`MH_MOCK`/`DTE_TRANSMISION_TEST_ENABLED`) →
`config:clear` → `dte:transmitir {id}` → **restaurar los 3 flags + `config:clear`**. Cada
cambio de flag requiere `config:clear` para tomar efecto. Los cambios persistentes reales
quedan solo en el DTE #71 (firma + aceptación).

## 0. Botón manual "Firmar y transmitir" — MODO MOCK (punto estable)

> Estado: **operativo SOLO en modo MOCK** (firma y aceptación **simuladas**).
> **NO es válido ante Hacienda**: no firma con el firmador real ni transmite a la red.

Existe en la vista del documento ([`facturacion/show`](../resources/views/facturacion/show.blade.php))
una acción **manual** "Firmar y transmitir" para gestores (`administrador`/`facturación`).
**No es automática**: nunca se firma/transmite al generar el CCF; el usuario la dispara a mano.

- **Ruta:** `POST facturacion/{dte}/firmar-transmitir` (`facturacion.firmar-transmitir`).
- **Controlador:** `DteController::firmarTransmitir()` — orquesta, idempotente, reusa los servicios:
  1. asegura el JSON oficial (lo genera si falta; **no consume correlativo nuevo**),
  2. firma **solo si está `Generado`** (`DteFirmaService::firmar()`); si ya está `Firmado`, salta,
  3. transmite (`DteTransmisionService::transmitir()`) y traduce el resultado.
- **Policy:** `DtePolicy::firmarTransmitir()` — gestores, estado `Generado`/`Firmado`, sin sello, no anulado.
- **Idempotencia:** no re-firma si ya hay JWS, no retransmite si ya hay sello / está `Aceptado`; el
  botón **desaparece** una vez aceptado.
- **Correos:** la acción **NO** envía correo automático; el envío al cliente sigue **manual**.

### Modo MOCK (config actual)

| Variable | Valor | Efecto |
|----------|-------|--------|
| `DTE_FIRMADOR_MOCK` | `true` | firma simulada → JWS ficticio `*.mock.jws` |
| `MH_MOCK` | `true` | aceptación simulada: sello `MOCK-SIMULADO-…` + `fhProcesamiento`=`now()` |
| `DTE_TRANSMISION_TEST_ENABLED` | `false` | cierra la vía apitest real (defensa en profundidad) |
| `DTE_TRANSMISION_ALLOW_PRODUCTION` | `false` | producción bloqueada |
| `DTE_SISTEMA_ACTUAL_ACTIVO` | `true` | el sistema actual sigue siendo el oficial |

En la UI, un documento aceptado en mock se rotula **"Aceptado simulado (MOCK)"** con el aviso
**"MODO PRUEBA / MOCK — NO VÁLIDO ANTE HACIENDA"** (se detecta por el prefijo `MOCK` del sello),
para no confundirlo con una aceptación real del MH.

### Paso a real (pendiente de confirmación explícita del usuario)

Apagar `DTE_FIRMADOR_MOCK`/`MH_MOCK`, **levantar el firmador local** (apagado por defecto), y abrir
la transmisión según §7.b. **No hacer sin confirmación**; producción sigue bloqueada.

## 1. Flujo futuro de transmisión

1. **Autenticarse** una vez al día (o según el modelo de facturación) contra el
   servicio de autenticación → se obtiene un **token** (JWT, ya con prefijo `Bearer`).
   - Vigencia del token: **pruebas 48 h, producción 24 h**.
2. Con el DTE ya **firmado** (JWS), hacer **POST** al servicio de **recepción uno-a-uno**
   con el token en el header `Authorization`.
3. Leer la respuesta: `estado` = `PROCESADO` (aceptado, con `selloRecibido`) o
   `RECHAZADO` (sin sello). Pueden venir `observaciones` que no impiden la recepción.
4. **Persistir el `selloRecibido`** en el DTE e incorporarlo al JSON como
   `selloRecibido` (paso de la fase real; hoy NO se persiste).
5. Política de reintentos: si recepción no responde en ~8 s → consultar estado del
   documento; si no fue recibido, reenviar (máximo 2 veces); si falla, contingencia.

## 2. Endpoints (TEST / PROD)

| Servicio | Método | TEST | PROD |
|----------|--------|------|------|
| Autenticación | POST | `https://apitest.dtes.mh.gob.sv/seguridad/auth` | `https://api.dtes.mh.gob.sv/seguridad/auth` |
| Recepción uno-a-uno | POST | `https://apitest.dtes.mh.gob.sv/fesv/recepciondte` | `https://api.dtes.mh.gob.sv/fesv/recepciondte` |
| Recepción por lote | POST | `.../fesv/recepcionlote` | `.../fesv/recepcionlote` |
| Consulta DTE | POST | `.../fesv/recepcion/consultadte` | idem prod |
| Consulta lote | GET | `.../fesv/recepcion/consultadtelote/{codigoLote}` | idem prod |
| Contingencia | POST | `.../fesv/contingencia` | idem prod |
| Invalidación/anulación | POST | `.../fesv/anulardte` | idem prod |

> **Sin barra final** (trailing slash): el manual exige la URL exacta (ej. `.../fesv/recepciondte`).

## 3. Autenticación (4.1)

- **Headers:** `content-Type: application/x-www-form-urlencoded`, `User-Agent`.
- **Body (form-urlencoded):** `user`, `pwd`.
- **Respuesta OK (200):**
  ```json
  { "status": "OK", "body": { "user": "...", "token": "Bearer eyJ...", "tokenType": "Bearer",
    "rol": { "...": "..." }, "roles": ["ROLE_USER"] } }
  ```
  El token a usar es `body.token` (ya incluye `Bearer`).
- **Error:** `{ "status": "ERROR", "error": "Unauthorized", "message": "Usuario no valido" }`
  (códigos 100–111: usuario incorrecto, credenciales inválidas, token inválido/expirado, etc.).

### Prueba controlada de autenticación real en ambiente testing

Para validar **solo el login/token** contra el ambiente de pruebas, **sin transmitir
ningún DTE**, existe el candado `DTE_AUTH_TEST_REAL_ENABLED` (default `false`):

- `php artisan dte:auth-test` hace login real **solo si TODOS** estos candados están OK:
  `DTE_AUTH_TEST_REAL_ENABLED=true`, `DTE_TRANSMISION_AMBIENTE=testing`, la URL contiene
  `apitest.dtes.mh.gob.sv`, y `DTE_TRANSMISION_USER`/`DTE_TRANSMISION_PASSWORD` configurados.
- Si el flag es `false` (o el ambiente es producción, o la URL no es apitest, o faltan
  credenciales) → **no hace HTTP**.
- El token, si se obtiene, vive **solo en Cache** (TTL testing) y **nunca se imprime**;
  el comando muestra solo "token obtenido: sí/no".
- **Aunque `dte:auth-test` funcione, `dte:transmitir` sigue bloqueado** porque
  `DTE_TRANSMISION_ENABLED=false` y `DTE_MODO_OPERACION=paralelo`. **No se hace POST a
  `/fesv/recepciondte`, no se guarda sello, no se cambia estado.**

`.env` esperado: `DTE_AUTH_TEST_REAL_ENABLED=false` (más las variables de §7).

### Implementación (preparada/bloqueada): `DteTransmisionAuthService`

- `obtenerToken()`: con `DTE_TRANSMISION_ENABLED=false` lanza excepción **antes de
  cualquier HTTP** (no autentica). Habilitado, hace el login (form-urlencoded user/pwd),
  valida `status=OK` y `body.token`, y normaliza el prefijo `Bearer`.
- **Cache del token** (Cache de Laravel, no base de datos) con TTL **47 h pruebas /
  23 h producción** (por debajo de la vigencia oficial de **48 h / 24 h**).
- URL de auth construida según ambiente: `apitest.dtes.mh.gob.sv` (testing) /
  `api.dtes.mh.gob.sv` (producción), o `DTE_TRANSMISION_URL` si se define.
- **Nunca** imprime ni loguea usuario, contraseña ni token.
- Comandos: `php artisan dte:auth-check` (diagnóstico, sin secretos) y
  `php artisan dte:auth-test` (bloqueado si deshabilitado; nunca muestra el token).

## 4. Recepción uno-a-uno (4.2.1)

- **Headers:** `Authorization: <token>` (el del login, con `Bearer`), `User-Agent`,
  `content-Type: application/JSON`.
- **Body:**

  | Campo | Tipo | Comentario |
  |-------|------|------------|
  | `ambiente` | String | `00` prueba / `01` producción |
  | `idEnvio` | Integer | correlativo a discreción (uno-a-uno) |
  | `version` | Integer | = versión de identificación del DTE |
  | `tipoDte` | String | = tipo del DTE |
  | `documento` | String | DTE **firmado** (JWS) |
  | `codigoGeneracion` | String | UUID v4 |

  > `numeroControl` **NO** va en el body de recepción (viaja dentro del JWS firmado).

- **Respuesta OK (200), sin/ con observaciones:**
  ```json
  { "version": 2, "ambiente": "00", "versionApp": 2, "estado": "PROCESADO",
    "codigoGeneracion": "FF84E5DB-...", "selloRecibido": "20219E9D...",
    "fhProcesamiento": "12/02/2026 13:29:04", "clasificaMsg": "10",
    "codigoMsg": "001", "descripcionMsg": "RECIBIDO", "observaciones": ["",""] }
  ```
- **Rechazo (HTTP 400):** `estado: "RECHAZADO"`, `selloRecibido: null`,
  `codigoMsg/descripcionMsg` con el error, `observaciones`.
- **Clasificación:** se interpreta por `estado` (`PROCESADO`=aceptado, `RECHAZADO`=rechazado)
  **aunque el HTTP sea 400**. Códigos de mensaje: `1` RECIBIDO, `2` RECIBIDO CON
  OBSERVACIONES, `3`–`34` errores por campo, `94`–`116` errores generales/credenciales.

## 5. Estructura del sello de recepción

El sello es el campo **`selloRecibido`** (string alfanumérico, ej.
`2025207067DD7185424C8E000A2598A776A1PG98`). El DTE confirmado se conforma de:
**estructura de datos + `firmaElectronica` + `selloRecibido`**. En la fase real se
incorpora `selloRecibido` al JSON del DTE y se persiste en la BD.

## 6. Diferencia pruebas vs producción

- **Dominios distintos:** `apitest.dtes.mh.gob.sv` (pruebas) vs `api.dtes.mh.gob.sv`
  (producción); `ambiente` = `00` (prueba) / `01` (producción).
- **Token:** vigencia 48 h en pruebas, 24 h en producción.
- **Nunca** enviar DTE de prueba a producción (sección 6.3 del manual).

## 7. Variables `.env` necesarias (SIN valores reales)

```dotenv
DTE_TRANSMISION_ENABLED=false        # mantener en false hasta tener todo listo
DTE_TRANSMISION_AMBIENTE=testing
DTE_TRANSMISION_URL=                 # https://apitest.dtes.mh.gob.sv  (o api... en prod)
DTE_TRANSMISION_ENDPOINT_RECEPCION=  # /fesv/recepciondte
DTE_TRANSMISION_TIMEOUT=15
DTE_TRANSMISION_USER_AGENT=DulcesLaNegrita-DTE/1.0
DTE_TRANSMISION_USER=                # usuario del WS de autenticación
DTE_TRANSMISION_PASSWORD=            # contraseña (solo .env local, nunca en repo/logs)
DTE_TRANSMISION_TOKEN=               # token obtenido del login (temporal)
```

## 7.b Candados de seguridad antes de transmisión real

`transmitir()` evalúa estos candados **antes de cualquier HTTP**; si alguno aplica,
no se hace ninguna petición:

| Variable | Valor seguro | Efecto si NO está abierto |
|----------|--------------|---------------------------|
| `DTE_TRANSMISION_ENABLED` | `false` | bloquea |
| `DTE_TRANSMISION_REAL_CONFIRMATION` | `false` | bloquea (confirmación real faltante) |
| `DTE_TRANSMISION_DRY_RUN` | `true` | bloquea transmisión real (nunca HTTP real) |
| `DTE_TRANSMISION_ALLOW_PRODUCTION` | `false` | bloquea si el ambiente es producción |
| `DTE_SISTEMA_ACTUAL_ACTIVO` | `true` | el sistema actual sigue siendo el oficial |
| `DTE_MODO_OPERACION` | `paralelo` | en `paralelo` bloquea siempre la transmisión real |

### Sistema actual vs sistema nuevo — modos de operación

El **sistema actual** de facturación sigue siendo el **oficial en uso**. El **sistema
nuevo** (este) convive según `DTE_MODO_OPERACION`:

- **`paralelo`** (actual): el sistema actual factura oficialmente; el sistema nuevo solo
  **genera JSON, firma local y dry-run**. **NO transmite** (bloqueado siempre).
- **`respaldo`**: el sistema nuevo solo transmite con **confirmación manual fuerte**
  (`DTE_TRANSMISION_REAL_CONFIRMATION=true`) y revisión de correlativos; mientras el
  sistema actual siga activo, debe advertirse el riesgo de duplicar documentos.
- **`principal`**: el sistema nuevo sería el oficial. **No usar** hasta definir la
  **migración completa** (correlativos, punto de venta y ambiente coordinados).

Reglas: en `paralelo` la transmisión real está **siempre bloqueada**; en `respaldo`
está bloqueada **salvo confirmación explícita**; en `principal` se permite **solo si
todos los demás candados están OK**. Con `DTE_SISTEMA_ACTUAL_ACTIVO=true` y modo no
`principal`, la transmisión real queda bloqueada por defecto.

- **`DTE_TRANSMISION_DRY_RUN`**: interruptor de "ensayo". En `true`, ni `transmitir()` ni
  ningún comando hacen HTTP real. Para un ensayo formal usar
  `php artisan dte:transmitir-dry-run {id}` (arma el payload, no transmite).
- Diagnóstico: `php artisan dte:modo-operacion` (modo + candados) y
  `php artisan dte:preflight-real {id}` (checklist BLOQUEADO/LISTO; refleja el modo y si
  el sistema actual está en uso). Ninguno transmite ni muestra secretos.

## 8. Advertencias

- ⚠️ **NO activar `DTE_TRANSMISION_ENABLED=true`** hasta tener credenciales reales,
  el token del login y haber validado el flujo en **pruebas**.
- ⚠️ **Credenciales/token solo en `.env` local**, nunca en código, docs, logs ni repo.
- ⚠️ **El token NO se guarda en base de datos ni se imprime**: vive solo en la Cache de
  Laravel con TTL (47 h pruebas / 23 h producción) y se renueva con el login.
- ⚠️ **Correlativos y sistema actual:** **no se debe transmitir desde dos sistemas sin
  coordinar correlativos, punto de venta y ambiente.** El `idEnvio` (correlativo a
  discreción) y la numeración oficial del DTE deben gestionarse SIN reutilizar ni chocar
  con el **sistema actual** de facturación; **no tocar la numeración existente**. Un
  `idEnvio`/correlativo repetido o fuera de orden produce rechazo (códigos 4 y 19 del manual).
- ⚠️ **No usar producción** hasta definir la migración (modo `principal`); por ahora el
  sistema nuevo opera en **modo paralelo seguro**.
- ⚠️ Firmar/transmitir local **no** equivale a emitir: el DTE solo queda emitido con el
  **sello de recepción** del MH.

## 9. Estado del código vs manual

`DteTransmisionService` ya está alineado con el manual en estructura (sin transmitir):
`prepararPayloadRecepcion()` arma `ambiente/idEnvio/version/tipoDte/documento/codigoGeneracion`
(sin `numeroControl`); `transmitir()` envía headers `Authorization` (con `Bearer`) +
`User-Agent` + `application/json` e interpreta la respuesta por `estado`
(aceptado/rechazado/observaciones), **sin persistir sello ni cambiar estado**.

**Pendiente para la fase real:** servicio de **autenticación** (login → token),
**persistir `selloRecibido`** y el cambio de estado en transacción cuando
`resultado=aceptado`, política de reintentos y manejo de contingencia/lote.
