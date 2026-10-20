# Matriz de validaciones Hacienda 2.0 (FE, CCF, FEX, NC)

Issue #42. Fecha: 20/10/2026. Plazo de Hacienda 2.0: 01/12/2026.

## Fuente y cómo leer la matriz

- **Normativa de Cumplimiento de los DTE, versión 2.0**, Anexo IV «Validaciones para campos DTE», pp. 102-122 (página impresa = página del PDF). El PDF es escaneado y queda fuera del repositorio. Se leyó con OCR y después página por página. El número de cada fila es el del campo en el Anexo IV; «17.2» es la regla 2 del campo 17.
- También se citan la regla 7 (numeración, redondeo y holgura, pp. 16-17), la 11.3 (representación gráfica, pp. 25-26) y el Anexo I, 4 (archivo DTE y representación, p. 43).
- Solo se listan las reglas que aplican a FE (01), CCF (03), NC (05) y FEX (11). Las de CRE, CLE, DCLE, NRE, NDE, FSEE y CDE no aplican: el sistema no emite esos tipos.

Estado de cada regla:

| Marca | Significado |
|---|---|
| **Cubierta** | Validada o construida en el código, con prueba. Se indican el archivo y la prueba. |
| **Esquema** | La exige el JSON Schema oficial, que se valida al generar (`DteSchemaValidator`, dentro de `DteJsonService::generar`; prueba `DteJsonServiceTest`). Si falla, el documento vuelve a borrador. |
| **Falta** | No está validada o el código hace otra cosa. Lleva número de hueco (H1…). |
| **No soportado** | La operación no se emite: el bloque sale siempre `null`. Ver la sección «Operaciones no soportadas». |
| **No aplica** | La regla es de un caso que este emisor no tiene. |

Versiones vigentes según el campo 1: FE = 2, FEX = 3, CCF = 4, **NC = 4**. La NC v3 ya no figura en la Normativa 2.0 (ver H1).

## Huecos

Orden de prioridad: P1 son rechazos o incumplimientos posibles antes del 01/12/2026.

| Id | Prioridad | Regla | Qué falta | Dónde |
|---|---|---|---|---|
| H1 | P1 | 1 | Para la NC la Normativa solo admite la versión 4; producción sigue en v3 por `DTE_NC_VERSION`. Va por #40, con la aceptación en apitest. | `config/dte.php` (`json.versiones.05`) |
| H2 | P1 | 160-164 | FE y FEX al contado mandan `pagos: null`, pero con condición 1 o 3 los pagos son obligatorios. El CCF a crédito manda `codigo` y `montoPago = total`, que deben ir `null` y `0.00`, con plazo y período. | `SerializadorFacturaMh`, `SerializadorExportacionMh`, `SerializadorCcfMh::pagos()` |
| H3 | P1 | 11.3, 11.3.1, Anexo I 4 | El PDF y la ficha de exportación leen el cliente y la empresa **actuales**. Si se edita el cliente después de emitir, la representación deja de corresponder al DTE transmitido. El evento de invalidación también toma el receptor del cliente actual. | `DtePdfService`, `resources/views/facturacion/pdf.blade.php`, `ReceptorExportacionPresentacion`, `SerializadorInvalidacionMh::documento()` |
| H4 | P1 | 5.3 y regla 7.1 | El correlativo del número de control debe volver a `000000000000001` con el primer DTE de cada ejercicio (1 de enero). No hay reinicio anual. | `DteGeneracionService`, modelo `Correlativo` |
| H5 | P1 | 57 | El correo del receptor solo puede ir `null` en FE y FEX. En CCF y NC el esquema lo acepta `null`, así que un cliente sin correo pasa la validación local. | `ValidacionPreJsonService::validarReceptor()` |
| H6 | P1 | 39 | En FE, el NIT o DUI del receptor va sin guiones. El cliente admite el formato con guiones y el serializador de FE lo manda tal cual; el de CCF sí lo limpia. | `SerializadorFacturaMh::receptor()` |
| H7 | P1 | 17.2 c | La NC se genera dentro de los 3 meses desde la fecha del CCF, «salvo excepciones legales». No se valida. | `DteGeneracionService`, `ValidacionPreJsonService` |
| H8 | P2 | 157 | En FE, `totalIva` = suma de `ivaItem` − IVA del descuento global. Hoy se calcula sobre el total gravado; con muchas líneas el redondeo podría pasar la holgura de ±0.01. Queda por demostrar con una prueba. | `CalculadoraDte::calcularFactura()`, `SerializadorFacturaMh::resumen()` |
| H9 | P2 | 90.13, 91.13, 101.2 | En la NC solo se controlan el saldo total y el gravado del CCF. Falta el tope por exento y por no sujeto, y que el IVA retenido acumulado de las NC no supere el del CCF. | `SaldoMontoCcf::exceso()` |
| H10 | P2 | 35-37, 166-167 | Si la FEX es de servicios (`tipoItemExpor` 2), recinto, régimen, tipo de régimen e incoterms deben ir `null`. El sistema los exige siempre. | `CrearBorradorRequest`, `ValidacionPreJsonService`, `SerializadorExportacionMh` |
| H11 | P2 | Criterio del issue | El Excel de catálogos activo es el oficial (ver «Catálogos»), pero no hay forma de comprobar que lo **importado en la base** coincida con él. | `ImportarCatalogosMhCommand` |

Fuera de este issue: la ventana de la fecha de emisión respecto de la transmisión (campo 10.2-10.4, hasta 5 días después sin cambiar de período) y la holgura de 30 minutos del último día del mes (11.2) caen en la contingencia (#43).

## Matriz por sección

### Sección 1: Identificación

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 1 | version | FE 2, FEX 3, CCF 4, NC 4 | todos | Cubierta para FE, CCF y FEX: `config/dte.php`, `MapeadorDteSalida::identificacion()`, `DteSchemaRepositoryTest`. NC: **Falta (H1)**. |
| 2 | ambiente | CAT-001: 00 pruebas, 01 producción | todos | Cubierta: `MapeadorDteSalida`; `DteGeneracionNumeroInternoAmbienteTest`, `AmbientePruebasAvisoTest`. |
| 3 | tipoDte | CAT-002 | todos | Cubierta: cada serializador; `SerializadoresMhMultiTipoTest`. |
| 4 | codigoGeneracion | UUID v4 en mayúsculas, 36 caracteres, único | todos | Cubierta: `CodigoGeneracion`; `UtilidadesJsonTest`. Esquema (patrón). |
| 5.1-5.2 | numeroControl | 31 caracteres, `DTE-tt-` + establecimiento `M/B/S/P`+3 dígitos + punto de venta `P`+3 dígitos + 15 dígitos | todos | Cubierta: `NumeroControlBuilder`; `UtilidadesJsonTest`, `NumeroControlUnicoPorAmbienteTest`. Esquema (patrón). |
| 5.3 | numeroControl | No se repite en el ejercicio; reinicia el 1 de enero | todos | **Falta (H4)**. La unicidad sí está cubierta (`NumeroControlUnicoPorAmbienteTest`). |
| 6-9 | tipoModelo, tipoOperacion, tipoContingencia, motivoContin | 1/1/null/null en normal; 2/2/CAT-005 en contingencia | todos | Cubierta para el modo normal: `MapeadorDteSalida`. La contingencia es de #43 (`ContingenciaModoTest`). |
| 10.1 | fecEmi | Formato `YYYY-MM-DD` | todos | Esquema. |
| 10.2-10.4 | fecEmi | Ventana respecto de la transmisión | todos | Fuera de este issue (#43). |
| 11.1 | horEmi | Formato `HH:MM:SS` | todos | Esquema; `FechaEmisionDteHoraLocalTest`. |
| 11.2 | horEmi | 30 minutos de holgura el último día del mes | todos | Fuera de este issue (#43). |
| 12 | tipoMoneda | USD | todos | Cubierta: `identificacionComun()`. Esquema. |
| 13 | fusion | `null` si no hay fusión | NC v4 | Cubierta: `SerializadorNotaCreditoV4Mh`; `NotaCreditoV4Test`. Fusiones: no soportado. |

### Sección 2: Documentos relacionados

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 14.1 | tipoDocumento | `null` si no hay documentos relacionados | FE, CCF, FEX | Cubierta: serializadores (`documentoRelacionado: null`). |
| 14.5, 14.9 | tipoDocumento | La NC exige 03-CCF (o 07-CR); no puede ser null | NC | Cubierta: `DteGeneracionService::validarDocumentoRelacionadoNc()`, `ValidacionPreJsonService`; `DteNotaCreditoTest`, `NcAveriaSinCcfTest`. |
| 15 | tipoGeneracion | CAT-007; obligatorio en NC | NC | Cubierta: `MapeadorDteSalida::documentoRelacionado()` (2 = electrónico). |
| 16.1 | numeroDocumento | Código de generación del electrónico | NC | Cubierta: serializadores NC (`CodigoGeneracion::esValido`); `NotaCreditoV4Test`. |
| 16.3, 80.10 | numeroDocumento | No relacionar documentos invalidados o rechazados | NC | Cubierta: `Dte::aceptadoRealmentePorMh()` en `ValidacionPreJsonService`; `DteBusquedaCcfNotaCreditoTest`. |
| 16.7 | numeroDocumento | Un CCF en varias NC sin exceder su valor | NC | Cubierta: `SaldoMontoCcf::exceso()`; `DteSaldoAcreditableArchivadoTest`. Tope por tipo de venta: **Falta (H9)**. |
| 16.8 | numeroDocumento | Mismo emisor y receptor que el relacionado | NC | Cubierta: el cliente de la NC es siempre el del CCF (`DteBorradorService::crearNotaCredito`); `DteNotaCreditoDesdeCcfTest`. Emisor único: `DteEmisorUnicoTest`. |
| 17.1, 17.3 | fechaEmision | `YYYY-MM-DD`, la del relacionado | NC | Cubierta: `MapeadorDteSalida::documentoRelacionado()`. |
| 17.2 c | fechaEmision | NC dentro de los 3 meses del CCF | NC | **Falta (H7)**. |

### Sección 3: Emisor

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 18-22 | nit, nrc, nombre | Obligatorios; NIT de 9 o 14 dígitos sin guiones; NRC sin guiones ni ceros a la izquierda | todos | Cubierta: `ValidacionPreJsonService::validarEmisor()`, `CoherenciaConfiguracionFiscal`; `ValidacionPreJsonTest`, `CoherenciaConfiguracionFiscalTest`. Esquema (patrones). |
| 23-24 | codActividad, descActividad | CAT-019; descripción del código | todos | Cubierta: `descActividad()`; `SincronizadorActividadesEconomicasTest`. |
| 26-28 | departamento, municipio, distrito | CAT-012/013/008 coherentes entre sí | todos | Cubierta: `CoherenciaUbicacion` en `ValidacionPreJsonService`; `ValidacionPreJsonTest`. |
| 29-31 | complemento, teléfono, correo | Obligatorios; teléfono de 8 a 30 caracteres | todos | Esquema. |
| 32-33 | codEstable, codPuntoVenta | Opcionales en FE, CCF y FEX; la NC no los lleva | todos | Cubierta: `SerializadorNotaCreditoV4Mh` no los manda; `NotaCreditoV4Test`. |
| 34 | tipoItemExpor | CAT-011, sin los códigos 3 y 4 | FEX | Cubierta: `TipoItemExportacion` (1 y 2); `DteExportacionCamposFexTest`. |
| 35-37 | recintoFiscal, tipoRegimen, regimen | Obligatorios si bienes; `null` si servicios | FEX | Bienes: cubierta (`CrearBorradorRequest`, `ValidacionPreJsonService`; `DatosAduanerosFexTest`). Servicios: **Falta (H10)**. |

### Sección 4: Receptor

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 38 | tipoDocumento | CAT-022; la NC que ajusta un CCF usa solo 36-NIT | FE, FEX, NC | Cubierta: `SerializadorNotaCreditoV4Mh` (fijo 36); `NotaCreditoV4Test`. |
| 38-39, 42 | tipoDocumento, numDocumento, nombre | En FE, `null` por debajo del monto legal y obligatorios por encima | FE | Cubierta: umbral en `ValidacionPreJsonService` (`dte.factura_consumidor_final`); `ValidacionPreJsonTest`. |
| 39 | numDocumento | NIT o DUI sin guiones | FE, FEX | **Falta (H6)** en FE. |
| 40 | nit | Obligatorio; 9 o 14 dígitos sin guiones; con NRC activo | CCF | Cubierta: `SerializadorCcfMh::soloDigitos()`; `SerializadorCcfMhTest`. NRC activo: lo valida el MH. |
| 41, 43-44 | nrc, codActividad, descActividad | CAT-019 en CCF y NC | CCF, NC | Cubierta: `ValidacionPreJsonService`. En FE la Normativa se contradice (43.1 dice `null`; 43.2 dice CAT-019). Se mantiene como está. |
| 47-50 | departamento, municipio, distrito, complemento | Coherentes; en FE pueden ir `null` | FE, CCF, NC | Cubierta: `validarUbicacionReceptor()`; `ValidacionPreJsonTest`. |
| 51-52, 55 | codPais, nombrePais, tipoPersona | CAT-020 y CAT-029 obligatorios | FEX | Cubierta: `SerializadorExportacionMh::receptor()`; `PresentacionCajaYReceptorFexTest`. |
| 56 | telefono | Opcional | todos | Esquema. |
| 57 | correo | Estructura válida; `null` solo en FE y FEX | todos | Estructura: esquema. CCF y NC sin correo: **Falta (H5)**. |

### Secciones 5-7: Documentos asociados y operaciones por cuenta de terceros

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 58-68 | otrosDocumentos | `null` si no hay | FE, CCF, FEX | No soportado (siempre `null`). |
| 69-71 | ventaTercero | `null` si no aplica; la NC copia el del CCF | FE, CCF, NC, FEX | No soportado (siempre `null` en CCF y NC; coherente). |
| 72 | compraTercero | `null` si no aplica | FEX | No soportado. |

### Sección 8: Cuerpo del documento

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 74 | numItem | Consecutivo desde 1; máximo 2000 | todos | Cubierta: `numero_linea` (`DteGeneracionService::reordenarLineasSegunOc`); `DteGeneracionOrdenOcTest`. Máximo: esquema. |
| 75 | tipoItem | CAT-011; en FEX solo 1 o 2 | todos | Cubierta: `TipoProducto`; los serializadores exigen el dato. El 4 no se usa (no soportado). |
| 80 | numeroDocumento | En la NC, el código del CCF; en los demás `null` | todos | Cubierta: serializadores; `NotaCreditoV4Test`. |
| 82 | cantidad | Mayor que 0 y hasta 8 decimales | todos | Cubierta: `AgregarLineaDteRequest::validarValores()` (entero ≥ 1); `PrecioYCantidadTest`. |
| 83 | codigo | Puede ir `null` | todos | Cubierta (snapshot de la línea). |
| 84 | codTributo | `null` salvo tipoItem 4 | todos | Cubierta: siempre `null`. |
| 85 | uniMedida | CAT-014; 99 si no aplica | todos | Cubierta: `MapeaCatalogosMh::uniMedida()`; `CatalogosMhImportTest`, `SerializadorCcfMhTest`. |
| 86 | descripcion | Obligatoria; hasta 1500 caracteres | todos | Esquema. |
| 87 | precioUni | ≥ 0; sin IVA en CCF, NC y FEX; con IVA en FE | todos | Cubierta: `CalculadoraDte` (FE con IVA incluido); `CalculadoraDteTest`. |
| 89 | montoDescu | ≥ 0 y no mayor que el monto del ítem | todos | Cubierta: `AgregarLineaDteRequest::validarDescuentoContraImporte()`; `DteBorradorServiceTest`. |
| 90-92 | ventaNoSuj, ventaExenta, ventaGravada | (precio × cantidad) − descuento del ítem; 0.00 si no hay; holgura ±0.01 | todos | Cubierta: `CalculadoraDte`; `CalculadoraDteTest`, `SerializadoresMhMultiTipoTest`. |
| 92.15 | ventaGravada | En la NC, suma ≤ gravado del CCF | NC | Cubierta: `SaldoMontoCcf::exceso()`; `DteSaldoAcreditableArchivadoTest`. |
| 90.13, 91.13 | ventaNoSuj, ventaExenta | En la NC, suma ≤ no sujeto y exento del CCF | NC | **Falta (H9)**. |
| 96 | tributos (ítem) | CCF y NC: obligatorio 20 si hay gravado; FE: `null`; FEX: C3 | todos | Cubierta: serializadores; `SerializadorCcfMhTest`, `SerializadoresMhMultiTipoTest`, `NotaCreditoV4Test`. |
| 97 | psv | ≥ 0; 0.00 si no aplica | FE, CCF | Cubierta: fijo 0.00 (no soportado). |
| 98 | noGravado | 0.00 si no hay cargos o abonos | todos | Cubierta: fijo 0.00 (no soportado). |
| 99, 101 | ivaPerci, ivaRete (ítem) | 1 % de las ventas gravadas si aplica | NC v4 | Cubierta: `ImportesNcV4AjusteCcf`; `NotaCreditoV4Test`, `DteNotaCreditoRetencionTest`. |
| 101.2 | ivaRete (ítem) | Acumulado de las NC ≤ IVA retenido del CCF | NC | **Falta (H9)**. |
| 102 | ivaItem | (ventaGravada ÷ 1.13) × 13 %; holgura ±0.01 | FE | Cubierta: `CalculadoraDte::calcularFactura()`; `CalculadoraDteTest`. |

### Sección 9: Resumen

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 123-125 | totalNoSuj, totalExenta, totalGravada | Suma de los ítems; holgura ±0.01 | todos | Cubierta: `CalculadoraDte`; NC v4 con cuadre explícito en `SerializadorNotaCreditoV4Mh::validarImportes()`. |
| 129 | subTotalVentas | No sujeto + exento + gravado | FE, CCF, NC | Cubierta: serializadores. |
| 130-132 | descuNoSuj, descuExenta, descuGravada | ≥ 0 y no mayores que el total respectivo | FE, CCF, FEX | Cubierta: `CalculadoraDte::prorratearDescuentoGlobal()`; `DteDescuentoPorcentajeTest`. |
| 134-135 | porcentajeDescuento, totalDescu | Sin símbolo; descuentos por ítem + globales | FE, CCF, FEX, NC | Cubierta: serializadores; NC v4 = suma por ítem (`validarImportes`). |
| 136-138 | tributos (resumen) | Consolida los códigos de los ítems; FEX C3 en 0.00 | todos | Cubierta: serializadores; `validarImportes()` en NC v4. |
| 139 | subTotal | Suma de operaciones − descuentos globales | FE, CCF | Cubierta: serializadores. |
| 141-142 | ivaPerci, ivaRete | ≥ 0 | CCF, FE | Cubierta: percepción fija 0.00 (no soportada); retención: `DteNotaCreditoRetencionTest`. |
| 143-146 | totalIvaPerci, codigoRetencionMH, totalIvaRete, totalIva | Sumas de los ítems; `codigoRetencionMH` `null` al ajustar un CCF | NC v4 | Cubierta: `SerializadorNotaCreditoV4Mh::validarImportes()`; `NotaCreditoV4Test`. |
| 148-149 | seguro, flete | ≥ 0 | FEX | Cubierta: `CalculadoraDte::calcularFacturaExportacion()`. |
| 150 | montoTotalOperacion | FE y CCF: subTotal + tributos; NC: subTotalVentas + tributos; FEX: gravado − descuento + seguro + flete + tributos | todos | Cubierta: `CalculadoraDte`, `validarImportes()` (NC v4). |
| 151 | totalNoGravado | Suma de cargos y abonos | todos | Cubierta: 0.00 (no soportado). |
| 152 | totalNoOnerosas | ≥ 0 y no mayor que el monto de la operación | FEX | Cubierta: 0.00. |
| 153 | totalPagar | Fórmula por tipo (operación ± percepción, retención y cargos) | todos | Cubierta: `CalculadoraDte`; `CalculadoraDteTest`, `NotaCreditoV4Test`. |
| 156 | totalLetras | Alfanumérico | todos | Cubierta: `NumeroALetras`; `UtilidadesJsonTest`. |
| 157 | totalIva | Suma de `ivaItem` − IVA del descuento global | FE | **Falta comprobar (H8)**. |
| 158 | saldoFavor | 0.00 si no se usa | FE, CCF, FEX | Cubierta: fijo 0.00. |
| 159 | condicionOperacion | CAT-016 | todos | Cubierta: `MapeadorDteSalida`. |
| 160-164 | pagos | Obligatorio con condición 1 o 3; con condición 2, `codigo` `null`, monto 0.00, plazo (CAT-018) y período obligatorios | FE, CCF, FEX | **Falta (H2)**. |
| 165, 168 | numPagoElectronico, observaciones | `null` si no se usan | todos | Cubierta: `null`. |
| 166-167 | codIncoterms, descIncoterms | CAT-031 si bienes; `null` si servicios | FEX | Bienes: cubierta (`DatosAduanerosFexTest`). Servicios: **Falta (H10)**. |

### Secciones 11-12: Apéndice y sello

| N.º | Campo | Regla | Tipos | Estado |
|---|---|---|---|---|
| 172-174 | apendice | De 1 a 10 entradas, o `null` | todos | Cubierta: `apendiceComun()` (solo la orden de compra); `DteGeneracionOrdenOcTest`. |
| 175 | selloRecibido | No va en el JSON transmitido; sí en el archivo DTE entregado | todos | Cubierta: `ArchivoEntregaDteService`; `ArchivoEntregaDteTest`. |

## Representación gráfica y entrega

| Regla | Exigencia | Estado |
|---|---|---|
| 11.3, Anexo I 4 | La representación usa los datos de los campos del JSON transmitido | **Falta (H3)**: el PDF lee los modelos actuales. |
| 11.3 | En el ambiente de pruebas, advertir que el documento no tiene valor legal | Cubierta: `pdf.blade.php` (`ambiente 00`); `AmbientePruebasAvisoTest`. |
| Anexo I 4.1 | El archivo DTE incluye la estructura, `firmaElectronica` y `selloRecibido` | Cubierta: `ArchivoEntregaDteService`; `ArchivoEntregaDteTest`. |
| 12 | Conservar el JSON tal como se generó, firmó y transmitió | Cubierta: `DteGeneradoInmutableTest`, `DteInvalidacionProteccionEvidenciaTest`. |

## Catálogos

- El catálogo activo es `resources/dte/catalogos/Catálogos - Facturación Electrónica 2026-07-01.xlsx` (`config/catalogos_mh.php`). Su SHA-256 (`e86d7edc…b2c6`) es idéntico al del Excel entregado junto con la Normativa 2.0. El importador verifica ese hash antes de importar (`CatalogoOficialMh`; `CatalogosMhImportTest`).
- Falta comprobar que lo importado en la base de cada ambiente coincida con el Excel (H11).

## Operaciones no soportadas

El sistema no emite estas operaciones. Sus campos van siempre en `null` o en 0.00, según pide la Normativa cuando no se usan. Si el negocio las necesita, hay que implementarlas antes de emitirlas:

- Documentos asociados (`otrosDocumentos`: médico, transporte y otros).
- Venta y compra por cuenta de terceros.
- Fusiones (`identificacion.fusion`).
- Ítems tipo 4 con tributos especiales (A8, 57, 90, A6) y `codTributo`.
- Cargos y abonos que no afectan la base imponible (`noGravado`), saldo a favor, precio sugerido (`psv`) y percepción de IVA.
- NC sobre más de un documento, sobre documentos físicos o sobre un Comprobante de Retención (07).
- FEX de servicios, mientras esté abierto H10.
- Tipos de documento ND, NR, CR, CL, DCL, FSE y CD; eventos de retorno y de operaciones especiales (ver la auditoría, punto 5).

## Validación en el ambiente de pruebas del MH

Pendiente. Se hace al final, en la copia aislada que arma la sesión de Hacienda (#43), con evidencia por tipo y versión: FE, CCF al contado y a crédito, FEX de bienes y NC v4.
