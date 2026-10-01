# Propuesta de Gastos — para revisión

Fecha: 6 de septiembre de 2026. Estado: dirección general aprobada; inicio de fase 1 en desarrollo. Presentación del formulario Registrar gasto pendiente de revisión antes de extenderla al resto. Sin push ni despliegue.

**Acuerdos para comenzar fase 1**

- Cada gasto tiene uno o varios vencimientos. Un pago se aplica mediante filas `(pago, cuota, importe)`; puede cubrir varias cuotas del mismo gasto o de varios gastos del mismo destinatario y moneda. La obligación se deriva de la cuota, sin segundo reparto que pueda contradecirla. Unique por pago/cuota, importes positivos y suma aplicada igual al pago. Pendiente de una cuota = importe vigente menos aplicaciones vigentes; vencido = suma de pendientes de cuotas con fecha anterior a hoy. Cuotas futuras o sin fecha no se suman a vencido. Se propone repartir empezando por fechas más antiguas, pero el reparto explícito se revisa antes de guardar. Ejemplo: cuotas 40 vencida y 60 futura; pago 50 repartido 40/10 deja pendiente 50 y vencido 0. Pago 20 aplicado a la futura deja pendiente 80 y vencido 40. Correcciones revierten exactamente las aplicaciones originales.
- «Ya lo pagué» es una opción visible del formulario completo, junto a «Pendiente de pago». Al marcarla aparecen fecha, método, quién pagó, importe pagado (permite parcial), reparto por cuotas y comprobantes del pago. Un único botón registra gasto, cuotas, pago, aplicaciones, adjuntos y evento auditado; ninguna confirmación parcial si falla validación/archivo/BD. Los archivos se preparan en almacenamiento privado antes del commit y se limpian si hay rollback; un fallo de proceso puede dejar huérfanos privados, nunca un pago visible sin los adjuntos registrados. Clave de idempotencia estable evita duplicar por reintento. Archivo de cobro y comprobante del pago tienen vínculos separados.
- Un pago puede combinar empresa y personal solo para un mismo destinatario y moneda. No tiene un ámbito editable global: importes por ámbito se derivan de sus aplicaciones a cuotas. Registrar/revertir requiere permiso de acción y acceso a TODAS las obligaciones del pago. Quien consulta solo empresa puede ver sus aplicaciones empresariales y su subtotal, nunca importe total mixto, referencia, nota global, datos personales ni comprobante compartido. Cabecera/comprobante del pago mixto requieren acceso a todos sus ámbitos; no se ocultan solo filas dejando totales reveladores. Los informes empresariales suman aplicaciones empresariales una vez. Exportaciones, consultas directas y auditoría respetan el mismo alcance. No habrá asignación automática de permiso personal a contabilidad. En esta primera pantalla cada gasto pertenece a un solo ámbito; el servicio admite repartos mixtos, su pantalla se construirá después de aprobar la presentación.
- Fecha de arranque y carga de pendientes se eligen al ponerlo en uso; no bloquean desarrollo, no son campos obligatorios del formulario y no disparan importación automática. Recurrencias y recordatorios son la siguiente fase prioritaria; planilla y WhatsApp permanecen fuera de este corte.
- Corrección de auditoría: existe `app/Models/Planta/PlantaProveedor.php`. El catálogo especializado de Planta no se sustituye ni se exige para capturar un gasto. La futura vinculación de proveedores de Compras/Planta debe reutilizar identidades verificadas; este primer formulario permite destinatario por nombre sin exigir alta de proveedor ni documento fiscal.

La propuesta es incorporar un área **Gastos** al sistema actual: qué hay que pagar, para cuándo, qué se pagó y qué queda pendiente. Incluye gastos empresariales y personales claramente separados. Funciona mediante registro directo, con o sin documento fiscal; Compras es una fuente opcional para ahorrar captura. Los ingresos por ventas pertenecen a Rutas/Cobros. No incluye administración de cuentas bancarias, saldos de cuentas ni conciliación bancaria. Internamente conserva obligaciones y pagos separados, documentos privados y una planilla de control que utiliza el mismo registro de pagos. Mantener Laravel, Blade/Alpine y los componentes administrativos existentes.

Alcance ajustado tras la revisión del usuario: incluir compras sin CCF, proveedores sin documento fiscal, pagos a proveedores a plazo, recibos para pagar impuestos enviados por la contadora y gastos personales recurrentes como universidad y clases. Estos ejemplos ilustran registros configurables; no serán datos ni reglas incrustados en el código. «Renta mensual» se captura como concepto y se clasifica según corresponda, sin asumir si significa alquiler o impuesto.

**1. Auditoría y límites de lo verificado**

El HEAD local comprobado es `15b50e74884321da496037fc0c0e81075d0f2b11`, coincidente con el último despliegue reportado. `git status --short` mostraba únicamente `firmware/asistencia/asistencia.ino` modificado antes de esta documentación. Se preservó. Esto verifica el repositorio local, no el estado efectivo del servidor de producción.

La auditoría fue estática: código, rutas, modelos, migraciones, configuración declarada y pruebas existentes. No se consultaron bases de datos, secretos ni servicios operativos; no se ejecutaron scheduler, migraciones, pagos, correos o transmisiones. No se inspeccionó ni modificó `C:\rclone`. La operación de Compras y Drive en producción es contexto proporcionado por el usuario, no una verificación remota de esta sesión.

| Área | Evidencia en el repositorio | Reutilización y límite |
|---|---|---|
| Plataforma | `composer.json`, `package.json` | Laravel 12 declarado, Blade, Alpine, Tailwind; Dompdf y PhpSpreadsheet disponibles. Sin necesidad de cambiar de frontend. |
| Compras | `app/Models/DocumentoRecibido.php` | Conserva emisor, NIT/NRC, fechas de correo y fiscal, número, código, sello, total decimal y metadatos de adjuntos. No tiene obligación, saldo ni pagos. No aparece un modelo de proveedor independiente entre los modelos revisados. |
| Identidad de compras | `app/Services/DocumentosRecibidos/SincronizadorDocumentosRecibidos.php`, migraciones de documentos recibidos | Deduplicación por identidad del correo y código de generación; índices únicos. Debe agregarse identidad única del vínculo financiero, también para documentos sin código fiscal. |
| Tipos de documento | `DocumentoRecibido::TIPOS_SOPORTADOS`, `ParserDocumentoRecibido.php` | Incluye notas de crédito/débito y retenciones. El total del tipo 07 es monto sujeto a retención: **no usarlo como deuda**. El parser utiliza `float`; el adaptador financiero deberá validar y normalizar el importe decimal sin copiar esa aritmética. |
| Envío a contabilidad | `DocumentoRecibidoEnvio`, `EnviarDocumentoRecibidoContabilidad` | Hay historial y envío en cola. `pendiente/enviado/ignorado` no representa pago. El job tiene un intento y reintento manual. La consulta previa de envío equivalente no sustituye una restricción transaccional de idempotencia. |
| Archivos | `config/filesystems.php`, `AdjuntosDocumentoRecibido.php`, `DocumentoRecibidoController::descargarArchivo` | Disco local en `storage/app/private`, descargas de PDF/JSON de Compras. Reutilizar originales mediante referencias; agregar autorización por obligación/pago, vistas previas y recepción de imágenes. No basta con que el disco se llame privado. |
| Empleados | `app/Models/Asistencia/AsistenciaEmpleado.php`, `routes/asistencia.php` | Identidad con código, nombres, activo, ingreso y usuario opcional; las huellas son relaciones separadas. Las rutas actuales exigen Asistencia habilitada. Crear acceso de personal para Planilla independiente del lector, sobre la misma identidad. |
| Personal de rutas | `app/Models/PersonalRuta.php` | Ya permite vínculo opcional con empleado de Asistencia. Revisar coincidencias y enlazar explícitamente; no fusionar personas automáticamente por nombre. |
| Planilla | Modelos y rutas revisados | No se encontró motor de nómina ni control de pagos laborales. Jornadas de asistencia no equivalen a horas extra legalmente calculadas. |
| Permisos y auditoría | `app/Enums/PermisoSistema.php`, `database/seeders/RolesSeeder.php`, `User.php`, uso de Activitylog | Reutilizar catálogo, middleware/policies y bitácora. El administrador recibe todos los permisos actualmente; decidir quién tendrá ese rol cuando existan salarios. |
| Programación y correo | `routes/console.php`, `app/Support/Correo/CandadoCorreoReal.php` | Scheduler, colas, interruptores por proceso y candado de simulación fuera de producción. Reutilizar infraestructura; no reutilizar estados de correo fiscal para recordatorios. |
| Avisos | `User::Notifiable`, configuración de backup | Infraestructura disponible, pero no se encontró bandeja general de vencimientos. Los respaldos ya silencian éxitos: conservar esa preferencia. |
| Estilo y navegación | `resources/views/facturacion/create-ccf.blade.php`, `layouts/app.blade.php`, `app/Enums/AreaSistema.php` | Formulario blanco, ancho contenido, etiquetas, rejilla adaptable, errores junto al campo, acciones sencillas. Integrar el área al selector y menú existentes. |
| WhatsApp | Búsqueda en `app`, `config`, `routes`, `database`, vistas | No se encontró integración oficial aprovechable en el código revisado. No demuestra ausencia de una cuenta o servicio externo. |

Existen pruebas de Compras, contabilidad, empleados y Asistencia que servirán como regresión. No se ejecutaron: esta entrega no modifica comportamiento.

**2. Alcance y conceptos que verá el usuario**

Menú: **Gastos · Pagos · Recurrentes · Planilla · Informes · Configuración**. En Gastos se filtra por pendientes, vencidos, próximos y pagados. Mostrar cada opción cuando esté disponible y autorizada. Destinatarios de pago, categorías, métodos y avisos se administran en Configuración; no habrá catálogo obligatorio de cuentas/cajas. Los nombres comerciales y preferencias son datos editables, nunca condiciones del código.

La acción principal será **Registrar gasto**. El formulario muestra concepto, a quién se paga, empresa/personal, categoría, importe, período y vencimiento. Permite indicar «Pendiente de pago» o «Ya lo pagué»; en el segundo caso aparecen fecha, método, quién pagó y comprobante en el mismo formulario. Un gasto sin CCF es válido para este control administrativo; no se presupone su tratamiento fiscal. El documento de cobro y el comprobante del pago son respaldos distintos y opcionales según el caso.

| Caso cotidiano | Registro propuesto |
|---|---|
| Compra sin CCF o proveedor informal | Registrar gasto manual, proveedor por nombre sin exigir NIT/NRC, monto y fecha; adjuntar recibo/foto si existe o indicar que no entregó documento. |
| Factura recibida en Compras | Crear gasto con datos prellenados o vincularla a un gasto existente. Solo se genera una deuda. |
| Proveedor que cobra después | Registrar gasto pendiente y fecha acordada; registrar abonos a medida que se paguen. Si hay cuotas con fechas distintas, guardar vencimientos por cuota dentro del mismo gasto. |
| IVA u otro pago enviado por la contadora | Registrar pendiente con concepto, período, importe y fecha del recibo; adjuntar el documento para pagar. Tras pagar, adjuntar comprobante y registrar el pago. No depende de recibir un correo ni calcula el impuesto. |
| Universidad o clases de inglés | Gasto personal recurrente; separar institución/persona a quien se paga de persona para quien es el gasto. Generar un registro por período. |

La marca «No entregaron documento» se diferencia de «Falta adjuntar» para no reclamar eternamente un CCF inexistente. El comprobante de pago puede seguir faltando aunque no se espere documento fiscal.

Una obligación responde «qué debemos, a quién y para cuándo». Un pago responde «qué salida de dinero registramos y quién la hizo». Un documento recibido respalda la operación. Su envío a contabilidad es otro proceso independiente.

Separar dos clasificaciones:

- **Ámbito:** empresarial o personal; persona para quien es el gasto opcional (no tiene que ser propietario, empleado ni usuario). Un registro personal no entra en los totales empresariales por defecto. El destinatario del pago y la persona beneficiada son campos distintos: institución educativa y estudiante, por ejemplo.
- **Naturaleza:** gasto operativo, adquisición de inventario/activo, obligación tributaria, remuneración, retiro de propietario u otro movimiento por clasificar. Categoría describe el concepto: electricidad, software, mantenimiento, etc. No se crea un segundo gasto cuando se paga.

Primera entrega: obligaciones operativas, compras, tributos por importe manual revisado, pagos, movimientos personales/retiros identificados y comprobantes. Adquisiciones y tributos se controlan como obligaciones, sin afirmar automáticamente que sean gasto del período. Una salida inmediata usa «Registrar y pagar», en un solo formulario y una sola transacción, pero conserva obligación y pago separados.

Transferencias entre cuentas propias, préstamos y anticipos con saldo a favor quedan fuera de la primera entrega. El sistema debe impedir registrarlos como gastos ordinarios y explicar el alcance. La arquitectura admite después movimientos con origen/destino y aplicaciones de anticipos. No simularlos con obligaciones negativas ni aumentar una factura para absorber un exceso.

Pago empresarial con fondos de un propietario: identificar origen personal y pagador; la deuda con el proveedor se salda una vez. Si se necesita controlar reembolso al propietario, será una obligación vinculada de naturaleza **reembolso**, excluida del gasto del período. Incorporarla solo si se confirma este caso en la revisión; no registrar nuevamente el gasto original.

No administrar cuentas bancarias ni calcular sus saldos. Para pagar basta método (transferencia, efectivo u otro), fecha, quién pagó y referencia/comprobante cuando exista. Una nota opcional puede identificar el origen sin crear una cuenta ni exigir saldo disponible. «Saldo pendiente» siempre se refiere al gasto por pagar. El módulo es control administrativo, no reemplazo de contabilidad formal.

**3. Modelo de datos propuesto**

Nombres orientativos, no migraciones definitivas. Las entidades de fases posteriores se crean al implementar su fase.

| Entidad | Datos principales y relaciones |
|---|---|
| `beneficiarios` | Nombre, identificación opcional normalizada, contacto, activo; enlace único opcional a empleado existente. Proveedor no debe ser convertido artificialmente en cliente. Guardar fotografía histórica de nombre/identificación al confirmar documentos. |
| `categorias_financieras` | Nombre, naturaleza admitida, activo; fijo/variable como característica independiente de periodicidad. Se desactivan, no eliminan si tienen uso. |
| `metodos_pago` | Transferencia, efectivo y otros métodos configurables. Sin catálogo bancario, credenciales ni saldo de cuentas. |
| `personas_gasto` | Nombre de persona para quien se realiza un gasto personal, con enlaces opcionales a identidades existentes; sin exigir cuenta de acceso. |
| `obligaciones` | Beneficiario, persona para quien es el gasto opcional, concepto, ámbito/naturaleza, moneda, período desde/hasta, emisión, vencimiento nullable, monto nullable, responsable, ciclo documental, autor, fechas y versión. Puede estar esperando monto sin inventar cero. |
| `obligacion_partidas` | Desglose opcional por categoría/naturaleza y período; una partida habitual prellenada. Permite dividir conceptos mixtos sin crear dos deudas por la misma factura. Suma igual al importe confirmado. |
| `obligacion_vencimientos` | Opcional para cuotas pactadas: importe y fecha por cuota; suma igual a la obligación. Las aplicaciones identifican la cuota cubierta cuando existe calendario. Sin calendario, un único vencimiento. No duplicar el gasto por cada cuota ni calcular vencido el total si solo venció una cuota. |
| `obligacion_fuentes` | Referencia a documento recibido u otro origen, papel de la fuente y snapshot de los datos reutilizados. Una fuente de deuda canónica solo puede originar una obligación. Varios respaldos pueden referenciar la misma obligación. |
| `pagos` | Beneficiario, monto, moneda, método, fecha real, persona que pagó, usuario que registró, referencia, nota opcional de origen, observaciones, estado, clave de idempotencia y vínculo de reversión. Un pago normal tiene un beneficiario y una moneda. No necesita cuenta bancaria asociada. |
| `pago_aplicaciones` | Pago, obligación e importe aplicado. Relación muchos a muchos: parciales y un pago para varias obligaciones del mismo beneficiario/moneda. Unique por pago/obligación. |
| `ajustes_obligacion` | Crédito/débito/corrección, importe positivo con dirección explícita, motivo, documento fuente, autor, fecha y reversión. Aplicación de cada crédito limitada a su importe disponible. No es un pago. |
| `adjuntos` y vínculos | Disco/ruta privada generada, nombre original, MIME real, bytes, SHA-256, autor, fecha, origen y estado de validación; vínculos a obligación, pago o planilla. Fuente de Compras referenciada, no recapturada. |
| `eventos_financieros` | Historial inmutable de registro, confirmación, corrección, aplicación y reversión; autor, instante, motivo, entidad y versión. Activitylog complementa la auditoría general, con acceso salarial restringido. |
| `reglas_recurrentes`, `ocurrencias_recurrentes` | Configuración versionada y fecha de efecto; período/fecha lógica, estado generado/omitido y obligación resultante. Unique por regla y ocurrencia lógica, independiente de versión. |
| `preferencias_avisos`, `avisos`, `entregas_avisos` | Canal, destinatarios, anticipación, frecuencia, zona horaria; motivo y obligaciones incluidas; clave única de resumen, intento, resultado y próxima ejecución. |
| `planillas`, `planilla_empleados`, `planilla_conceptos` | Período, tipo regular/extraordinario, revisión; empleado y snapshot, ingresos/descuentos manuales, bruto y neto; obligación salarial única por detalle confirmado. |
| `lotes_pago` | Agrupación operativa de pagos individuales de planilla. No cambia la regla de beneficiario único por pago ni representa transferencia bancaria ejecutada. |

Reutilizar `empresas` para identidad y encabezados de esta instalación. No se propone aislamiento multiempresa. Configuración sin datos particulares incrustados y servicios de dominio pequeños dentro del monolito son suficientes para reutilizar el producto.

**4. Estados, saldos e integridad**

Tres ejes visibles, sin un estado único ambiguo:

| Eje | Valores |
|---|---|
| Preparación de obligación | Esperando recibo/monto, lista para pagar, cancelada. Una obligación confirmada conserva su versión original. |
| Liquidación calculada | Sin pagos, parcialmente saldada, saldada. Si solo hay nota de crédito, indicar «Saldada por ajuste», no «Pagada». Con monto desconocido: «Por determinar». |
| Vencimiento calculado | Sin fecha, próxima, vence hoy, vencida; solo es vencida cuando tiene saldo conocido positivo y fecha anterior a hoy. Saldadas/canceladas no generan reclamo actual. |
| Pago | Borrador, registrado, revertido. «Registrado» significa declarado por el usuario, no liquidado por el banco. |
| Verificación, si se incorpora | Sin verificar, verificado manualmente con evidencia y autor. Reservar «conciliado» para un proceso contra movimientos bancarios reales. |

`saldo = importe confirmado + débitos vigentes − créditos vigentes − aplicaciones de pagos vigentes`.

Ejemplo: factura USD 100, pago USD 30, nota de crédito USD 20: pendiente USD 50. Pagar USD 50 deja saldo cero; dinero pagado USD 80 y crédito USD 20, sin descontar la nota otra vez. Revertir el primer pago devuelve saldo USD 30. Si se desconocía monto, el saldo era desconocido, no USD 0.

- Importes almacenados en decimal de precisión definida (propuesta `DECIMAL(18,2)` para monedas admitidas de dos decimales); cálculos con unidades menores enteras o librería decimal y cadenas, nunca `float`. Monedas de otra precisión requieren validación explícita antes de habilitarlas. No conversiones de moneda en V1.
- Al registrar pago: transacción, bloqueo de obligaciones en orden estable, nueva lectura de saldos, validación de beneficiario/moneda/método, suma exacta de aplicaciones y clave única de idempotencia. Repetir la misma petición devuelve el mismo resultado; misma clave con otros datos devuelve conflicto. Doble clic no crea otro pago; dos usuarios no pueden consumir el mismo saldo.
- En V1, monto de pago = suma aplicada; sobrepago se advierte y bloquea. No dejar remanentes ocultos como anticipos. Los anticipos futuros necesitan subregistro de saldo a favor y aplicación explícita.
- Comparar posibles duplicados por referencia, origen, beneficiario, fecha e importe; hash para archivo idéntico. Coincidir en importe no bloquea. Una excepción justificada queda auditada.
- Pago registrado no se edita financieramente ni se borra: reversión íntegra con motivo y pago sustituto vinculado. Las aplicaciones originales se conservan. Una reversión corrige el registro: no declara que el banco devolvió dinero.
- Un crédito posterior a un pago completo se muestra como saldo a favor por resolver; no lleva la deuda a saldo negativo silencioso ni se aplica ficticiamente. En V1 se admite aplicar solo hasta el saldo disponible, dejando el remanente documental visible para tratamiento posterior.
- Correcciones de deuda mediante ajustes trazables; cancelar exige saldo y aplicaciones compatibles. No cancelar una deuda pagada sin resolver su historial. Cambios de importe/beneficiario con pagos no se realizan por edición directa.
- No modificar DTE fiscales aceptados. Fechas reales de operación, fecha de registro y fecha efectiva de corrección se guardan por separado. Informes históricos indican fecha de corte y revisiones posteriores.

**5. Vinculación con Compras y arranque controlado**

Compras es una entrada opcional. Todos los gastos pueden registrarse directamente, sin sincronizar correo, importar un DTE, proporcionar CCF ni adjuntar archivo. En Compras agregar «Registrar gasto», «Vincular a gasto existente» o «Ver gasto» según corresponda. Abrir formulario ya completo con proveedor, fecha fiscal, número, monto y adjuntos; solicitar únicamente clasificación, período, vencimiento y responsable faltantes. Confirmar moneda desde el original o pedir revisión: no hay campo de moneda explícito en el modelo revisado. Si el documento llega después del registro manual, vincularlo como respaldo al gasto existente, sin generar otra deuda.

El vínculo no altera `estado` de Compras. Una factura puede estar enviada a contabilidad y pendiente de pago; otra, pagada y aún pendiente de enviar.

La fuente debe identificar de manera canónica el documento: código fiscal cuando exista y referencia única a `documentos_recibidos.id` en todos los casos. Si un PDF sin código representa un documento ya registrado, vincular como respaldo luego de revisión. No fusionar por nombre o monto. Para una obligación recurrente existente, «Vincular recibo» completa esa obligación, sin crear una nueva deuda.

Notas de crédito/devoluciones: revisión del documento relacionado y aplicación explícita como ajuste con fuente única y control de importe acumulado, incluso si se distribuye entre varias deudas. Notas de débito aumentan deuda mediante ajuste revisado. Retenciones y documentos no soportados requieren interpretación antes de generar una obligación; jamás convertir indiscriminadamente todos los `total` en importes por pagar.

Arranque: elegir fecha de corte, listar candidatas y confirmar por documento «pendiente completo», «pendiente parcial», «ya pagado», «no corresponde» o «revisar». Nada se incorpora automáticamente. Para parciales sin historial fiable, guardar importe original, saldo inicial certificado y fecha/motivo de apertura; no inventar pagos ni fechas. Ese ajuste de apertura no entra en dinero pagado del período. La conciliación inicial la revisan ambos usuarios con apoyo contable cuando corresponda.

**6. Bocetos de pantallas**

Bocetos funcionales de baja fidelidad; no son pantallas implementadas ni pruebas visuales. Usar layout y componentes del CCF: encabezado sobrio, secciones completas visibles, una o dos columnas según ancho, etiquetas persistentes y acciones al pie. Sin asistente inicial ni panel de gráficas.

Pantalla principal, datos ficticios al 06/09/2026:

```text
Gastos                             [Registrar gasto] [Registrar pago]

Moneda: USD     Ámbito: Empresarial     Pagos del: 01/09 al 30/09
Pendiente a hoy 350.00    De ello vencido 150.00    Pagado en período 120.00
1 obligación espera monto (no incluida en totales)

[Pendientes] [Vencidos 1] [Hoy 1] [Próximos 1] [Pagados] [Por completar 1]
Buscar concepto, proveedor o referencia…   Categoría ▾  Responsable ▾

Beneficiario / concepto       Vence       Saldo USD   Situación
Proveedor A / mantenimiento   03/09          150.00    ⚠ Vencida · Parcial
Proveedor B / internet        06/09           50.00    ◷ Hoy · Sin pagos
Proveedor C / seguro          12/09          150.00    ◷ Próxima · Sin pagos
Proveedor D / electricidad    Sin fecha      Por definir · Falta recibo

Abrir fila → detalle                         Exportar resultados
```

Pendiente es saldo actual, vencido es un subconjunto, pagado pertenece al período seleccionado: no sumar esas tres cifras. Monedas separadas, filtros explícitos y paginación. En «Pagados» mostrar obligaciones saldadas mediante pagos; los créditos se identifican aparte. La sección Pagos permite revisar las salidas individuales.

```text
Registrar gasto

Datos de la obligación
Beneficiario* [Buscar o crear…]       Concepto* [Internet septiembre]
Categoría*   [Servicios ▾]            Ámbito    [Empresarial ▾]
Naturaleza   [Gasto operativo ▾]      Responsable* [Usuario actual ▾]

Importe y fechas
Moneda* [USD ▾]  Importe [50.00]      [ ] Todavía no conozco el monto
Período [01/09/2026] a [30/09/2026]   Vence [06/09/2026] / Sin fecha
Documento [Vincular de Compras…]      Emisión [01/09/2026]

Comprobantes  [Elegir archivo] [Tomar foto]
Arrastrá archivos o pegá una captura aquí. Vista previa y quitar borrador.
Opciones adicionales ▸ número, observaciones, partidas, propietario

[Cancelar]                                              [Guardar gasto]
```

Detalle: importe original, ajustes, pagado y saldo; responsable, vencimiento y documentos; historial cronológico con usuario y motivo. Una acción principal «Registrar pago»; corregir/cancelar en acciones secundarias autorizadas. Monto desconocido no permite pagar esa obligación hasta completarla.

```text
Registrar pago

Beneficiario* [Proveedor A]      Fecha real* [06/09/2026]
Monto* [100.00] USD              Método* [Transferencia ▾]
Quién pagó* [Usuario actual ▾]   Para [Empresa / Persona]
Referencia [                    ]

Obligaciones del beneficiario          Saldo        Aplicar
[✓] Mantenimiento · septiembre        150.00        [100.00]
[ ] Otra obligación                    80.00        [      ]
Aplicado 100.00 · Sin asignar 0.00 · Saldo posterior seleccionado 50.00

Comprobantes [Elegir archivo] [Tomar foto]   Miniaturas / PDF
Observaciones [                                            ]
Quedará registrado por vos. Este sistema no ejecuta la transferencia.

[Cancelar]                                             [Registrar pago]
```

Desde una obligación se preseleccionan beneficiario y saldo, editables para parcial. El registrador sale de la sesión, no de un selector. Si falta comprobante, permitir guardar con motivo y señal «Falta comprobante» según política acordada. No perder datos si falla una carga; mostrar cuáles archivos sí llegaron.

```text
Planilla de control                   [Nueva planilla]
Período 01/09–15/09/2026 · Quincenal · Borrador

Empleado       Ingresos     Descuentos     Neto      Pagado    Pendiente
Empleado A      300.00         20.00       280.00      0.00      280.00
Empleado B      280.00          0.00       280.00      0.00      280.00
Total USD       580.00         20.00       560.00      0.00      560.00

Abrir empleado → Salario, bonificación y descuentos con concepto/monto
[Guardar borrador]                              [Confirmar planilla]

Confirmada: [Registrar pagos] [Imprimir para firmas] [Adjuntar firmada]
Cada empleado: recibo individual, historial y saldo.
```

En móvil: filas convertidas en bloques compactos con concepto, saldo y vencimiento; formularios a una columna, controles de al menos 44 px, teclado decimal, foco visible y errores asociados. Acciones táctiles sin duplicarlas arriba y abajo; barra inferior solo si sustituye la acción normal y no tapa contenido. Cámara mediante selector del teléfono; pegado como mejora opcional con alternativa de archivo. Planilla permite abrir cada empleado sin desplazar horizontalmente toda la página. Verificar modo claro/oscuro, zoom, lector de pantalla y navegación de teclado en la implementación.

**7. Recurrencias y recordatorios**

Regla con beneficiario, concepto, moneda, categoría, responsable, frecuencia semanal/mensual/anual o lista de fechas, comienzo/fin, monto fijo o variable, vencimiento y anticipación de generación. Generar una obligación por período, copiando la versión vigente de la regla.

- Propuesta para días 29–31 inexistentes: último día del mes; 29 de febrero anual pasa al 28 en año no bisiesto. Mostrar esta política al configurar y conservarla en cada ocurrencia.
- Semanal: día de semana y ancla. Fechas específicas: lista sin repetidos. Mensual: clave del mes lógico, aunque cambie el día o la versión. Cambio de frecuencia define fecha de corte y comprueba solapamientos antes de guardar.
- Variable: genera «Esperando recibo/monto»; sin importe conocido no se suma deuda. Vencimiento desconocido permanece nulo. Puede aparecer aviso «Falta recibir recibo», nunca reclamo de deuda vencida inventada.
- Pausar frena generación futura, no elimina obligaciones existentes. Al reanudar se revisan períodos faltantes antes de recuperarlos. Omitir crea una ocurrencia omitida con motivo para que el scheduler no la regenere. Cancelar cierra la regla; deudas generadas siguen vigentes salvo cancelación individual justificada.
- Unique por regla/ocurrencia más transacción: reintentos y dos workers no duplican. Tras caída del servicio recuperar períodos desde la última ocurrencia con ventana revisable; no generar todo el histórico sin control. Guardar zona horaria de negocio y timestamps de auditoría en UTC.

Avisos dentro del sistema y resumen de correo configurable, por ejemplo 7/3/0 días y recordatorio semanal de vencidos; son valores propuestos editables. Responsable y destinatarios autorizados por ámbito. No incluir salarios en un correo general.

Preparar un único resumen por destinatario, ventana y canal; registrar clave única, contenido, obligaciones y resultado. Volver a consultar saldos y permisos justo antes de enviar: quitar saldadas/canceladas, no enviar resumen vacío. Una saldada desaparece de avisos activos, conservando historia.

Outbox transaccional después de confirmar datos, bloqueo de entrega y reintentos con espera creciente para fallos claramente anteriores a aceptación. SMTP no garantiza entrega exactamente una vez: si hay timeout después de posible aceptación, dejar «resultado incierto» y revisar antes de reenviar, o usar proveedor con idempotencia cuando se apruebe. No prometer que una simple bandera evita todos los duplicados de transporte. No copiar sin más el job fiscal actual.

Conservar `CandadoCorreoReal`, log/array y simulación en desarrollo, y agregar interruptor propio apagado. Nunca mandar «todo salió bien» ni un correo por cada pago. La periodicidad permite recordar pendientes reales aunque no haya nuevas operaciones.

**8. Planilla, documentos y confidencialidad**

Primera planilla es **control de remuneraciones y recibos**, con importes manuales revisados, períodos semanales/quincenales/mensuales, ingresos y descuentos desglosados. Bruto = suma de ingresos; neto = bruto − descuentos, con validación de neto no negativo. No aplicar automáticamente porcentajes legales ni inferir horas extra a partir de presencia.

Al confirmar se congelan conceptos, nombre, período e importes y se crea una obligación por empleado, con fuente única. Un segundo clic no duplica planilla ni deuda. Avisar solapamiento de empleado/período; una planilla extraordinaria requiere tipo y motivo explícitos. Corrección mediante revisión vinculada, conservando la versión y pagos; nunca recalcular recibos históricos desde un salario actual.

Pago individual o lote: un pago por empleado, incluso cuando el operador registra varios juntos. Antes de confirmar el lote, revisar errores de todos; registro atómico para un lote manual pequeño, con identidad única por integrante. El lote no envía órdenes al banco. Parciales permitidos con saldo visible y recibo del importe efectivamente pagado; planilla calculada no equivale a planilla pagada.

Anticipos/descuentos: concepto manual con respaldo en primera versión; su recuperación automática requiere enlazar el anticipo original y evitar descontarlo dos veces. Un descuento retenido a favor de tercero puede crear otra obligación de naturaleza pasivo, **no otro gasto salarial**; esta derivación se valida contablemente antes de automatizarse. Bruto y neto no deben confundirse en informes.

Dompdf para hoja general de firmas y recibos: empresa configurable, número/revisión, período, empleado/código, ingresos, descuentos, neto, fecha e importe pagado, saldo restante y espacio de firma. Cabecera repetida, paginación, renglones legibles y firmas sin partir. Documento sin pago claramente «Preparación de planilla», sin declarar recibido. Adjuntar después versión firmada y mantener relación con el documento emitido.

Permisos propuestos: `gastos.ver`, `gastos.registrar`, `pagos.registrar`, `pagos.corregir`, `gastos.administrar`, `gastos.exportar`, `planilla.ver`, `planilla.gestionar`, `planilla.pagar` y acceso personal restringido. Policies por registro y archivo, incluidas búsquedas, exportaciones, vistas previas, bitácora y avisos. Ver gastos no permite deducir salarios individuales desde beneficiarios, totales filtrados o adjuntos. Mostrar totales laborales agregados solo con permiso expresamente acordado.

Sin circuito de aprobación obligatorio para dos operadores: registrar directamente con trazabilidad. Podrá añadirse aprobación antes de registro sin cambiar el modelo de aplicaciones.

Archivos: propuesta inicial JPG/PNG/WebP y PDF, hasta 10 MB por archivo y 10 archivos por registro, configurable. Validar firma/MIME y tamaño en servidor; nombres/rutas generadas, protección de sesión/CSRF, límites de carga, cuarentena o rechazo de archivos no inspeccionables, análisis antimalware cuando esté disponible y acceso siempre por controlador autorizado. No SVG/HTML ejecutables ni rutas suministradas por el cliente. Miniaturas también privadas. No enlaces públicos ni confiar solo en enlaces firmados. Conservar original y registrar descargas/correcciones relevantes sin volcar información salarial al log general. Retención y eliminación autorizada se acuerdan antes de operar; nunca borrar comprobantes históricos silenciosamente.

**9. Informes**

- Pendientes y vencidos a fecha de corte, próximos compromisos y obligaciones por completar. Proyecciones recurrentes separadas de deudas ya generadas; variables desconocidas por cantidad, sin monto inventado.
- Pagos por fecha real, categoría, beneficiario y responsable; distinguir pagador de registrador. Si un pago cubre varias categorías, usar sus aplicaciones/partidas, no repetir el pago completo en cada categoría.
- Gasto administrativo del período según clasificación y período de la obligación, separado de dinero pagado según fecha del pago. Compras de inventario, activos, tributos, retiros y reembolsos no entran automáticamente como gasto operativo.
- Fijos/variables, empresariales/personales, comprobantes faltantes, historial y saldo, pagos y pendientes de planilla autorizados.
- XLSX/CSV con filtros, fecha de corte, moneda y explicación de columnas; evitar fórmulas inyectadas en textos exportados. PDF donde ayude a firmar/imprimir. Cada moneda con sus propios totales.

**10. Entregas utilizables y criterios de aceptación**

| Fase | Entrega y dependencia | Se considera utilizable cuando… |
|---|---|---|
| 1. Obligaciones, pagos y comprobantes | Catálogos mínimos, estados/saldos, parciales y pago multifactura, reversión, archivos privados, integración revisada con Compras, créditos, apertura controlada e informes básicos. Depende de revisar este diseño y decisiones de arranque. | Ambos operadores pueden saber qué falta, registrar un pago desde teléfono y encontrar evidencia; los casos de concurrencia, permisos y saldos pasan pruebas. Puede usarse con captura manual sin esperar automatizaciones. |
| 2. Recurrencias y avisos | Reglas/versiones, generación idempotente, bandeja interna, resúmenes simulados y control de entregas. Depende de saldos y permisos de fase 1. | Cambiar una plantilla no altera meses previos; se recuperan períodos sin duplicar; no se reclama deuda saldada ni se envían correos vacíos. |
| 3. Planilla y formatos | Personal reutilizado sin biometría, conceptos manuales, obligación por empleado, pagos/lotes, PDF general/individual y firmados. Depende de fase 1 y de validación laboral del alcance; puede adelantarse respecto de fase 2 si es prioridad. | Funciona con Asistencia apagada; no duplica gasto/pago; preserva versiones, restringe salarios y los impresos se revisan visualmente. |
| 4a. Captura asistida/OCR | Bandeja y sugerencias revisables, detección de duplicados y trazabilidad de extracción. Depende de fase 1; no es requisito para terminarla. | Una extracción equivocada no crea ni confirma pagos; las sugerencias tienen revisión humana y acceso restringido. |
| 4b. WhatsApp oficial | Recepción autorizada hacia la bandeja, no ejecución de pagos. Depende de bandeja segura y aprobación específica de proveedor/costos/cuenta. OCR es opcional, no requisito técnico de recepción. | Webhooks repetidos no duplican entradas; remitente no autorizado no registra pagos; todo pasa por revisión. |

Pruebas comprometidas para implementación: deuda 100 con parciales 30/70; reversión; crédito 20 aplicado una vez; crédito posterior a liquidación; sobrepago y dos usuarios sobre el mismo saldo; pago multifactura; monedas distintas rechazadas; doble importación/vinculación; apertura parcial sin pago ficticio; variable sin fecha; 31/mes corto y bisiesto; pausa/reanudación/omisión; workers simultáneos; resumen vacío/saldado/error incierto; acceso directo no autorizado a archivo/exportación/planilla; lote repetido; planilla con asistencia apagada. Usar DB de pruebas aislada, reloj controlado, correo y discos simulados. Revisar escritorio y móvil con nombres largos, varios PDF/fotos, errores de carga y planilla de varias páginas. La revisión visual aún está pendiente de implementar prototipos/pantallas aprobados.

**11. WhatsApp: evaluación preliminar de fase posterior**

No se encontró integración reutilizable. Propuesta: Cloud API oficial, número/activo empresarial autorizado, HTTPS público para webhook, verificación de firma, remitentes preautorizados y descarga privada del archivo. Identidad por mensaje/media para deduplicación. El teléfono identifica una entrada de bandeja, no sustituye sesión y permisos del usuario que confirma el pago. No activar número, webhook, cuenta o facturación en esta entrega.

La documentación oficial de Meta enumera `whatsapp_business_messaging` y `whatsapp_business_management` para mensajería y administración de activos; solicitar solo permisos correspondientes al flujo finalmente aprobado. Ver [colección oficial de Meta](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api).

Costos: separar mensajes facturables de Meta, eventual cargo de intermediario, almacenamiento/webhook, OCR por imagen/página y mantenimiento. La tarifa depende de mercado/categoría y debe reconfirmarse al contratar; no se propone presupuesto mensual ficticio sin volumen ni proveedor. Consultar [precios oficiales de WhatsApp](https://whatsappbusiness.com/es-la/products/platform-pricing/). Presupuesto a preparar: `mensajes facturables × tarifa vigente + proveedor + OCR + infraestructura`; pedir volumen y cotización antes de conectar. No asumir que todo el flujo será gratis por ser entrante.

Estimación propia de esfuerzo, no cotización: bandeja segura y recepción oficial básica, 5–10 jornadas de ingeniería con base de fase 1 lista; OCR revisable, otras 3–6 jornadas según proveedor y formatos. Alta/verificación/revisión de Meta y ajustes de privacidad pueden añadir esperas externas. Esfuerzo medio/alto frente a subir una foto directamente desde el teléfono, ya cubierto en fase 1.

**12. Decisiones para revisar juntos**

Solo las tres primeras son necesarias para cerrar fase 1; las otras se resuelven antes de su fase.

1. **Desde cuándo.** Ya se confirmó incluir gastos empresariales y personales, con o sin CCF, y sin dependencia de Compras ni cuentas bancarias. Falta elegir fecha de arranque y quién revisa lo anterior. Propongo incorporar solo pendientes verificados, sin conversión masiva. El control de reembolsos de fondos propios sigue siendo opcional y no bloquea el registro de gastos.
2. **Quién puede ver y corregir.** ¿Ambos podrán registrar y revertir pagos? ¿Contabilidad tendrá consulta/exportación? ¿Quién verá datos personales y salarios? Propongo permisos operativos para ambos, sin aprobación obligatoria, y planilla restringida expresamente; revisar el rol administrador que hoy tiene acceso total.
3. **Cómo pagan habitualmente.** ¿Moneda inicial USD y transferencia como método habitual? ¿Necesitan pagar varias facturas juntas desde el inicio o cuotas con distintas fechas? Propongo admitir pago conjunto y calendario opcional de cuotas. ¿Permitir pago sin comprobante con motivo? Propongo sí, marcado como faltante. No se requieren cuentas bancarias. Sobrepagos bloqueados hasta definir anticipos.
4. **Cuándo avisar.** ¿A quién y con qué frecuencia enviar resumen cuando hay pendientes? Propuesta: avisos 7/3/0 días, vencidos semanalmente y ningún correo vacío. Todo simulado hasta una autorización posterior de activación.
5. **Cómo preparar planilla.** ¿Semanal, quincenal o mensual; cuántos empleados; qué conceptos usan hoy; aceptan parciales; hoja conjunta, recibo individual o ambos? Propuesta: ambos formatos e importes manuales revisados, sin cálculos legales automáticos.

**13. Riesgos que requieren validación**

El riesgo principal es crear una deuda incorrecta desde documentos que no representan monto por pagar o desde histórico ya liquidado. Se resuelve con vínculo único y revisión de apertura, no con importación indiscriminada.

Con contabilidad: clasificación de inventario/activo frente a gasto, período de reconocimiento, impuestos/retenciones, créditos/devoluciones, apertura de parciales, fondos/reembolsos de propietarios y recuperaciones de anticipos. Las reglas aquí son propuestas de control de datos, no dictamen fiscal. Validar tratamiento antes de automatizarlo.

Con asesoría laboral: conceptos y presentación de recibos, descuentos autorizados, obligaciones retenidas a terceros y conservación de documentos. ISSS, AFP, renta, vacaciones, aguinaldo, indemnización y horas extra quedan fuera del cálculo automático. Para un motor legal futuro será indispensable investigación separada en fuentes oficiales vigentes de El Salvador, con regla, fecha de vigencia, topes, excepciones, ejemplos y pruebas validadas; esta entrega no presenta fórmulas legales.

Otros riesgos concretos: exposición de salarios a través de auditoría/exports; timeout de correo de resultado incierto; comprobantes repetidos; crédito que excede deuda; modificación de plantilla que regenera meses; y confundir pago declarado con conciliación bancaria. Cada uno tiene controles propuestos arriba. Comprobar cobertura y recuperación de los nuevos archivos privados antes de uso operativo, sin cambiar el respaldo externo de Drive en este proyecto.

Próximo paso: revisar alcance, bocetos y decisiones 1–3. Solo después de esa revisión se implementaría fase 1 en desarrollo.

---

## Estado de la implementación — corte 1 de fase 1 (8 de septiembre de 2026)

Solo desarrollo. Sin commit, sin push, sin despliegue, sin tocar producción ni `C:\rclone`. Base de pruebas SQLite `:memory:`; base de desarrollo local `base_ejemplo` (no existe una base de producción en esta máquina). No se transmitió ningún DTE, no se ejecutó ningún pago bancario y no se envió ningún correo.

**Corrección previa.** El archivo de rutas usaba una Closure dentro del array de middleware del grupo. Eso rompía la resolución de rutas con `Object of class Closure could not be converted to string` y por eso se había retirado su inclusión de `routes/web.php`. Ahora el interruptor vive en `App\Http\Middleware\ModuloGastosActivo` (alias `modulo.gastos`), como en Planta y Asistencia, y el grupo usa `['auth', 'modulo.gastos', 'permission:gastos.ver']`. `routes/gastos.php` volvió a `routes/web.php` y el enlace volvió al menú, detrás de `GASTOS_ENABLED` y de `gastos.ver`.

**Lo que existe y está verificado.**

| Pieza | Dónde |
|---|---|
| Pantalla «Registrar gasto» con «Ya lo pagué» | `resources/views/gastos/create.blade.php`, `resources/views/gastos/partials/confirmacion.blade.php` |
| Adjuntos con archivo, cámara, arrastrar y pegar | `resources/views/components/gastos-adjuntos.blade.php`, `resources/js/gastos-form.js` |
| Gasto + cuotas + pago + aplicaciones + adjuntos en una transacción | `app/Services/Gastos/RegistrarGasto.php` |
| Pago, reparto por cuota, sobrepago bloqueado y reversión | `app/Services/Gastos/RegistrarPago.php` |
| Pendiente y vencido por cuota | `app/Services/Gastos/SaldosGastos.php` |
| Aritmética en centavos enteros | `app/Services/Gastos/Dinero.php` |
| Ámbito como candado (empresa / personal / pago mixto) | `app/Services/Gastos/AccesoGastos.php` |
| Descarga autorizada de archivos privados | `App\Http\Controllers\Gastos\GastoController::archivo` |
| Interruptor del módulo | `config/gastos.php`, `App\Http\Middleware\ModuloGastosActivo` |
| Permisos | `App\Enums\PermisoSistema` (`gastos.ver`, `gastos.registrar`, `gastos.personales`, `gastos.pagos.registrar`, `gastos.pagos.corregir`) |
| Pruebas | `tests/Feature/Gastos/RegistrarGastoTest.php`, `tests/Feature/Gastos/PagoMixtoTest.php` |

**Lo que NO existe todavía**, y se construye después de aprobar esta presentación: listado de gastos con filtros, ficha del gasto, pantalla de pagos independiente (incluida la de pago mixto, cuyo servicio sí está listo), ajustes/notas de crédito, vinculación con Compras, arranque controlado, informes y exportaciones. Recurrencias y recordatorios son la fase siguiente; planilla, OCR y WhatsApp van después.

**Decisiones de implementación que conviene revisar juntos.** En este primer corte cada gasto pertenece a un solo ámbito, y el destinatario se escribe por nombre: sin catálogo canónico, un pago que agrupa varias obligaciones exige que el nombre coincida exactamente, y no se fusiona nada por parecido. El catálogo de beneficiarios resuelve eso y llega con el listado.

### Comprobaciones pedidas antes de cerrar el corte

**Concurrencia real contra MySQL, no solo reintentos en serie.** Reenviar una petición una detrás de otra prueba idempotencia, no aislamiento. Se corrieron cuatro carreras con DOS PROCESOS PHP simultáneos contra `base_ejemplo`, sincronizados a un instante de arranque pactado (cada proceso calienta conexión y clases, luego espera en bucle hasta la misma marca de tiempo):

| Carrera | Situación | Resultado |
|---|---|---|
| 1 | Dos pagos de 70.00 sobre una cuota de 100.00 | Uno OK (103 ms), el otro **rechazado**: «El pago supera el pendiente de una cuota». Queda 1 aplicación, 70.00 aplicados, 30.00 pendientes. Nadie consumió saldo inexistente. |
| 2 | Dos pagos con la **misma clave** de idempotencia | Los dos procesos devuelven **el mismo pago** (id 5). 1 fila de pago, 1 aplicación, 50.00 pendientes. |
| 3 | Dos pagos de 40.00 sobre una cuota de 100.00 (los dos caben) | **Ambos OK.** 2 aplicaciones, 80.00 aplicados, 20.00 pendientes. El bloqueo serializa sin rechazar pagos legítimos. |
| 4 | Dos POST simultáneos a `gastos.store` con la **misma clave de formulario** (doble clic real, no reenvío) | Los dos redirigen al mismo `?guardado=6`. 1 gasto, 1 cuota, 1 aplicación, 1 pago nuevo. |

El mecanismo es el de {@see RegistrarPago}: transacción, bloqueo de los gastos en orden estable por id, **relectura del pendiente ya dentro del bloqueo**, y el índice único de `clave`. La carrera 1 es la que demuestra el aislamiento; la 3, que el candado no es demasiado grueso.

Estas carreras necesitan MySQL de verdad (SQLite `:memory:` no tiene concurrencia entre procesos), así que no forman parte de la suite: son una verificación manual y quedan documentadas acá con su resultado.

**Presentación en ancho móvil real.** Primero se intentó con capturas de Edge headless a `--window-size=390,844`; **esas capturas engañan** —muestran recorte horizontal que no existe— así que no sirven para juzgar. La medición buena se hizo cargando la pantalla dentro de un `<iframe>` de 390 px en el navegador real (viewport efectivo de 375 px con la barra de desplazamiento) y midiendo el DOM:

- `documentElement.scrollWidth === clientWidth === 375`: **no hay desplazamiento horizontal de página**, ni en el formulario ni en la confirmación. Cero elementos del formulario fuera del viewport.
- La tabla de cuotas de la confirmación desplaza **dentro de su propio contenedor** (`overflow-x: auto`, 310 px visibles sobre 377 px de contenido), igual que las tablas del dashboard existente.
- **37 blancos táctiles medidos, ninguno por debajo de 44 px.** Los elementos de 20 px que aparecen al medir en crudo son etiquetas de texto y controles `sr-only` envueltos en un `label` de 44 px, no blancos táctiles.
- Ningún campo visible baja de 16 px de tipografía (por debajo, iOS hace zoom al enfocar).
- **Defecto encontrado y corregido:** el bloque «Reparto del pago entre cuotas» usaba `grid-cols-2` sin condición y a 375 px dejaba dos columnas de 129 px —la fecha de la cuota se partía en dos renglones y el campo de dinero quedaba angosto—. Pasó a `grid-cols-1 sm:grid-cols-2`: en móvil la etiqueta va arriba y el importe ocupa los 270 px de ancho, con 44 px de alto.

Lo que **sigue sin comprobarse** y hay que mirar en el teléfono de verdad: la cámara (`capture="environment"`), el pegado de capturas, el arrastrar y soltar táctil, el teclado decimal (`inputmode`), el selector nativo de fecha, el zoom del sistema, el lector de pantalla y la navegación por teclado externo. Un iframe de 375 px reproduce el ancho, no el dispositivo.

**Ámbito mixto: lo que el lector restringido SÍ conserva.** Ocultar la cabecera de un pago mixto no puede llevarse por delante la parte que sí es del lector: si alguien ve un gasto empresarial, tiene que poder ver cuánto se le aplicó, o el saldo de su cuota bajaría sin explicación. Se agregó a `AccesoGastos`:

- `aplicacionesVisibles(User, Pago)`: las filas `(pago, cuota, importe)` que caen sobre obligaciones que el usuario alcanza.
- `subtotalVisible(User, Pago)`: su suma en centavos.

Sobre un pago de 100.00 (60.00 empresarial + 40.00 personal), quien solo alcanza empresa obtiene **una** aplicación de 60.00 y subtotal 6000; `pagoCompleto()` sigue devolviendo `false`, así que no ve el total de 100.00, ni la referencia, ni la nota, ni el método, ni la fecha, ni el comprobante compartido, ni el concepto del gasto personal. La pantalla lo refleja con un bloque «Aplicado a este gasto: USD 60.00» sin cabecera. Un pago solo personal devuelve cero aplicaciones y subtotal 0 para ese mismo lector.

**Migración ajena ejecutada en esta sesión.** Junto a la de Gastos corrió `2026_09_04_090000_corregir_cat013_ciudad_delgado_y_cuscatancingo`, que estaba **pendiente de antes** en la base de desarrollo (viene del commit `edb315f`, no de este corte). No estaba señalada de antemano; debió estarlo. Es el hotfix CAT-013 que reagrupa Ciudad Delgado y Cuscatancingo bajo San Salvador **Centro** (23) en vez de **Este** (22). Resultado verificado en `base_ejemplo`:

| Fila | Antes | Después |
|---|---|---|
| `distritos` 158 Ciudad Delgado | agrupación San Salvador Este / CAT-013 22 | San Salvador Centro / **23** (CAT-008 sigue 19) |
| `distritos` 157 Cuscatancingo | agrupación San Salvador Este / CAT-013 22 | San Salvador Centro / **23** (CAT-008 sigue 04) |
| `municipios` 23 Ciudad Delgado | código 22 | **23** |
| `municipios` 26 Cuscatancingo | código 22 | **23** |

Cuatro filas tocadas. `dtes`, `clientes` y `cliente_sucursales` modificados hoy: **0, 0 y 0**. Ningún DTE histórico, sello, correlativo ni estado cambió, como declara la propia migración. En adelante, cualquier migración ajena pendiente se avisa **antes** de correrla y no se mezcla con este corte.

---

## Fase 1 completa — segundo corte (8 de septiembre de 2026)

Aprobada la presentación del formulario, se completó el flujo. Sigue sin commit, push ni despliegue; sin tocar producción, Drive ni firmware.

### Lo que existe ahora

| Pantalla / capacidad | Ruta | Archivo principal |
|---|---|---|
| Listado con pestañas, búsqueda, filtros y totales por ámbito | `gastos.index` | `resources/views/gastos/index.blade.php`, `App\Services\Gastos\ConsultaGastos` |
| Ficha: cuotas, pagos, saldo, ajustes, documentos e historial | `gastos.show` | `resources/views/gastos/show.blade.php` |
| Alta con «Ya lo pagué» | `gastos.create` / `gastos.store` | `App\Services\Gastos\RegistrarGasto` |
| Pagos posteriores y abonos, multiobligación | `gastos.pagos.create` / `.store` | `App\Http\Controllers\Gastos\PagoController` |
| Ficha del pago con subtotales por ámbito | `gastos.pagos.show` | `resources/views/gastos/pagos/show.blade.php` |
| Adjuntar documentos y comprobantes después | `gastos.documentos`, `gastos.pagos.comprobantes` | `App\Services\Gastos\AdjuntarDespues` |
| Reversión trazable de pagos | `gastos.pagos.revertir` | `App\Services\Gastos\RegistrarPago::revertir` |
| Ajustes: notas de crédito/débito y correcciones | `gastos.ajustes.store` / `.revertir` | `App\Services\Gastos\RegistrarAjuste` |
| Vínculo opcional con Compras | `gastos.compras.elegir` / `.vincular` | `App\Services\Gastos\VincularCompra` |
| Informes de pendientes y pagos, con exportación CSV | `gastos.informes` / `.exportar` | `App\Services\Gastos\InformeGastos` |

Permisos nuevos: `gastos.administrar` (ajustes) y `gastos.exportar` (sacar datos del sistema). Tablas nuevas: `gastos_ajustes` y `gastos_fuentes`.

### Decisiones que conviene conocer

**El ajuste se aplica a la CUOTA, no a la obligación.** El diseño hablaba de ajustes por obligación, pero el pendiente y el vencido ya se calculan cuota por cuota; colgar el ajuste del gasto obligaba a inventar a qué vencimiento pertenece. Con una sola cuota —el caso normal— es exactamente lo mismo.

**Un documento origina UNA deuda, y lo garantiza la base.** La columna `gastos_fuentes.deuda_unica` vale el id del documento cuando el papel es «deuda» y NULL en cualquier otro caso; como los NULL no chocan en un índice único, eso da «único solo para deuda» sin índices parciales, que no son portables. No es una consulta previa: dos peticiones simultáneas no pueden pasarla las dos.

**Retenciones (07) y notas de crédito (05) nunca se convierten en deuda.** El total del 07 es monto sujeto a retención y la 05 resta. La pantalla lo dice y el servicio lo bloquea.

**«Saldada por ajuste» no es «Pagada».** Un gasto cancelado solo con nota de crédito no aporta a dinero pagado y se informa distinto. Confundirlos falsearía cualquier informe de egresos.

**El informe de pagos lleva una fila por APLICACIÓN.** Un pago que cubre dos obligaciones aparece dos veces con lo que tocó a cada una; para contar salidas de dinero hay que agrupar por la columna «Pago». Va dicho en la pantalla y en la cabecera del CSV.

### Errores encontrados y corregidos en este corte

1. **`CAST(... AS INTEGER)` no es MySQL.** Toda la aritmética en centavos del listado funcionaba en las pruebas (SQLite) y reventaba en desarrollo. Ahora el nombre del tipo se elige por driver en un único sitio (`ConsultaGastos::centavos`).
2. **Totales del informe multiplicados por cien.** `totalesPorAmbito` decidía con `is_int()` si un importe ya venía en centavos, pero el driver devuelve los enteros de SQL como CADENA. Se mostraba 465735.00 donde había 4657.35. Ahora se declara explícitamente con `enCentavos:`.
3. **Un gasto vencido mostraba «Sin fecha».** `resumen()` solo registraba la próxima fecha cuando la cuota aún no había vencido, así que la fila decía «Vencida» y «Sin fecha» a la vez. Las tres tienen prueba de regresión.

### Verificación

- **77 pruebas de Gastos** (`RegistrarGastoTest`, `PagoMixtoTest`, `FlujoGastosTest`), 312 aserciones.
- **Concurrencia real contra MySQL con dos procesos simultáneos**, además de las cuatro carreras del corte anterior: un pago de 70 contra una nota de crédito de 70 sobre la misma cuota de 100. Gana uno, el otro se rechaza, y el saldo nunca queda negativo. Los dos servicios bloquean el gasto en el mismo orden, así que no pueden cruzarse.
- **Móvil medido a 390 px REALES.** Advertencia de método: `msedge --headless --window-size=390` **no** da un viewport de 390 —`window.innerWidth` sale 496— así que sus capturas «móviles» están recortadas y no sirven para juzgar el ancho. La medición buena se hace cargando la pantalla en un `<iframe>` de 390 px y midiendo el DOM. Resultado final en las cuatro pantallas: sin desplazamiento horizontal de página y **cero blancos táctiles por debajo de 44 px**. Las tablas anchas (cuotas, informes) desplazan dentro de su propio contenedor, igual que las del dashboard existente.
- Defectos de móvil encontrados así y corregidos: la tabla de totales medía 525 px y quedaba recortada **sin barra para alcanzarla**; las insignias de situación se montaban sobre los importes; cuatro `summary` no llegaban a 44 px; y el formulario de ajuste vivía dentro de la tabla desplazable, o sea detrás del scroll horizontal en un teléfono. Ahora es una sección propia a ancho completo.

### Datos de demostración

`php artisan db:seed --class=GastosDemoSeeder` siembra casos representativos marcados con `[demo]`: vencido con abono, cuotas con fechas distintas, vence hoy, proveedor sin documento fiscal, impuesto de la contadora, pagado completo, saldado solo con nota de crédito, esperando monto, dos personales, un **pago mixto** empresa+personal del mismo destinatario y un pago **revertido**. Es idempotente y se niega a correr fuera de `local`/`testing`.

**No es una importación histórica.** La fecha de arranque y la carga de pendientes reales siguen sin decidirse, y cuando se decidan será un proceso revisado documento por documento.

### Lo que sigue sin comprobarse

En el teléfono de verdad: cámara, pegado de capturas, arrastrar y soltar táctil, teclado decimal, selector nativo de fecha, zoom del sistema, lector de pantalla y teclado externo. Un iframe reproduce el ancho, no el dispositivo.

Aparte, el **topbar compartido** (`layouts/navigation.blade.php`) es una fila flex sin `min-w-0` ni `flex-wrap`: con un nombre de usuario largo puede apretarse en pantallas estrechas. Afecta a todas las áreas, no solo a Gastos, y por eso no se tocó en este corte: cambiarlo es una modificación del marco común y merece decidirse aparte.

---

## Fase 2 — recurrencias y avisos (9 de septiembre de 2026)

Cerrada la fase 1, se construyó lo que sigue: reglas recurrentes, bandeja interna de avisos y resúmenes por correo. Sigue sin commit, push ni despliegue; sin tocar producción, Drive ni firmware. Planilla queda para su fase.

### Puntos de fase 1 que se confirmaron antes de abrir esta

| Punto | Dónde vive | Cómo se comprueba |
|---|---|---|
| Saldo a fecha de corte | `ConsultaGastos::cuotasConSaldo($corte)` filtra pagos por `fecha` y reversiones por `revertido_at > fin del corte`; los ajustes por `created_at` | `test_un_pago_posterior_al_corte_no_altera_el_saldo_de_esa_fecha`, `..._una_reversion_posterior_...`, `..._un_ajuste_posterior_...` |
| Notas de crédito sin doble aplicación y remanente identificado | `VincularCompra::creditoDelDocumento` acumula lo aplicado contra el total del documento; el control corre dentro de la transacción con el documento bloqueado | `test_la_misma_nota_de_credito_no_se_puede_descontar_dos_veces`, `test_una_nota_de_credito_se_puede_repartir_y_el_sobrante_queda_identificado`, `test_revertir_un_ajuste_devuelve_su_parte_al_remanente_de_la_nota` |
| Completar gastos sin monto | `CompletarMonto` completa el registro existente y se niega si ya tiene importe o cuotas | `test_completar_el_monto_deja_la_obligacion_lista_para_pagar_sin_crear_otra`, `..._dos_veces_no_duplica_cuotas`, `..._no_sirve_para_cambiar_un_importe_que_ya_existia` |
| Preservación de cambios ajenos tras Pint | El árbol probado tiene 337 inserciones y 2 supresiones; las 2 son del firmware (buzzer), trabajo previo y ajeno a Gastos. Los tres PHP versionados tocados (`PermisoSistema`, `bootstrap/app.php`, `routes/web.php`) son **solo adiciones**: Pint no reformateó nada de terceros | `git diff --numstat` sobre el árbol de la corrida en verde |

Los cuatro estaban cubiertos por la corrida completa del 2026-09-08 (5245 pruebas, 0 fallos). Esa evidencia se conservó en `evidencias/suite-2026-09-08/`, fuera del temporal y excluida de Git.

### Lo que existe ahora

| Capacidad | Ruta / comando | Archivo principal |
|---|---|---|
| Reglas recurrentes: alta, edición versionada, ficha | `gastos.reglas.*` | `App\Services\Gastos\Recurrencia\AdministrarReglas` |
| Calendario: semanal, quincenal, mensual, anual | — | `App\Services\Gastos\Recurrencia\CalendarioRecurrencia` |
| Generación idempotente de obligaciones | `gastos.reglas.generar`, `gastos:generar-recurrentes` | `App\Services\Gastos\Recurrencia\GenerarObligaciones` |
| Pausar, reanudar, cancelar, omitir un período | `gastos.reglas.pausar` / `.reanudar` / `.cancelar` / `.omitir` | `AdministrarReglas` |
| Bandeja interna de avisos | `gastos.avisos.index` | `App\Services\Gastos\Avisos\ArmarAvisos` |
| Preferencias por persona | `gastos.avisos.preferencias` | `App\Http\Controllers\Gastos\AvisoController` |
| Resumen por correo, simulado fuera de producción | `gastos:avisos` | `App\Services\Gastos\Avisos\EnviarResumenes` |

Permiso nuevo: `gastos.recurrencias`. Tablas nuevas: `gastos_reglas`, `gastos_regla_versiones`, `gastos_ocurrencias`, `gastos_preferencias_avisos`, `gastos_avisos`, `gastos_resumenes`. Interruptores nuevos, los dos apagados: `GASTOS_RECURRENCIAS_AUTO` y `GASTOS_AVISOS_AUTO`.

### Decisiones que conviene conocer

**Crear una obligación recurrente NUNCA la marca pagada.** Es la regla que gobierna todo el generador y tiene dos pruebas dedicadas. Que el alquiler se pague todos los meses no autoriza a asentar que este mes ya se pagó: sería declarar una salida de dinero que nadie hizo y contaminaría el informe de pagos del período. El generador crea el gasto y su cuota, y ahí termina.

**«Quincenal» se implementó como DOS FECHAS AL MES, no como «cada catorce días».** Es la lectura habitual de «quincena» para pagos, y es la que compone con «una obligación por período»: las claves lógicas son `2026-03-Q1` y `2026-03-Q2`, ancladas al mes. Cada catorce días no se ancla a nada —deriva entre meses— y obligaría a otra clave de período. Si lo que se quiere es bisemanal, es un cambio acotado en `CalendarioRecurrencia`, pero cambia el modelo de períodos y conviene decidirlo antes de que existan reglas quincenales en uso.

**Una por período, garantizado por la base y por dos caminos.** `gastos_ocurrencias` tiene índice único `(regla_id, periodo)`, y además la `clave` del gasto se deriva por UUIDv5 de la clave de la regla más el período, contra el índice único de `gastos.clave`. La clave lógica **no incluye la versión de la regla**: editar la plantilla no puede reabrir un mes ya generado.

**Monto variable no inventa cifras.** Genera una obligación sin importe y sin cuota, que cae en «Por completar»: no suma a pendiente, no suma a vencido y no se puede pagar. El vencimiento esperado se guarda en la ocurrencia, no en una cuota falsa. Su aviso es «Falta el monto», que habla del recibo que no llegó, nunca «Vencido».

**Días 29–31.** Se recortan al último día del mes y nunca se empujan al siguiente: pasar el alquiler del 31 de abril al 1 de mayo movería la deuda de mes. El 29 de febrero cae al 28 en año no bisiesto. La política se muestra al configurar la regla.

**Editar no reescribe el pasado.** Cada cambio crea una versión con fecha de efecto; la ocurrencia guarda con qué versión nació. Subir el alquiler en marzo no cambia lo que se debía en enero. Cambiar la **frecuencia** de una regla que ya generó exige poner «vigente desde» después del último período generado, porque las claves lógicas de una frecuencia y otra no son comparables.

**Pausar, cancelar y omitir no borran nada.** Frenan lo que viene. Una obligación mal generada se resuelve por los caminos de fase 1 —ajuste o reversión—, nunca desde la regla.

**Ventana de recuperación acotada (62 días).** Tras una caída, la corrida siguiente recupera lo que falta pero no el histórico entero: una regla mensual vigente desde hace tres años generaría 36 deudas que nadie pidió. Lo que queda fuera se **informa** por salida y en la pantalla, y espera decisión humana.

**Nunca un correo vacío, y nunca «todo salió bien».** Si al armarlo no hay pendientes, no se manda y **no se guarda fila**: no existe el estado «vacío» porque no existe el resumen. El contenido se recalcula justo antes de enviar —no sale de la bandeja— para no reclamar por correo una deuda que se pagó esa mañana.

**Correo simulado fuera de producción.** Pasa por el mismo `CandadoCorreoReal` que el correo fiscal y el de compras. `simulado` se distingue de `enviado` a propósito: «no hubo error» no significa «llegó». Un fallo posterior a la aceptación del servidor queda como `fallido` y hay que mirarlo antes de reenviar; una bandera no evita los duplicados de transporte y no se promete que lo haga.

**Tres llaves para que algo pase solo.** El módulo encendido, el interruptor del proceso encendido, y el planificador corriendo. Con dos de tres no genera ni envía. El comando comprueba el interruptor además del `when()` del planificador, así que una invocación manual con `--aplicar` tampoco escribe si está apagado. Los dos comandos son **dry-run por defecto**.

### Un defecto encontrado y corregido

**`HAVING` sin `GROUP BY` revienta en SQLite.** La consulta nueva de cuotas abiertas copiaba el patrón `havingRaw('saldo > 0')` y fallaba con «HAVING clause on a non-aggregate query». Ahora filtra con `whereRaw` repitiendo la expresión, que es portable entre los dos motores. Salió en la primera corrida de las pruebas nuevas, no en producción.

### Verificación

- **Suite completa en verde sobre el árbol final**: 5333 pruebas, 21 239 aserciones, **0 fallos y 0 errores**, 56 min (evidencia en `evidencias/suite-2026-09-10/`; incluye el arreglo del menú y sus 10 pruebas). Comprobado contra el JUnit XML, no solo contra el resumen impreso. Las 5 deprecaciones de PHPUnit son las de siempre y no cambiaron en ninguna de las tres corridas. Evidencia en `evidencias/suite-2026-09-09b/` (la corrida previa, anterior a «quincenal explícito», queda en `evidencias/suite-2026-09-09/`).
- **88 pruebas nuevas** (`RecurrenciaTest` 49, `AvisosTest` 29, `BaseSinFase2Test` 10), y **178 de Gastos en total**.
- Pint pasado **solo sobre los archivos tocados**: cinco reformateados, ninguno ajeno.
- **Verificado también contra MySQL 8.4.3**, sobre una base aislada clonada de desarrollo: 44 comprobaciones, 0 fallas. Ver más abajo.

### Validación contra MySQL (9 de septiembre de 2026)

Cerrada la limitación que quedaba abierta: la suite corre sobre SQLite `:memory:`, y la fase 1 ya tuvo un defecto que pasaba ahí y reventaba en MySQL. **44 comprobaciones contra MySQL 8.4.3, 0 fallas.** Evidencia y scripts en `evidencias/mysql-2026-09-09/`.

**Sobre una base aislada, clonada de desarrollo con sus datos reales.** No una base vacía: el riesgo de esta migración es que agrega una columna con clave foránea a `gastos_eventos`, que ya tenía filas. El clon llegó con 17 gastos, 7 pagos y 26 eventos, y la migración corrió encima; los 26 eventos sobrevivieron.

**La base de uso no se tocó, y se comprobó al terminar:** `base_ejemplo` sigue con 0 tablas de fase 2, sin la columna `regla_id`, con sus 17 gastos y 26 eventos, y su última migración sigue siendo la de fase 1. Se usó `migrate`, nunca `migrate:fresh`; la conexión se desvió por variable de entorno sin editar `.env`; se confirmó con `db:show` antes de ejecutar; y el script de validación lleva un candado que lo aborta si la base conectada no es la aislada.

**Lo que confirmó el motor real y SQLite no podía confirmar:** el índice único `(regla_id, periodo)` rechaza el duplicado desde MySQL —probado con un `INSERT` directo, no con una comprobación de PHP—; las tres consultas del listado (`cuotasAbiertas`, `esperandoMontoAbiertos`, `totales`) corren y devuelven centavos enteros; y el `whereRaw` con `COALESCE` que reemplazó al `HAVING` funciona en los dos motores.

**Un tropiezo del entorno, no del código:** al reclonar la base, la caché de permisos de Spatie sobrevive al cambio y apunta a ids que ya no existen. Hay que correr `permission:cache-reset` después de clonar. Queda anotado en el README de la evidencia porque volverá a pasar.

### «Quincenal», ahora explícito

Se mantiene como **dos fechas del mes, las dos configurables**, y se hizo explícito en tres lugares para que nadie lo confunda con «cada 14 días»:

- `CalendarioRecurrencia::POLITICA_QUINCENAL` explica la diferencia y **por qué importa**: dos fechas del mes están ancladas al mes, así que cada período tiene clave lógica estable (`2026-03-Q1`, `2026-03-Q2`) y siempre hay dos por mes. Cada 14 días deriva —unos meses caen dos vencimientos y otros tres— y «la quincena de marzo» dejaría de significar algo. Como la clave lógica es lo que garantiza «una obligación por período», mezclarlas rompería el candado contra duplicados. El texto se muestra en el formulario cuando se elige esa frecuencia.
- **«Último día del mes» se elige por su nombre.** Los días del mes pasaron de casilla numérica a selector, con la última opción rotulada «Último día del mes». Se codifica como 31 —que es, por definición, el último día de cualquier mes que lo tenga, y en los que no, la política de recorte lo lleva igual al último—, pero el operador ya no tiene que deducir que escribir 31 significa el 28 en febrero.
- Los rótulos dicen «dos fechas del mes» y no «dos veces al mes», que se puede leer como «cada dos semanas». La descripción en palabras de una regla quedó: «Dos fechas del mes: el día 15 y el último día».

Tres pruebas nuevas lo fijan: último día en febrero (cae el 28 y sigue siendo `2026-02-Q2`), doce vencimientos en seis meses repartidos dos por mes, y que el selector nombre la opción. Total del módulo: **168 pruebas de Gastos**.

### Un 500 en todo el sistema, y qué se cambió por eso (10 de septiembre de 2026)

`facturacion.test` respondía **500 en todas las pantallas**, dashboard incluido. La causa: el menú lateral contaba avisos sin leer con una consulta directa a `gastos_avisos` escrita dentro del Blade. En una base con las migraciones de fase 2 sin aplicar esa tabla no existe, la consulta reventaba, y como el menú se dibuja en todas las pantallas se llevó puesto el sistema entero —incluido el dashboard, que no tiene nada que ver con Gastos—.

**La lección no es «faltaba una tabla».** Son dos reglas que ahora el código impone:

1. **Ninguna vista compartida consulta tablas de un módulo.** Un contador decorativo no puede decidir si una pantalla existe. Ahora responde `App\Services\Gastos\InstalacionGastos`, que devuelve cero cuando no hay de dónde contar.
2. **El código de un módulo no da por sentado su propio esquema.** Entre desplegar el código y correr las migraciones hay una ventana —de días, si esperan autorización— y durante esa ventana el resto del sistema tiene que seguir funcionando igual.

Lo que se agregó: el servicio `InstalacionGastos` (memoizado por petición), el middleware `ModuloGastosFase2` sobre todas las rutas de recurrencias y avisos, y una pantalla propia que explica qué falta y que hay que correr `php artisan migrate`.

**Responde 503 y no 404.** El 404 del interruptor del módulo significa «esto no existe para vos», que es una decisión; acá el módulo está encendido y lo que falta es migrar. La acción a tomar es distinta, así que confundirlos mandaría a quien administra a buscar un interruptor que ya está encendido. Y no se usa la página 503 de Laravel: la comparte el modo mantenimiento y además no muestra el motivo.

`BaseSinFase2Test` fija el escenario completo con 10 pruebas —borra las seis tablas y comprueba que el dashboard abre, que fase 1 sigue entera, que el menú no ofrece lo que va a fallar, y que las rutas dan 503 con explicación—. El módulo queda en **178 pruebas**.

### Fase 2 instalada en desarrollo (10 de septiembre de 2026)

Aplicada en `base_ejemplo` con respaldo previo (`backups/base_ejemplo-antes-gastos-fase2-20260910-165157.sql`, 96 tablas), conexión confirmada con `db:show`, y `--path` al fichero exacto para que ninguna migración ajena pudiera colarse. Nunca `migrate:fresh`. **Todos los conteos idénticos antes y después**; las seis tablas nuevas nacieron vacías y `gastos_eventos.regla_id` quedó NULL en las 26 filas existentes.

Un paso que no es migración: `gastos.recurrencias` es un permiso nuevo y los permisos viven en `PermisoSistema` + `RolesSeeder`. Sin él, `/gastos/reglas` daba 403 y no había nada que verificar. Se comparó en seco qué cambiaría el seeder —solo agregar ese permiso a `administrador`, sin quitar nada ni tocar otros roles— y el resultado fue exactamente ese: 66 → 67 permisos.

Verificadas contra la base real, recorriendo rutas, middleware y Blade completo: dashboard, Gastos, Informes, Recurrentes, alta de regla, Avisos y Preferencias, todas 200. Correo simulado (`mail.default=log`), los dos procesos automáticos apagados, y cero reglas, avisos y resúmenes creados. Evidencia en `evidencias/fase2-en-dev-2026-09-10/`.

### Lo que NO se hizo, y por qué

- **No se importan pendientes automáticamente** ni se toca nada histórico. Sigue sin decidirse la fecha de arranque, y cuando se decida será un proceso revisado documento por documento.
- **Planilla** queda para su fase, con salarios fuera de cualquier correo general.
- **Sin datos de demostración de recurrencias.** `GastosDemoSeeder` no se amplió: sembrar reglas activas en una base de demostración crearía obligaciones al primer `gastos:generar-recurrentes` de alguien que solo quería mirar. Si se quiere, va con las reglas pausadas.
- **Sin cola para los correos.** El envío es sincrónico dentro del comando. Con el volumen de una empresa chica alcanza; si crece, el outbox ya está y encolar es un cambio local.

## Reorganización de la navegación (10 de septiembre de 2026)

Gastos había crecido hasta tener listado, alta, pagos, informes y repeticiones. Como grupo dentro de Facturación obligaba a elegir entre un menú larguísimo o esconderle pantallas, así que pasó a ser **área propia**, con el mismo mecanismo que Producción y Cobros (`App\Enums\AreaSistema`): permiso de entrada `gastos.ver`, interruptor `GASTOS_ENABLED`, aterrizaje en «Por pagar» y su propio `sidebar-gastos.blade.php`.

Va **después** de Facturación en el enum a propósito: `visiblesPara()` devuelve en orden y el primero decide dónde aterriza cada quien, así que los cuatro roles históricos siguen aterrizando donde siempre. Efecto secundario buscado: quien tiene `gastos.ver` sin `dte.ver` antes recibía 404 en `/dashboard` y ahora aterriza en Por pagar.

### El menú: cuatro filas

**Por pagar · Historial de pagos · Gastos que se repiten · Informes.**

«Registrar gasto» y «Registrar pago» **salieron del menú**: son acciones que se hacen estando en algún sitio, así que viven como botones en esas pantallas. Un menú que mezcla «dónde estoy» con «qué hago» obliga a leerlo entero cada vez. «Avisos» tampoco es una fila —un aviso no es un lugar al que ir— y se integró arriba de Por pagar.

### Por pagar

Conserva **«Ya saldados»** como filtro, e incluye los saldados por nota de crédito: la pestaña pregunta «¿esto ya no se debe?» y hay dos maneras de dejar de deberlo. La fila distingue cuál fue; el nombre no podía decir «Pagados» y mentir.

La **franja de avisos** es una sola línea: cuántos y de qué tipo, cada cifra enlazando a su filtro. **«Ya lo vi» marca lectura y nada más**: no salda gastos, no los quita de pendientes y no cancela recordatorios futuros —si la deuda sigue vencida, la semana que viene vuelve a avisar—. Y abrir la pantalla **no** marca nada: silenciar un vencido por pasar por ahí es justo lo que no puede ocurrir.

### Historial de pagos

Pantalla nueva. Responde «¿qué dinero salió?», que no es la pregunta de Por pagar. Tres cosas que hace bien:

- **Respeta el alcance**, y el recorte va en la consulta: un pago mixto se muestra a quien solo alcanza lo empresarial con **su subtotal**, nunca con el importe total ni la referencia. El total delataría el importe personal aunque se ocultara su fila.
- **Distingue los revertidos**: se listan y se marcan, pero no suman al total; se cuentan aparte para que nadie crea que desaparecieron.
- **Separa las monedas**: cada una con su total, sin conversión.

### «Este gasto se repite»

Una casilla en el formulario de siempre —sin asistente y sin pasos— y un botón en la ficha de cualquier gasto. En los dos casos **no se vuelve a capturar nada**: la repetición toma destinatario, concepto, categoría, ámbito, moneda, responsable e importe del propio gasto. Lo único que se pregunta es cada cuánto y si el importe será fijo o cambiará.

**Se puede elegir «cambia cada vez» aunque el gasto ya tenga monto.** El recibo de la luz de este mes tiene una cifra concreta y el del mes que viene no se sabe; en ese caso la repetición guarda importe NULL y los siguientes nacen esperando monto. El importe **no** se pregunta de nuevo en ninguno de los dos casos.

Al convertir un gasto que ya existe, tres garantías, cada una con su prueba:

1. **No se duplica su período.** El gasto ocupa su propia ocurrencia, así que la generación lo encuentra resuelto y nunca crea un gemelo. Sin esto, alguien pagaría dos veces el mismo alquiler.
2. **No se tocan sus pagos.** El servicio no escribe una fila en `gastos_pagos` ni en aplicaciones. Si estaba pagado, sigue pagado.
3. **No se inventan históricos.** `vigente_desde` se fija al inicio del período del propio gasto, así que no hay ningún período anterior dentro del alcance.

Es idempotente: la clave se deriva del gasto, y la comprobación va **antes** que la de «¿es repetible?», porque después de convertirlo el gasto queda ligado a su propia repetición.

**Los gastos con varias cuotas quedan fuera**, y la ficha lo explica: eso es un plan de pagos, no algo que se repita, y no está claro si lo que vuelve es el gasto entero o cada cuota.

**El próximo vencimiento se muestra en grande** en la ficha de la repetición, y en el mensaje de confirmación al convertir.

### Sin cambiar URL ni nombres técnicos

Las tablas siguen siendo `gastos_reglas`/`gastos_ocurrencias`, las clases `Regla`/`Ocurrencia` y las rutas `gastos.reglas.*`. Solo cambian las palabras visibles, igual que el área se llama «Producción» y por dentro es `planta`. Renombrar habría costado churn en pruebas y vistas —y una migración sobre una base recién migrada— a cambio de nada para quien usa el sistema.

### Verificación

- **Suite completa en verde**: 5356 pruebas, 21 328 aserciones, 0 fallos y 0 errores (1 h 04 min). Evidencia en `evidencias/suite-2026-09-10b/`.
- **201 pruebas de Gastos** en verde, 682 aserciones. Entre ellas, `RepetirYHistorialTest` (21) y `BaseSinFase2Test` (12, ahora con el área nueva).
- **El escenario sin fase 2 sigue cubierto**: con las seis tablas borradas, el dashboard abre, todo lo de fase 1 funciona, el menú de Gastos no ofrece «Gastos que se repiten» y sus rutas responden 503 con explicación.
- Comprobado **contra la base real de desarrollo**: las nueve pantallas en 200, las cuatro filas del menú presentes, y verificado que «Registrar gasto», «Registrar pago» y «Avisos» **no** son filas del menú.
- Correo simulado, procesos automáticos apagados, cero resúmenes.


## Los cuatro candados de cierre de fase 3 (11 de septiembre de 2026)

Antes de cerrar Planilla se revisaron cuatro cosas concretas. **Tres encontraron un
agujero de verdad** y la cuarta estaba bien por dentro y mal escrita por fuera. Se
documentan acá porque las cuatro son del tipo que no se nota hasta que alguien la
aprovecha.

Las prueba `tests/Feature/Planilla/CandadosPlanillaTest.php` (20 pruebas).

### 1. La puerta de atrás por `/gastos` — era real

Las obligaciones que crea una planilla **son gastos normales**, y tienen que serlo: es
lo que hace que el dinero viva en un solo sitio y que los saldos, los pagos y los
informes sean los de siempre. El efecto colateral es que el sueldo de cada persona era
una fila corriente del listado de Gastos.

Planilla protegía sus propias pantallas. No protegía el dato. Quien tuviera
`gastos.ver` —el perfil de quien lleva proveedores, sin nada laboral— abría `/gastos` y
leía cuánto cobra cada quien; con `gastos.pagos.registrar` podía además pagarlo, y con
`gastos.pagos.corregir`, revertirlo.

**La solución no fue tapar pantallas.** Se introdujo `ProteccionDeGastos`, una interfaz
que **define Gastos** y que responde quien tenga el contexto —hoy,
`ProteccionPlanilla`—. Gastos pregunta «¿este usuario alcanza este gasto?» sin aprender
jamás qué es un sueldo. Si Planilla no está instalada, `SinProteccion` responde que no
hay nada que esconder y Gastos funciona exactamente igual que antes.

El candado vive en **`AccesoGastos::ver()`**, que es el embudo por el que pasan abrir la
ficha, registrar un pago y revertirlo. Y se **recorta en la consulta**, no al pintar:

| Dónde | Por qué ahí y no en la vista |
|---|---|
| `ConsultaGastos::base()` | el listado y sus pestañas |
| `recortarPorAmbitoYFiltros()` | los totales y el contador de «por completar» |
| `cuotasPagables()` | el selector de «Registrar pago» |
| `basePagos()` | el historial: entra con `JOIN`, así que el pago de sueldo desaparece entero |
| `beneficiariosConSaldo()`, `categorias()` | los desplegables, que delatan nombres |
| `InformeGastos::pendientes()` y `pagos()` | informes y **CSV**, la salida más fácil de llevarse |

Ocultar la fila y dejar el total habría sido teatro: de un total y un contador se deduce
el importe que falta.

**Los avisos quedan fuera para todo el mundo**, incluso para quien sí tiene el permiso.
Se arman una vez por ámbito y después se reparten entre destinatarios distintos: cuando
llega el momento de recortar por persona, el aviso ya está escrito con el nombre y la
cifra dentro. Las obligaciones de planilla tienen su propia pantalla, que dice lo mismo
y solo la abre quien corresponde.

**Pagar exige ahora los dos permisos**, `planilla.pagar` **y** `planilla.salarios`, y la
ruta lo dice explícitamente. Para declarar que salió dinero hay que saber cuánto era.
Antes esto quedaba implícito —el rechazo llegaba al final del recorrido— y el usuario
recibía un 403 sin saber cuál de los dos permisos le faltaba. **No se agregó ningún
permiso nuevo**: los dos ya existían.

#### Por qué una subconsulta y no una lista de ids

La primera versión devolvía `array<int>` para un `whereNotIn`. Se cambió por una
subconsulta por dos razones, y la segunda es la seria:

1. La lista crece sin techo: una planilla por semana durante años son miles de enteros
   viajando dentro de cada consulta.
2. **Habría que decidir cuándo recalcularla.** Confirmar una planilla la deja rancia en
   el acto, y una lista rancia acá no es un número mal pintado: es un sueldo que se ve.

Una subconsulta la evalúa el motor en el momento y siempre ve lo último. Hay una prueba
dedicada a eso: consultar el listado **antes** de que exista la planilla y otra vez
después, dentro de la misma petición.

### 2. El reintento del lote — era real

Saltar a quien ya cobró del todo cubre el caso fácil. No cubría estos dos:

- **Abono parcial.** El lote le pagó 60 de 100 a alguien porque eso era lo que quedaba.
  Si después ese saldo se mueve —una reversión, otro abono—, al reintentar se pedía el
  **mismo pago con otro importe**. Gastos lo rechazaba con razón («Este registro ya fue
  utilizado con otros datos»), pero al hacerlo **tumbaba el lote entero**, incluidos los
  que todavía no habían cobrado.
- **Dos peticiones a la vez.** Ambas leen el mismo pendiente e intentan insertar la
  misma clave. Una gana; la otra se estrellaba contra el índice único con un error de
  base de datos en la cara del usuario.

La corrección son tres cosas, y las tres hacen falta:

1. **Se pregunta por la clave, no por el saldo.** Si ya existe un pago con la clave
   derivada `uuid5(NS_LOTE, clave_lote|detalle_id)`, este lote ya atendió a esa persona.
   El saldo que tenga hoy da igual.
2. **El lote se bloquea** (`lockForUpdate`) mientras se procesa, así dos peticiones
   simultáneas se ponen en fila en vez de competir.
3. **Se tolera igual la violación de unicidad**, porque entre dos motores y dos niveles
   de aislamiento no se puede prometer que el bloqueo llegue primero. Si salta, se
   recupera el pago que ganó y se sigue.

El resultado ahora distingue `repetidos` (ya atendidos por **este** lote) de `omitidos`
(sin saldo por otra vía). La pantalla los suma —para quien mira, el lote no le pagó
ahora y punto—; el rastro y las pruebas los separan, que es donde la diferencia importa.

#### Lo que solo apareció con dos procesos de verdad

Con las tres cosas puestas, la prueba en SQLite pasaba. **Dos procesos reales contra
MySQL, arrancando al mismo instante de reloj, no.** Y encontraron dos problemas
seguidos que ninguna prueba de un solo proceso podía encontrar:

**Primero, un interbloqueo de InnoDB.** Uno pagaba a los tres y el otro moría con
`Deadlock found when trying to get lock`. El dinero quedaba bien —nadie cobró dos
veces—, pero la segunda persona recibía un error de base de datos, y en una pantalla de
pagos eso es inaceptable: mirando ese error no hay forma de saber si se pagó o no.

Nacía de crear el lote **dentro** de la transacción larga: los dos procesos intentaban
insertar la misma clave, y el segundo esperaba en el índice único mientras el primero
seguía tomando candados de usuario, gasto y cuota persona por persona. La corrección
fue sacar la creación del lote a **su propia transacción corta**, que confirma enseguida,
y reintentar la transacción larga si hay interbloqueo. Reintentar es seguro **porque** la
clave derivada hace idempotente el bucle; sin eso, un reintento automático sería la peor
idea posible acá.

**Y después, la foto de REPEATABLE READ.** Sin el interbloqueo, el segundo proceso
falló con `Duplicate entry`… pese a que el código ya toleraba esa violación. La
recuperación hacía «¿existe la fila?» con una lectura corriente, y el nivel de
aislamiento por defecto de MySQL responde con la **foto del inicio de la transacción**,
anterior a que el otro proceso confirmara. Preguntaba si existía la fila justo cuando el
error decía que sí, y le contestaban que no.

La recuperación usa ahora `lockForUpdate`, que fuerza una lectura del estado actual. Con
eso, dos procesos simultáneos dan:

```
A: pagos=0  repetidos=3  omitidos=3
B: pagos=3  repetidos=0  omitidos=0
```

Uno paga, el otro reconoce que ya está pagado. Sin errores y sin un solo pago duplicado.

Vale la pena decirlo claro: **estas dos las habría dado por buenas cualquier suite en
SQLite.** Es la misma lección del `CAST … AS SIGNED` de fase 1, y por eso la validación
contra MySQL aislado no es opcional.

Una prueba comprueba explícitamente que la idempotencia **no** se convierte en un pase
libre: un **lote distinto**, con otra clave, sobre gente ya pagada, no paga nada.

### 3. El anticipo ya descontado — era real

Un pago registrado como anticipo y ya descontado en una planilla **no se podía revertir**
sin dejar un desastre: revertirlo dice que ese dinero nunca salió, mientras la planilla
ya se lo descontó a la persona. Quedaría cobrada de menos y sin rastro de por qué.

Nada lo impedía. Ahora lo impide `impedimentoParaRevertir()`, y el mensaje dice qué
desatar primero: anular la planilla que lo descontó —lo que libera las aplicaciones— y
después revertir el pago. Hay una prueba de esa secuencia completa.

**Es un impedimento, no un permiso**, y por eso son dos métodos distintos. A quien tenga
todos los permisos le pasa exactamente lo mismo; responderle «no tenés permiso» lo
mandaría a buscar un rol que no le falta.

### 4. La anulación — bien por dentro, mal escrita por fuera

La implementación **ya era correcta**: el ajuste se graba como `tipo => 'correccion'`,
que es interno y no fiscal. Lo que estaba mal era el texto, que decía «nota de crédito»
en el servicio, el controlador, la vista y las rutas.

Y no es vocabulario. Una **nota de crédito** es un documento tributario que se le emite
a un tercero y se transmite a Hacienda. Acá no hay tercero: la empresa se corrige a sí
misma un sueldo mal calculado. Un sueldo anulado que apareciera como nota de crédito
ensuciaría la lista de documentos fiscales con algo que jamás se emitió, y tarde o
temprano alguien lo cuadraría contra lo transmitido y no le saldría. Corregido en los
cuatro sitios; se agregó una prueba que exige `tipo === 'correccion'` y que no exista
ni un `nota_credito`.

`direccion => 'credito'` sí se conserva: describe hacia dónde mueve el saldo —lo baja—,
no el tipo de documento.

**Y el gasto salarial no se duplica.** La obligación con el empleado y la del tercero
son **partes** del total de ingresos, no algo que se le sume encima:

```
Ana:    225.00 + 35.50 comisión              → 260.50 al empleado
Carlos: 200.00 − 25.00 a la cooperativa      → 175.00 al empleado + 25.00 al tercero
                                               ─────────────────────────────────────
Total de ingresos de la planilla: 460.50  =  435.50 + 25.00
```

La prueba lo afirma como igualdad exacta y además como desigualdad («lo comprometido
nunca puede superar lo declarado como ingresos»), que es la forma en que dolería que
fallara.

### Una advertencia honesta que no es un defecto del código

Cuando un anticipo se entrega **por Gastos**, ese pago tiene su propio gasto. Si ese
gasto se categoriza como salario, el informe de egresos contará el mismo dinero dos
veces: una al entregarlo y otra cuando la planilla registre el salario completo.

El sistema no puede decidirlo solo, porque depende de cómo se quiera leer el informe. La
recomendación es **categorizar esos gastos como anticipos al personal**, no como
salario. La planilla ya hace su parte: descuenta el anticipo, así que la obligación que
crea es por el **neto**, nunca por el bruto.


## Integración de Planilla al desarrollo habitual (11 de septiembre de 2026)

Cierre del corte de fase 3. Planilla deja de vivir en una base aislada y pasa a
`base_ejemplo`, alcanzable con las cuentas de siempre.

### Lo que se hizo, en orden

1. **Respaldo completo** de `base_ejemplo` (1,53 MB), con su sha256, **y
   verificado restaurándolo** en una base desechable: 102 tablas, 17 gastos, 6
   empleados, 14 huellas, 157 DTE, 3 usuarios — idénticos. Un respaldo sin restaurar no
   es un respaldo, es un archivo.
2. **Solo las dos migraciones de Planilla**, que eran exactamente las dos pendientes.
   Ninguna ajena. Después: 112 tablas, y los mismos 17 gastos, 6 empleados, 14 huellas,
   157 DTE. Cero pérdida.
3. **`RolesSeeder`**, que es idempotente y toma el reparto del enum. El diff de permisos
   antes/después es de cinco líneas, todas añadidas al rol `administrador`:
   `planilla.ver`, `planilla.salarios`, `planilla.gestionar`, `planilla.pagar`,
   `planilla.documentos`. **Nada quitado, ningún otro rol tocado.** Se conserva la foto
   previa y la posterior para poder comprobarlo.
4. **`PLANILLA_ENABLED=true`** en el `.env` local y documentado en `.env.example`
   —apagado por defecto, como el resto de los módulos—.

No hizo falta ninguna migración nueva: el desembolso se deriva de tablas que ya
existían.

### Entrar con la cuenta de siempre

`responsable@example.com`, rol `administrador`, con sus cinco permisos de planilla.
**No hay ninguna cuenta de validación en la base habitual**: las que se usaron para
probar vivían solo en la base aislada. Las tres cuentas reales siguen siendo las de
siempre.

Comprobado pantalla por pantalla: dashboard, Gastos (listado, pagos, informes,
repeticiones, avisos), Planilla (inicio, formatos, empleados, anticipos, abrir) — once
pantallas en 200. Y el candado por el otro lado: la cuenta de **jefatura** recibe 403 en
Planilla, porque no tiene permisos laborales.

`base_ejemplo` queda con **cero planillas**, a propósito: la primera la hace una
persona, con datos de verdad.

### El ejemplo del cierre: 100 − 40 = 60, y el desembolso es 100

```
Ingresos de planilla      100
Anticipo ya entregado    − 40   ← salió antes, por su propio pago
────────────────────────────────
Obligación de la planilla  60
Pago final                 60
────────────────────────────────
TOTAL DESEMBOLSADO        100
```

Lo que costaba ver es que **son dos preguntas y no una**:

| Pregunta | Respuesta | Qué NO incluye |
|---|---|---|
| ¿Cuánto debe esta planilla? | **60** | los 40, que ya no se deben |
| ¿Cuánto salió por este período? | **100** | nada: cuenta el anticipo aunque sea anterior |

Y el error que esto impide: sumar los **100 de ingresos** con los **40 del gasto del
anticipo** y reportar 140. Los 100 ya contienen los 40.

Por eso `InformePlanilla::obligaciones()` devuelve `total_ingresos` y
`anticipos_aplicados` **marcados como informativos y fuera de `total`**. Están para
cuadrar, no para sumar.

### La categoría del anticipo da lo mismo

Se probó el ejemplo completo dos veces, con el gasto del anticipo archivado como
**«Anticipos al personal»** y como **«Salarios»**. Desembolso: **100 en los dos casos**.
La cifra sale del dinero que salió —los pagos—, no de la etiqueta.

La recomendación sigue siendo archivarlos como anticipos al personal, pero ahora es una
cuestión de cómo se lee un informe por categoría, no de si el total está bien.

### Pagos y obligaciones, separados

Gastos ya tenía la regla escrita —«PENDIENTE y PAGADO no son la misma pregunta»— y dos
informes distintos. Lo que faltaba era el lado de Planilla, que ahora tiene:

- `InformePlanilla::obligaciones()` — lo que esta planilla comprometió.
- `InformePlanilla::desembolsos()` — el dinero que salió, anticipos incluidos.
- `InformePlanilla::cuadre()` — la comprobación:
  `desembolsado + pendiente + retenido = total de ingresos`.

Y en la pantalla de la planilla, **un panel propio** —no una fila más dentro del avance
del pago—, porque responde otra pregunta. Dice en voz alta que no se sume con el total
de ingresos, y muestra el cuadre.

### El interruptor que la suite no fijaba

Encender `PLANILLA_ENABLED=true` en el `.env` **rompió `NavigationTest`**: el enlace
`/planilla` apareció en el menú de todos los roles y la prueba que vigila «cada rol ve
exactamente los mismos enlaces de siempre» lo cazó al instante.

La prueba tenía razón, y el defecto no era el enlace: era que `phpunit.xml` **fijaba
todos los interruptores de módulo menos este**. `PLANTA_ENABLED`, `ASISTENCIA_ENABLED`,
`GASTOS_ENABLED` y los dos procesos automáticos estaban clavados en `false`; Planilla
no, porque hasta ese día nadie lo había encendido en un `.env` real. Es decir: la suite
dependía del `.env` de cada máquina y nadie lo había notado.

Arreglado en dos partes, porque una sola no alcanzaba:

1. `phpunit.xml` fija `PLANILLA_ENABLED=false`, como todos los demás. La suite vuelve a
   arrancar en el mismo estado en cualquier máquina.
2. Una prueba nueva cubre el módulo **encendido**, que es lo que nadie tenía: el
   administrador recibe el enlace, y jefatura —módulo encendido, sin permisos
   laborales— **no lo ve**. Encender un módulo no reparte permisos.

### Dos defectos de fecha que aparecieron de paso

Los encontró el ejemplo, no una revisión: al pedir un informe **hasta hoy**, faltaba el
pago de hoy.

`gastos_pagos.fecha` es `DATE` en MySQL, pero SQLite —dinámicamente tipado— guarda lo
que Laravel le manda: `2026-09-11 00:00:00`. Comparado como cadena contra el límite
`2026-09-11`, ese pago queda **fuera**.

- `InformeGastos::pagos()` usaba `whereBetween` → **perdía el último día del período**.
- `ConsultaGastos::cuotasConSaldo()` usaba `where('p.fecha', '<=', $corte)` → **«pendiente
  al 31 de enero» ignoraba lo pagado el 31**.

Los dos pasan a `whereDate`, que es lo que el historial de pagos ya hacía: estaban en
desacuerdo entre sí. En MySQL el comportamiento no cambia —la columna es `DATE`—, pero
los dos motores dejan de discrepar, y es en SQLite donde corren las pruebas: la
discrepancia significaba que ningún caso «hasta hoy» estaba cubierto de verdad.


## Un área para los dos: «Gastos y pagos» (12 de septiembre de 2026)

El selector superior se había llenado de puertas —seis— y ninguna decía a quién le
tocaba cuál. Gastos y Planilla pasan a ser **un área**, llamada **Gastos y pagos**, con
Planilla dentro.

### Cómo queda

```
Gastos y pagos                      ← una sola entrada en el selector

  GASTOS
    Por pagar            (8)        ← el número son los avisos sin leer
    Historial de pagos
    Gastos que se repiten
    Informes

  PLANILLA
    Planillas
    Personas
    Anticipos
    Formatos
```

Ocho filas en dos grupos plegables. Se ve el grupo en el que estás y el título del otro,
así que la barra no crece: en la práctica se leen cuatro filas, no ocho.

**Ninguna pantalla cambió de nombre ni de sitio.** Las cuatro de Gastos y las cuatro de
Planilla son exactamente las aprobadas. Lo único que se movió es dónde vive el menú.

**Las acciones siguen siendo botones dentro de su pantalla**, no filas del menú:
«Registrar gasto» y «Registrar pago» en Por pagar, «Preparar una planilla» en Planillas.
Un menú que mezcla *dónde estoy* con *qué hago* hay que leerlo entero cada vez.

### El riesgo del cambio, y cómo se cerró

Juntar la navegación **no puede** juntar los permisos. Si alcanzara con `gastos.ver`
para ver el menú de Planilla, los sueldos quedarían a la vista de quien lleva las
cuentas de proveedores.

El grupo de Planilla se dibuja solo con `planilla.ver` y el módulo encendido. Quien entra
con `gastos.ver` a secas **no ve ni el título del grupo**: que el menú exista ya diría
que hay sueldos que mirar. Comprobado en la base real con una cuenta de solo `gastos.ver`
—creada, verificada y borrada en el acto—: sin enlaces, sin título, y **403 entrando por
la URL**.

Esconder no autoriza, y por eso el candado real está en otros tres sitios que no dependen
del menú: el middleware de cada ruta, `AccesoGastos::ver()` —que además esconde las
obligaciones de planilla dentro de Gastos— y la comprobación de `planilla.salarios` en
cada controlador.

### Un área con DOS puertas

Y el riesgo del otro lado, menos obvio: **quien solo tiene permisos de planilla ya
entraba a su área**. Si la nueva exigiera `gastos.ver`, juntar la navegación le habría
quitado un acceso que tenía. Eso no es simplificar, es romper.

Por eso `AreaSistema` pasa de «un permiso por área» a **puertas**: cada una con su
módulo, su permiso de entrada y su aterrizaje.

| Entra con | ¿Ve el área? | Aterriza en | Ve el grupo Planilla |
|---|---|---|---|
| `gastos.ver` | sí | Por pagar | **no** |
| `planilla.ver` | sí | Planillas | sí |
| los dos | sí | Por pagar | sí |

Tres detalles que la implementación tuvo que respetar:

1. **Se comprueba puerta por puerta**, no «área encendida» + «algún permiso del área».
   Comprobarlo por separado dejaría entrar con el permiso de una puerta APAGADA, y el
   usuario se llevaría un 403 en la cara. Hay una prueba dedicada a eso.
2. **El aterrizaje depende del usuario** (`rutaInicioPara`). Mandar a /gastos a alguien
   que solo tiene permisos de planilla es mandarlo a un 403. El selector y el redirector
   del dashboard preguntan por usuario.
3. **El área sobrevive a un módulo apagado.** Con Gastos apagado y Planilla encendida el
   área sigue en pie, y al revés. Solo desaparece si se apagan los dos.

### Lo que se borró

`sidebar-planilla.blade.php` y el case `AreaSistema::Planilla`. No hay dos menús que
mantener sincronizados ni dos sitios donde agregar una fila.
