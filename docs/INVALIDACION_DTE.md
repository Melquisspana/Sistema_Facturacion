# Invalidación oficial de DTE (evento `/fesv/anulardte`)

Anulación **oficial** ante el Ministerio de Hacienda de un DTE **ya aceptado**
(estado `Aceptado` con sello de recepción real). NO confundir con la **anulación
interna** (`DteAnulacionService`, solo documentos `Generado`, sin evento MH). El evento
de invalidación se **firma** y se **transmite** aparte, y su evidencia vive en columnas
**dedicadas** que nunca pisan la evidencia de recepción del DTE original.

> **Caso base real:** la NC #74 (tipo 05) se invalidó **realmente** en apitest el
> 2026-07-01 (ver [Resultado real](#resultado-real-nc-74)).

## Fases

### Fase A — Serializador / schema
- Schema oficial: `resources/dte/schemas/invalidacion/invalidacion-schema-v3.json`
  (draft-07, `version: 3`, 4 bloques con `additionalProperties:false`).
- `App\Enums\TipoAnulacionMh` (CAT-024): `1` Error en la información (exige documento de
  reemplazo `codigoGeneracionR`), `2` Rescindir la operación, `3` Otro (exige motivo texto).
- `App\Services\Dte\Serializadores\SerializadorInvalidacionMh::serializar(Dte, EventoInvalidacionData)`
  → array del evento (`identificacion` / `emisor` / `documento` / `motivo`).
  - `identificacion.codigoGeneracion` = **UUID nuevo** del evento (≠ al del DTE).
  - **`identificacion.fecEmi` = fecha de emisión del DTE** (ver [Error aprendido](#error-aprendido-rechazo-027)).
  - `documento.*` con datos **reales** del DTE aceptado (sello, número de control, código
    de generación, fecha, receptor).
- Validación: `DteSchemaValidator::validarInvalidacion(array)`.

### Fase B — Preview / mock (sin firmar ni transmitir)
- `php artisan dte:invalidacion-preview {dte} --tipo=N` — serializa y valida el evento;
  con `--guardar` escribe un JSON de inspección en `dte/invalidacion/preview/`.

### Fase C — Persistencia mock (firma simulada, sin transmitir)
- `App\Services\Dte\DteInvalidacionMockService::firmarMock(...)` — firma **mock** (JWS
  ficticio marcado `MOCK-SIN-FIRMA-REAL`), valida y persiste **solo** columnas nuevas.
  NO transmite, NO cambia estado, NO toca evidencia de recepción.
- `php artisan dte:invalidacion-mock {dte} --tipo=N [--guardar] [--confirmar]`
  (sin `--guardar` = dry-run; requiere `DTE_INVALIDACION_MOCK=true` o `--confirmar`).

### Fase D — Transmisión real (apitest)
- `App\Services\Dte\DteInvalidacionService` — serializa → valida → **firma real**
  (`DteFirmaService::firmarJson`, POST al firmador) → **token** de recepción
  (`DteTransmisionAuthService::obtenerToken`) → **POST** a `/fesv/anulardte`
  `{ ambiente, idEnvio, version, documento: <JWS> }` → interpreta → persiste.
  - **Solo si el MH acepta** (`estado = PROCESADO`): transiciona `Aceptado → Invalidado`
    y guarda `sello_invalidacion`.
  - **Rechazado:** guarda `respuesta_mh_invalidacion` (motivo) **sin** sello y **sin**
    cambiar estado → se puede **reintentar**.
- `php artisan dte:invalidacion-real {dte} --tipo=N [--dry-run] --transmitir-real --confirmo-invalidar`
  - Sin `--transmitir-real --confirmo-invalidar` → **dry-run** (muestra endpoint, cuerpo,
    schema y candados, sin firmar ni transmitir).
  - Si el DTE **ya está invalidado**, muestra un aviso amable y **no** intenta nada.

### Preflight y debug (solo lectura)
- `php artisan dte:invalidacion-preflight {dte} --tipo=N` — checklist completo antes del
  intento real (estado, aceptación real, sello null, endpoint apitest, schema, tipo
  explícito, responsable/solicitante, firmador, token, flags). No firma ni transmite.
- `php artisan dte:invalidacion-debug {dte}` — inspección: último JSON, **JWS decodificado**
  (con check `identificacion.fecEmi == documento.fecEmi`), última respuesta MH, intentos
  en disco y si el próximo intento regenera o está bloqueado. Solo lectura.

## Variables `.env`

```env
# Fase C (mock): firma simulada + persistencia, sin transmitir.
DTE_INVALIDACION_MOCK=false
# Fase D (real): candado de la transmisión real a apitest.
DTE_INVALIDACION_REAL_CONFIRMATION=true
# Endpoint de anulación (SIN barra final). Pruebas = apitest.
DTE_TEST_ANULACION_URL=https://apitest.dtes.mh.gob.sv/fesv/anulardte
DTE_INVALIDACION_VERSION=3
# Overrides SOLO si el MH confirma que difieren de los internos M001/P001.
DTE_INVALIDACION_COD_ESTABLE_MH=
DTE_INVALIDACION_COD_PUNTO_VENTA_MH=
# Responsable (quien realiza el evento) y solicitante (quien lo pide). OBLIGATORIOS
# ante el MH. Datos REALES (no de ejemplo). tipo_doc CAT-022 (36=NIT, 13=DUI).
DTE_INVALIDACION_RESP_NOMBRE=
DTE_INVALIDACION_RESP_TIPO_DOC=
DTE_INVALIDACION_RESP_NUM_DOC=
DTE_INVALIDACION_SOL_NOMBRE=
DTE_INVALIDACION_SOL_TIPO_DOC=
DTE_INVALIDACION_SOL_NUM_DOC=
```

Para la firma/transmisión real también deben estar: `DTE_FIRMA_ENABLED=true`,
`DTE_FIRMADOR_MOCK=false`, `DTE_TRANSMISION_TEST_ENABLED=true` (token de apitest),
`DTE_TRANSMISION_USER` / `DTE_TRANSMISION_PASSWORD` y el firmador local corriendo.

## Candados (transmisión real, `DteInvalidacionService::evaluarCandados`)

| Candado | Requisito |
|---|---|
| Confirmaciones del comando | `--transmitir-real` **y** `--confirmo-invalidar` |
| Mock apagado | `DTE_INVALIDACION_MOCK=false` |
| Confirmación real | `DTE_INVALIDACION_REAL_CONFIRMATION=true` |
| Nunca producción | ambiente ≠ producción **y** endpoint `apitest.dtes.mh.gob.sv` |
| Firma real | `DTE_FIRMA_ENABLED=true` **y** `DTE_FIRMADOR_MOCK=false` |
| Datos del evento | responsable **y** solicitante completos |
| Tipo explícito | `--tipo` obligatorio (sin default) |
| DTE elegible | `aceptadoRealmentePorMh()` (sello real no-MOCK + fecha MH) |
| No duplicar | sin `sello_invalidacion` y estado ≠ `Invalidado` (`tieneEventoInvalidacion()`) |

## Columnas dedicadas (`add_invalidacion_a_dtes`)

`codigo_generacion_invalidacion`, `tipo_anulacion`, `json_invalidacion_path`,
`jws_invalidacion_path`, `sello_invalidacion`, `respuesta_mh_invalidacion`,
`respuesta_mh_invalidacion_path`, `fecha_invalidacion`, `fecha_procesamiento_invalidacion`.
**Nunca** se tocan `sello_recepcion`, `respuesta_mh` ni `fecha_procesamiento_mh` del DTE
original. Archivos en `dte/invalidacion/{json,firmados,respuestas}/`; cada intento genera
archivos nuevos (código de generación distinto) y **conserva** los previos.

## Error aprendido (rechazo 027)

El primer intento real fue **RECHAZADO**: HTTP 400, `codigoMsg 027`
`[identificacion.fecEmi] DATO NO COINCIDE CON DTE`.

Causa (confirmada decodificando el JWS realmente enviado):

| Intento | `identificacion.fecEmi` | `documento.fecEmi` | Resultado |
|---|---|---|---|
| `641929A0` (pre-fix) | `2026-07-01` (now) | `2026-06-30` | RECHAZADO 027 |
| `05F93C81` (post-fix) | `2026-06-30` | `2026-06-30` | **ACEPTADO** |

**Regla implementada:** para el evento de invalidación, `identificacion.fecEmi` debe ser
la **fecha de emisión del DTE que se invalida** (= `documento.fecEmi`), **no** `now()`.
Se corrigió en `SerializadorInvalidacionMh::identificacion()`. `horEmi` se deja como la
hora del evento (`now()`): el MH no lo rechazó; si un rechazo futuro lo exigiera, se
alinearía a `dte.hora_emision`. Test de regresión:
`SerializadorInvalidacionMhTest::test_fecemi_del_evento_coincide_con_la_fecha_del_dte_no_con_now`.

## Resultado real (NC #74)

- **Invalidada oficialmente en apitest el 2026-07-01 08:49.**
- `estado = Invalidado` · `sello_invalidacion = 20262DD5C477E6474FAA9771D46EFADE2B53JAEU`
  · `codigoMsg 001` "Invalidación Recibida y Procesada".
- `sello_recepcion` original **intacto**. Evidencia de ambos intentos (rechazo + aceptación)
  conservada en `dte/invalidacion/respuestas/`.
- Reintento **bloqueado** (`tieneEventoInvalidacion()=true`); el comando real muestra el
  aviso "Este DTE ya fue invalidado oficialmente".

## Pendientes / notas
- Datos de responsable/solicitante: deben ser **reales** en `.env` (el schema los exige).
- `codEstableMH` / `codPuntoVentaMH` se asumen = internos `M001`/`P001` (overridables por
  config); confirmar con el MH si difieren.
- `tipoAnulacion` para NC tipo 05: se usó `2` (Rescindir) explícito; el Manual Técnico del
  MH está como PDF escaneado (sin capa de texto), así que la elección se dejó **explícita**
  por parámetro y documentada, no asumida.
