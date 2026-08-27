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

## Hito — primera Nota de Crédito real a apitest (30-jun-2026)

> **Punto estable.** Primera **Nota de Crédito (tipo 05)** propia transmitida y **ACEPTADA
> realmente por apitest**, referenciando el CCF #71 ya aceptado. Modo seguro restaurado
> al terminar. No se transmitió nada a producción.

- **DTE `id=74`** (Nota de Crédito tipo 05, devolución parcial: 1 × «DULCE DE MIEL»,
  total **$1.02**) **ACEPTADO por apitest**.
- **Referencia al CCF #71 aceptado**: `documentoRelacionado` apunta al `codigoGeneracion`
  real del CCF #71 (`CF903852-05A2-4881-8305-DEC18DC386C7`, tipoDocumento `03`,
  tipoGeneracion `2`). El descuento del #71 (5% global) se acredita proporcionalmente.
- **Sello de recepción real:** `2026A77BCED2A5C249999ECD1C51427B05A5ERRH`.
- **`respuesta_mh` guardada** (BD + `storage/app/dte/respuestas/…json`): `estado=PROCESADO`,
  `codigoMsg=001`, `descripcionMsg=RECIBIDO`, `ambiente=00`, `fhProcesamiento=30/06/2026 22:48:44`,
  sin observaciones. `Dte::aceptadoRealmentePorMh()` = **true** (sello real, no `MOCK`).
- **JWS real reutilizado**: firmado antes con `alg=RS512` (no se re-firmó; `DTE_FIRMADOR_MOCK`
  quedó en `true`). Se abrieron **solo** `MH_MOCK=false` + `DTE_TRANSMISION_TEST_ENABLED=true`.
- **Flags restaurados a modo seguro** tras la prueba: `MH_MOCK=true`,
  `DTE_TRANSMISION_TEST_ENABLED=false`, `DTE_FIRMADOR_MOCK=true` (`.env` verificado idéntico
  al respaldo). **Producción sigue bloqueada** (`DTE_TRANSMISION_ALLOW_PRODUCTION=false`,
  `DTE_TRANSMISION_REAL_CONFIRMATION=false`, modo `paralelo`).
- **PPQ, correos y CCF #71 intactos**: sin **envío automático de correo**
  (`correo.auto_envio=false`, 0 envíos encolados para la NC #74); el CCF #71 no se modificó
  (solo se referenció); no se tocaron totales, cliente, productos ni correlativo de la NC.
- Estructura validada contra `fe-nc-v3` y comparada con la referencia de oro
  (`comparar_nc.php`): **0 diferencias estructurales**.

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
  3. transmite **por la política oficial de reintentos** (`DteTransmisionResiliente`,
     ver §2.4) y traduce el resultado. Si el documento ya llegaba `Firmado`, consulta
     antes de enviar (Caso 2 del manual).
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
   - Vigencia del token: **24 h en los dos ambientes** (ciclo diario). Ver §2.5.
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

> **VERIFICADO contra el Manual V2.0** (revisión manual del PDF, 26-08-2026):
> autenticación, recepción uno-a-uno, **consulta individual** e invalidación
> coinciden exactamente con lo implementado. Lote, consulta de lote y contingencia
> siguen en la tabla como referencia del manual y **no** como código existente:
> pertenecen a la fase de contingencia, diferida.

### 2.1 Fuente única de las URLs

Desde la fase de endurecimiento, **ninguna URL del MH se arma fuera de**
`App\Support\Dte\EndpointsHacienda`. Antes convivían dos mecanismos —
`dte.ambientes.{00,01}.*_url` y `dte.transmision.url_base` + `endpoint_*` — y cada
consumidor repetía además su propio fallback con el host escrito a mano, en cuatro
archivos. Cambiar un endpoint y olvidarse de una copia no daba error: seguía
apuntando al sitio viejo.

Precedencia, **idéntica para auth, recepción y anulación**:

1. `dte.ambientes.{00|01}.{auth|recepcion|anulacion}_url` — URL completa. Gana sobre todo.
2. `dte.transmision.url_base` — reemplaza solo el host.
3. Host oficial incorporado (`HOST_PRUEBAS` / `HOST_PRODUCCION`).

La ruta sale de `dte.transmision.endpoint_{auth|recepcion|anulacion}`; si la clave
está vacía se usa la ruta incorporada, para que una configuración a medias no
resuelva a un host pelado (que significaba hacer POST contra la raíz del servicio).

Los métodos `*Oficial()` **ignoran toda la configuración** a propósito: son la
referencia contra la que `DteInvalidacionService` compara la URL resuelta antes de
tocar producción. Si un override apuntara a otro sitio, esa comparación es lo único
que lo detecta — y bloquea el envío en vez de redirigirlo.

Consumidores (todos pasan por ahí): `DteTransmisionAuthService`,
`DteTransmisionService`, `DteInvalidacionService`, `DteInvalidacionPreflightCommand`
y la pantalla `EstadoHaciendaApi`.

### 2.2 Credenciales de producción: sin respaldo

`DTE_PROD_USER` y `DTE_PROD_PASSWORD` son **obligatorias** para autenticar contra
producción. Si faltan, el login falla explícito **antes de cualquier HTTP**.

Antes caían de vuelta a `DTE_TRANSMISION_USER` / `DTE_TRANSMISION_PASSWORD`. El
problema no era el respaldo sino su silencio: un `DTE_PROD_USER` mal escrito no
fallaba, transmitía con la credencial vieja. Ese fallback **se eliminó**.

`DTE_TRANSMISION_USER` / `DTE_TRANSMISION_PASSWORD` siguen declaradas, pero **ya no
autentican en ningún ambiente**. Sus únicos consumidores legítimos son de
diagnóstico:

| Consumidor | Qué hace con ellas |
|---|---|
| `DteTransmisionService::authConfigurado()` | Señal del dry-run/estado técnico: «¿hay algún dato de auth?». No elige credenciales ni abre candados. |
| `DteSeguridadCheckCommand` | Informa si están puestas. No las usa. |

El diagnóstico (`dte:auth-check`, pantalla Hacienda/API) reporta la fuente:
`prod` · `testing` · `parcial` · `ninguna` · `legacy`. **`legacy` cambió de
significado**: ya no quiere decir «está usando las viejas», sino «producción NO
puede autenticar porque solo están puestas las legacy». Se pinta como **Error**,
no como advertencia.

`DTE_TEST_USER` / `DTE_TEST_PASSWORD` nunca tuvieron respaldo y siguen igual.

### 2.3 Consulta individual (implementada)

`App\Services\Dte\DteConsultaService` — `POST .../fesv/recepcion/consultadte`.

| Parte | Valor |
|---|---|
| Body | `nitEmisor` (solo dígitos, del EMISOR del documento), `tdte` (CAT-002), `codigoGeneracion` |
| Headers | `Authorization` (Bearer), `User-Agent`, `Content-Type: application/json` |
| Candados | los mismos que la transmisión: con la integración apagada no hace ninguna petición |
| Efectos | **ninguno**: no cambia estado, no persiste, no toca la máquina de estados |

Comando: `php artisan dte:consultar {id}` — por defecto **no hace HTTP**, solo muestra
a qué URL iría y con qué cuerpo. Para preguntar de verdad, `--consultar`.

**VERIFICADO contra el Manual V2.0**: método, URL de los dos ambientes, headers y los
tres campos del cuerpo coinciden.

No existe por conveniencia: es la pieza sin la cual la política de reintentos no puede
escribirse.

### 2.4 Política de reintentos previa a contingencia (implementada)

`App\Services\Dte\DteTransmisionResiliente`.

**El objetivo no es que el DTE entre: es que no entre dos veces.** Cuando recepción no
responde, el documento pudo haber sido recibido igual —la petición se perdió de vuelta,
no de ida—. Reenviar a ciegas transmite el mismo hecho económico dos veces, con dos
códigos de generación, y eso no se deshace borrando nada: se corrige invalidando ante
Hacienda.

**Los dos casos del Manual V2.0**, verificados:

| Caso | Disparador | Implementación |
|---|---|---|
| 1 | Recepción no responde tras el umbral (8 s) | consulta dentro del bucle, tras el timeout |
| 2 | El firmador falla y no procesa la respuesta de recepción | `estadoIncierto`: consulta **obligatoria antes** del primer envío |

En esta arquitectura firma y transmisión son pasos separados —el firmador no está en el
camino de la respuesta de recepción—, así que el Caso 2 se materializa como un documento
que llega **ya firmado** de un intento anterior cuyo desenlace nadie conoce: el usuario
que vuelve a pulsar el botón. `DteController` lo detecta mirando si el documento llega en
estado `Firmado` **antes** de intentar firmarlo.

Lo que **no** es el Caso 2: que la firma falle sin llegar a producir un JWS. Ahí no hubo
envío que consultar y preguntar sería una petición inútil; ese camino lanza su excepción
de firma y no entra en la política.

**LA REGLA QUE GOBIERNA TODO: solo se reenvía cuando se ha DETERMINADO que el documento
no fue recibido.** El manual manda reenviar «si no ha sido recibido»; una consulta que
falla no demuestra eso — no demuestra nada. Y los dos errores posibles no son
simétricos: no enviar se arregla enviando más tarde, mientras que enviar dos veces deja
un duplicado emitido, con numeración oficial gastada, que solo se corrige invalidando
ante Hacienda. **Ante la duda, detenerse.**

El ciclo:

1. Enviar. (Con `estadoIncierto`, primero se consulta — Caso 2.)
2. Respuesta definitiva (aceptado / rechazado) → se aplica y termina.
3. Silencio (timeout / conexión caída) → **consultar**:
   - el MH lo tiene → esa es la respuesta buena, se aplica, **no se reenvía**;
   - el MH **no** lo tiene (determinado) → reenviar, si quedan reenvíos;
   - **no se pudo determinar** → **no se envía ni se reenvía**: `estado_recepcion_incierto`.
4. Sin reenvíos → `reintentos_agotados`.

`estado_recepcion_incierto` deja el documento **intacto** (sin sello, sin cambio de
estado, misma numeración) y **no** activa contingencia. El mensaje dice explícitamente
que no es seguro reenviar hasta poder determinar el estado. Se aplica igual en la
consulta previa y en la del bucle: un solo criterio, un solo nombre.

El resultado lo declara con dos campos, para que nadie tenga que deducirlo:
`consulta_no_disponible = true` y `consulta_resultado` con el resultado crudo de la
consulta (`error_conexion`, `error_http`, `token_invalido`, …), o `null` si la consulta
no se pudo ni formular. Porque **«consulta que falla» incluye la que no llega a
salir**: si consultar lanza una excepción de precondición, tampoco se determinó nada, y
la política se detiene igual. Lo único que se propaga hacia arriba es el candado
(`DteTransmisionDeshabilitadaException`): un candado no se degrada a resultado.

**El tope se cuenta sobre el documento, no sobre la llamada.** En el camino normal son
el envío inicial más 2 reenvíos: 3 en total. En el Caso 2, el envío inicial ya lo hizo
el intento anterior, así que cuando la consulta previa confirma que el MH **no** lo
tiene, a esa llamada le quedan **2 envíos, no 3**. Contarlo de otro modo dejaría 4
transmisiones del mismo documento repartidas entre dos pulsaciones del botón, que es
justo lo que el tope existe para impedir.

| Parámetro | Clave | Default |
|---|---|---|
| Umbral de respuesta | `dte.transmision.timeout` | 8 s |
| Reenvíos adicionales | `dte.transmision.reintentos.max_reenvios` | 2 (3 envíos en total; 2 tras un Caso 2 confirmado) |
| Interruptor | `dte.transmision.reintentos.enabled` | `true` |

**Qué NO hace**, a propósito: no activa contingencia, no crea ni transmite evento de
contingencia, y **no regenera** el código de generación ni el número de control —
regenerarlos convertiría un reintento en un documento nuevo—. Al agotarse devuelve
`reintentos_agotados` con `contingencia_requerida = true` y ahí se detiene.

Qué cuenta como "sin respuesta": **solo** `error_conexion`. Un token rechazado, un HTTP
500 o un JSON roto son respuestas —el servidor contestó—; reintentarlos no es la
política, es insistir, y con un 500 podría duplicar.

**La política NO es opcional: es la única forma de transmitir.** Tanto el botón de la UI
(`DteController::procesarFirmaTransmision`, núcleo compartido por «firmar y transmitir» y
«generar y transmitir producción») como `php artisan dte:transmitir` pasan por
`DteTransmisionResiliente`. No hay una segunda vía, y hay un test que recorre el código
para que no aparezca mañana.

`dte:transmitir {id} --estado-incierto` fuerza el Caso 2 desde consola.

### 2.5 Vigencia del token

`dte.transmision.token_vigencia_horas`, default **24 h**, igual en los dos ambientes.

**CONFIRMADO contra el Manual V2.0**: «El servicio de autorización se deberá ejecutar una
vez en el día o según sea el modelo de facturación del contribuyente». Es un ciclo diario
y no distingue ambientes. Antes estaba hardcoded en 24 h producción / **48 h pruebas**;
ese 48 no salía del manual y se eliminó. El TTL del cache se calcula siempre por debajo de la
vigencia: equivocarse por abajo cuesta un login de más, por arriba significa usar un
token muerto en mitad de una transmisión.

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
  por debajo de la vigencia configurada de **24 h** (ver §2.5).
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
- **Token:** vigencia 24 h (ciclo diario), igual en pruebas y producción.
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
- **Sin terminal:** el mismo cálculo (`DteTransmisionService::estadoOperativo()`, que
  reutiliza `evaluarCandados()`) se ve en pantalla en dos lugares:
  - **Franja del navbar** (administrador/facturación, toda pantalla): badge
    `PARALELO SEGURO` (verde) / `RESPALDO|PRINCIPAL BLOQUEADO` (ámbar) /
    `RESPALDO|PRINCIPAL LISTO` (rojo, parpadeante — transmisión real posible AHORA) +
    chip `PRUEBAS / MOCK` si firma/transmisión/invalidación están en modo simulado.
  - **Panel "Salud del sistema" → "Transmisión DTE"** (solo administrador): mismo
    estado con detalle de candados (enabled/dry-run/confirmación) y de los 3 mocks.
  Ambos son de solo lectura: no transmiten, no firman, no muestran secretos.

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
