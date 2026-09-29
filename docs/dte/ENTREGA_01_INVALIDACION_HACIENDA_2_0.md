# Entrega 01: invalidación Hacienda 2.0 — reglas de sustitución y dependencia

Fecha: 20 de septiembre de 2026. Implementación de Claude sobre el encargo
`docs/dte/CLAUDE_01_INVALIDACION_HACIENDA_2_0.md`, para revisión de Codex.

**Sin commit, sin push, sin despliegue y sin transmisiones reales.** Toda la red de las
pruebas va con `Http::fake` sobre base SQLite en memoria y disco `Storage::fake`. Las
modificaciones locales ajenas —`firmware/asistencia/asistencia.ino` incluido— quedaron
intactas.

**Esto NO cierra la invalidación 2.0.** Los plazos siguen pendientes; ver la sección
«Pendiente separado» al final.

---

## 1. Qué cambió, en una frase

La regla de qué exige el evento de invalidación dejó de ser una propiedad del MOTIVO y
pasó a ser una política de DOMINIO que recibe el documento **y** el motivo; el documento
sustituto pasó a verificarse de verdad contra Hacienda-lo-que-este-sistema-sabe en vez de
aceptarse por tener forma de UUID; y la nota de crédito relacionada dejó de ser un riesgo
confirmable para ser una prohibición.

## 2. Comportamiento antes y después

| Caso | Antes | Después |
|---|---|---|
| FE/CCF/FEX motivo 1 | exigía sustituto | exige sustituto **verificado** |
| FE/CCF/FEX motivo 3 | **prohibía** sustituto | **exige** sustituto verificado + motivo en texto |
| NC 05 motivo 1 | **exigía** sustituto | lleva `codigoGeneracionR` null; un sustituto enviado se rechaza |
| NC 05 motivo 3 | prohibía sustituto | sigue en null, y exige motivo en texto |
| Cualquier tipo, motivo 2 | null | null (sin cambio) |
| Tipo sin regla (ND 06) | heredaba la regla general del motivo | se rechaza con explicación explícita; no hereda la del CCF |
| Sustituto escrito a mano | bastaba un UUID v4 con formato válido | debe existir aquí, mismo tipo, mismo emisor (NIT), mismo ambiente, aceptado realmente por el MH y no invalidado |
| Buscador de sustitutos | cualquier documento aceptado del mismo ambiente | solo los que el servidor aceptaría (mismo tipo, mismo emisor, mismo ambiente) |
| Cliente del sustituto | solo se priorizaba | sigue solo priorizándose: **no** es requisito (una corrección puede cambiar al receptor) |
| CCF con NC aceptada vigente | bloqueaba, pero `--confirmo-nc-relacionada` / la casilla lo abrían | **bloqueado sin excepción**; el flag se acepta, avisa que está obsoleto y no hace nada |
| NC en borrador / rechazada / aceptada MOCK / ya invalidada | contaban como «NC relacionada» (salvo borrador) | no activan la prohibición: no son correcciones fiscales vivas |
| NC aceptada real y **archivada** | contaba | **sigue contando**: archivar no es invalidar |
| Requisitos en la UI | banderas del enum, iguales para todo documento | el servidor los resuelve por documento; Alpine solo los pinta |

## 3. Archivos

### Nuevos

| Archivo | Papel |
|---|---|
| `app/Support/Dte/PoliticaInvalidacion.php` | La matriz documento × motivo. Única fuente. |
| `app/Support/Dte/RequisitosInvalidacion.php` | Resultado de la política (objeto de valor). |
| `app/Support/Dte/EmisorDte.php` | Identidad del emisor por NIT, no por fila de `empresas`. |
| `app/Services/Dte/VerificadorDocumentoReemplazo.php` | Verificación dura del sustituto. |
| `app/Services/Dte/ValidadorReglasInvalidacion.php` | Orquesta matriz + sustituto + dependencias fiscales. |
| `app/Console/Commands/Concerns/AvisaOpcionNcRelacionadaObsoleta.php` | Compatibilidad del flag retirado. |
| `tests/Feature/Dte/MatrizInvalidacionHaciendaTest.php` | La matriz completa y la paridad entre vías. |

### Modificados

`app/Enums/TipoAnulacionMh.php` · `app/Support/Dte/OpcionesInvalidacion.php` ·
`app/DataTransferObjects/Dte/Salida/EventoInvalidacionData.php` ·
`app/Services/Dte/Serializadores/SerializadorInvalidacionMh.php` ·
`app/Services/Dte/BusquedaDocumentoReemplazo.php` ·
`app/Services/Dte/DteInvalidacionService.php` ·
`app/Services/Dte/DteInvalidacionMockService.php` ·
`app/Http/Requests/Dte/TransmitirInvalidacionRequest.php` ·
`app/Http/Controllers/Facturacion/DteController.php` · `app/Models/Dte.php` ·
`app/Services/Rutas/LocalizadorNotaCredito.php` (solo un `@see`) ·
los cuatro comandos `dte:invalidacion-*` ·
`resources/views/components/dte/invalidacion-oficial.blade.php`.

Pruebas actualizadas: `SerializadorInvalidacionMhTest`, `DteInvalidacionUiTest`,
`DteInvalidacionProduccionTest`, `DteInvalidacionNcRelacionadaTest`,
`AccionesDocumentoUiTest`.

## 4. Decisiones que conviene revisar

1. **Tipo del sustituto = el mismo tipo del documento invalidado.**
   `PoliticaInvalidacion::tiposSustituto()` devuelve `[$documento]`. El manual presenta el
   sustituto dentro de la misma clase documental, y el encargo pide alinear el buscador con
   la validación. **Limitación conocida:** un error de clasificación del receptor
   (consumidor final facturado como contribuyente, o al revés) se corrige emitiendo un
   documento de OTRA clase. Si la Normativa y sus anexos confirman ese cruce, se amplía en
   ese único método. No se amplió por cuenta propia.

2. **Emisor = NIT, no `empresa_id`.** Un mismo contribuyente puede tener más de un registro
   interno; ante el MH sigue siendo un solo emisor, y `emisor.nit` es lo que viaja en el
   evento. Ver `EmisorDte`.

3. **La prohibición por notas se aplica a cualquier documento, no solo al CCF.** El manual
   la enuncia para el CCF; el modelo puede representar la relación para los demás, y
   bloquear es la opción conservadora. Si Codex prefiere restringirla literalmente al CCF,
   el cambio es de una línea en `Dte::notasFiscalesVigentes()`.

4. **Una nota aceptada solo en MOCK no bloquea.** No es un hecho fiscal: su código no
   existe en Hacienda. Se cubre explícitamente en las pruebas para que la decisión quede
   visible y no sea un descuido.

5. **`motivo` pasó de `max:1000` a `max:200`,** que es el límite de
   `invalidacion-schema-v3` para `motivo.motivoAnulacion`. Antes un texto de 201-1000
   caracteres pasaba el formulario y moría después contra el schema.

6. **El flag obsoleto se conserva, inerte.** `--confirmo-nc-relacionada` y el campo
   `confirmar_nc_relacionada` se siguen aceptando para no romper scripts, avisan que están
   obsoletos y no abren nada. La casilla desapareció de la UI.

## 5. Lo que NO se tocó

Frase `INVALIDAR DTE`, autorización por rol y `DtePolicy`, separación de ambientes,
protección de evidencia (`estaProtegidoComoEvidencia`), candados de firma/transmisión y de
endpoint oficial, idempotencia (`tieneEventoInvalidacion`), almacenamiento de respuestas en
columnas dedicadas y conservación de `sello_recepcion` / `respuesta_mh` /
`fecha_procesamiento_mh` del documento original. No se ampliaron los tipos de DTE
habilitados. No se creó ni transmitió ninguna NC automáticamente.

## 6. Matriz ejecutada y resultados reales de pruebas

### Suite completa

```
php vendor/bin/phpunit
Time: 01:00:33.534, Memory: 908.00 MB
OK, but there were issues!
Tests: 5828, Assertions: 23481, PHPUnit Deprecations: 6.
```

Cero fallos y cero errores. Las 6 deprecaciones de PHPUnit son PREEXISTENTES y ajenas a
este cambio: las diez suites de invalidación corridas juntas no producen ninguna (ver
abajo). La única que introduje —un `@dataProvider` en docblock— se convirtió a
`#[DataProvider]` antes de esta corrida.

### Suites de invalidación

```
php vendor/bin/phpunit tests/Feature/Dte/MatrizInvalidacionHaciendaTest.php   tests/Feature/Dte/DteInvalidacionNcRelacionadaTest.php   tests/Feature/Dte/DteInvalidacionUiTest.php   tests/Feature/Dte/DteInvalidacionProduccionTest.php   tests/Feature/Dte/SerializadorInvalidacionMhTest.php   tests/Feature/Dte/AccionesDocumentoUiTest.php   tests/Feature/Dte/DteInvalidacionMockTest.php   tests/Feature/Dte/DteInvalidacionRealTest.php   tests/Feature/Dte/DteInvalidacionProteccionEvidenciaTest.php   tests/Feature/Dte/InvalidacionesUiTest.php

OK (205 tests, 1015 assertions)
```

`MatrizInvalidacionHaciendaTest` aporta 69 pruebas. La matriz se ejecuta cuatro veces
sobre las doce celdas, mediante `#[DataProvider]`, con expectativas escritas a partir del
manual y no a partir de lo que devuelve el código:

| Comprobación por celda | Qué exige |
|---|---|
| `test_la_politica_declara_la_matriz_oficial` | la política declara exactamente la celda |
| `test_el_caso_valido_de_cada_celda_serializa_y_valida_contra_el_schema` | el JSON correcto, validado contra `invalidacion-schema-v3.json` |
| `test_la_ausencia_indebida_del_sustituto_se_rechaza` | falta el sustituto donde se exige → rechazo, sin JSON |
| `test_la_presencia_indebida_del_sustituto_se_rechaza` | sustituto impecable donde la matriz exige null → rechazo |
| `test_el_motivo_3_exige_texto_en_todos_los_tipos` | motivo 3 sin texto → rechazo en los cuatro tipos |

### Cobertura de las pruebas de aceptación del encargo

| # | Encargo | Dónde |
|---|---|---|
| 1 | matriz 4 tipos × 3 motivos, válidos y ausencia/presencia indebida | `MatrizInvalidacionHaciendaTest` (las cinco pruebas con `#[DataProvider]`) |
| 2 | FE/CCF/FEX motivo 3 exige sustituto y motivo; NC motivo 1 sin sustituto y null | ídem + `SerializadorInvalidacionMhTest::test_nc_motivo_1_*` + `DteInvalidacionProduccionTest::test_tipo3_*` |
| 3 | sustituto igual al original / inexistente / otro tipo, emisor, ambiente / sin sello / mock / invalidado | `test_sustitutos_invalidos_se_rechazan_antes_de_cualquier_llamada`, `test_un_sustituto_sin_sello_se_rechaza` |
| 4 | corrección del receptor: no bloquear por distinto `cliente_id` | `test_un_sustituto_con_otro_receptor_no_se_bloquea`, `AccionesDocumentoUiTest::test_el_buscador_ordena_por_cliente_pero_no_excluye_a_los_demas` |
| 5 | CCF con NC vigente bloqueado también con el viejo flag por HTTP, consola y llamada directa; archivada también bloquea | `DteInvalidacionNcRelacionadaTest::test_post_web_con_el_flag_viejo_sigue_bloqueado`, `…test_consola_real_con_el_flag_viejo_sigue_bloqueada`, `…test_el_flag_obsoleto_ya_no_existe_en_la_llamada_directa`, fixture «aceptada real ARCHIVADA» |
| 6 | notas no aceptadas o ya invalidadas no activan la prohibición | `test_solo_una_nota_aceptada_real_y_vigente_bloquea` (6 fixtures: borrador, rechazada, aceptada MOCK, aceptada real, invalidada, archivada) |
| 7 | paridad web / preview / preflight / serializador / mock / real, con POST manipulado | `test_paridad_de_rechazo_entre_web_consola_serializador_mock_y_real`, `test_paridad_de_aceptacion_y_conservacion_de_la_evidencia_original` |
| 8 | el formulario actualiza el reemplazo y no manda residuos | `test_el_asistente_limpia_lo_que_el_motivo_deja_de_pedir`, `test_un_reemplazo_vacio_se_normaliza_a_null_y_no_bloquea` |
| 9 | el JSON valida contra `invalidacion-schema-v3.json` | `test_el_caso_valido_de_cada_celda_serializa_y_valida_contra_el_schema` |
| 10 | se conserva JSON/JWS/sello original; las denegaciones no crean efectos ni llamadas | `test_paridad_de_aceptacion_y_conservacion_de_la_evidencia_original`, `test_una_denegacion_no_crea_efectos_fiscales_ni_archivos_ni_llamadas` |

### Pruebas cuya expectativa cambió

Cada cambio está justificado en el docblock del propio test, contra la matriz:

| Antes | Ahora | Motivo |
|---|---|---|
| `DteInvalidacionNcRelacionadaTest::test_confirmo_nc_relacionada_permite_pasar_el_candado` | eliminada; la reemplazan tres pruebas de que el flag NO abre nada | afirmaba justo lo que el manual prohíbe |
| `…::test_real_transmite_con_nc_relacionada_confirmada_explicitamente` | eliminada | ídem |
| `…::test_mock_persiste_con_nc_relacionada_confirmada` | `test_el_mock_no_admite_ninguna_confirmacion_de_nota` | el mock aplica la misma regla |
| `SerializadorInvalidacionMhTest::test_tipo_1_exige_documento_de_reemplazo` | `test_nc_motivo_1_no_lleva_sustituto_y_serializa_null` + `…rechaza_un_sustituto_enviado` | probaba la regla del motivo 1 sobre una NC, el único tipo donde no aplica |
| `DteInvalidacionUiTest::test_tipo_error_info_exige_codigo_de_reemplazo` | `test_nc_motivo_1_no_pide_reemplazo_y_rechaza_el_que_se_envie` | ídem (el fixture es una NC) |
| `DteInvalidacionProduccionTest::test_tipo3_con_motivo_valido_pasa` | ahora aporta sustituto; se suma `test_tipo3_sobre_ccf_sin_sustituto_falla` | el motivo 3 sobre CCF pasó de prohibir a exigir sustituto |
| `AccionesDocumentoUiTest::test_las_banderas_de_campos_condicionales_vienen_del_enum` | `…vienen_de_la_politica` + `test_la_ficha_recibe_las_banderas_del_documento_que_muestra` | el enum ya no decide; la regla depende del documento |
| `…::test_el_buscador_solo_ofrece_documentos_con_aceptacion_real_del_mismo_ambiente` | `…test_el_buscador_solo_ofrece_sustitutos_que_el_servidor_aceptaria` | el buscador se alineó con la verificación del servidor (filtra por tipo y emisor) |

### Aislamiento verificado antes de correr

`phpunit.xml` fija `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`; `Tests\TestCase::setUp()`
aborta antes de migrar si la base no es esa, y `Http::preventStrayRequests()` corre para
toda la suite. Cada prueba de invalidación usa además `Storage::fake('local')`. No se
ejecutó ninguna migración contra la base operativa ni salió una sola petición a la red.

### Sobre Pint

**No se pasó Pint.** El repositorio no tiene `pint.json` y NO está limpio bajo el preset
por defecto: archivos que este cambio no toca (`DteTransmisionService`, `DteFirmaService`,
`DtePolicy`) fallan `pint --test` igual. Pasarlo, aunque fuera solo sobre los archivos
tocados, reformatearía regiones ajenas de archivos compartidos grandes. El estilo se siguió
a mano, igual al del código circundante.

## 7. Pendiente separado: fechas y plazos

**No se implementó ni se reinterpretó nada de plazos, y la invalidación 2.0 NO queda
cerrada.**

La tabla de la página impresa 11 y los ejemplos de la 12 indican décimo día hábil del mes
siguiente para CCF/NC, mientras que un párrafo de la página 12 dice días posteriores a la
transmisión. Hace falta cotejo con la Normativa de Cumplimiento y sus anexos, o una
aclaración oficial, además del calendario de días hábiles aplicable. FE/FEX distinguen tres
meses desde la recepción y tienen además su propia ventana para la fecha del evento
respecto de la generación; tres meses **no** son noventa días.

Queda también para el cierre fiscal el uso de los datos históricos del JSON original en
lugar del cliente actual al construir el bloque `documento` del evento: hoy se lee el modelo
`Cliente` vivo, de modo que un receptor editado después de emitir viajaría con sus datos
nuevos. Está fuera del alcance de esta entrega y no se amplió en silencio.

## 8. Qué NO acredita esta entrega

Ninguna prueba local acredita autorización ni aceptación oficial. Todo se ejerció contra
HTTP simulado: el evento **no** se ha transmitido a Hacienda con estas reglas, ni en
apitest ni en producción. La aceptación real por tipo y motivo sigue sin probarse contra el
MH.
