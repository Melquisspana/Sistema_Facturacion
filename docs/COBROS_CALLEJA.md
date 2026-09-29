# Cobros Calleja — de la factura al pago

Cómo se usa el seguimiento de cobros, en el orden en que se usa, y qué queda pendiente de
configurar antes de que funcione solo.

El módulo **no emite, no firma, no transmite, no sube nada al portal y no manda correos**.
Lee documentos ya aceptados por Hacienda y registra hechos con su evidencia.

## Dónde está

`/cobros`, con el permiso `ppq.ver` (gestionar exige `ppq.gestionar`). Se llega desde el
encabezado de **Facturación → Cobros Calleja**. Reutiliza los permisos de PPQ a propósito:
es el mismo trabajo y la misma gente.

Rutas sigue existiendo y sigue mostrando documentos, pero allí la pregunta es «qué llevo
hoy». El cobro se decide acá.

## El circuito, paso por paso

### 1. Traer los documentos aceptados

Corre **solo cada hora** (`cobros:sincronizar --aplicar`, apagado por defecto) y también
está el botón **«Traer documentos aceptados»** como recuperación: planificador caído,
resultado necesario ahora mismo, o servidor con la automática apagada a propósito.

Da de alta en el seguimiento los CCF y NC del cliente que Hacienda ya aceptó de verdad
(con sello real, no simulado).

```
php artisan cobros:sincronizar                        # ENSAYO EN SECO: no escribe nada
php artisan cobros:sincronizar --aplicar              # necesita COBROS_ALTA_AUTO=true
php artisan cobros:sincronizar --aplicar --vincular   # además, vincula los albaranes únicos
                                                       # (necesita TAMBIÉN COBROS_VINCULACION_AUTO=true)
```

Solo **lee** `dtes` y escribe en `cobro_documentos`: no emite, no firma, no transmite, no
cambia ningún estado fiscal y no bloquea filas que la emisión necesite. Una corrida a mitad
de una facturación no la estorba.

Es **idempotente**: correrlo dos veces no crea nada nuevo ni pisa ningún estado. Un
documento que alguien había incorporado a mano no se duplica: se **adopta**, completándole
el sello y el importe que el alta manual no podía tener.

Lo viejo **no se declara pendiente**. Si el documento ya figura en un lote PPQ del circuito
anterior, se marca para **revisión histórica** con el lote y el motivo; si simplemente es
anterior al seguimiento y no hay antecedentes, se marca igual. Un pendiente inventado se
reclama dos veces y un cobrado inventado se deja de reclamar.

### 2. Vincular los albaranes (automática, aparte del alta)

Desde `COBROS_VINCULACION_AUTO=true` la MISMA corrida horaria —`cobros:sincronizar --aplicar
--vincular`— también vincula, sin que nadie pulse nada: recorre los CCF sin albarán del
cliente y guarda solo lo que `VinculadorAlbaranes` audita como **único y sin
contradicciones**. Es una llave APARTE de `COBROS_ALTA_AUTO` a propósito: dar de alta un CCF
y unirle un albarán son afirmaciones de distinto peso, y encender la primera no debe
encender la segunda de rebote. Con `COBROS_VINCULACION_AUTO=false` (el valor de fábrica), la
misma corrida sigue dando de alta los documentos y auditando la vinculación, pero no escribe
ningún vínculo y lo dice en el log.

También sigue el enlace **«Auditar vinculación de albaranes»** de la pantalla: primero corre
**en seco**, sin escribir nada, y muestra cuántos vínculos saldrían únicos, cuántos ambiguos
y por qué. Es la recuperación manual y no depende de `COBROS_VINCULACION_AUTO`.

Un vínculo solo se hace con **coincidencia única** por vínculo explícito (`dte_id` del
albarán) o por **orden de compra** sobre albaranes de **entrega (AC01)**, y solo si nada lo
contradice.

**Nunca se vincula por importe, fecha ni correlativo.** Esos tres solo pueden *impedir* un
vínculo: sala distinta, sucursal de otro cliente, período imposible o tipo que no prueba
entrega mandan el candidato a **«Revisar vinculación»**, con su motivo y sus candidatos.
Desde la ficha del documento se elige a mano, y queda registrado quién fue y qué se saltó.

Una factura **sin albarán sigue visible**: es justo la que hay que resolver. Lo que no puede
es presentarse.

### 3. Generar la solicitud

Se marcan las facturas completas y se pulsa **«Generar solicitud con lo marcado»**. El
archivo es el formato de **carga masiva de quedan** del portal: cinco columnas —sala,
número, año, mes y tipo—, **todas del albarán**, no de la factura.

- No entran notas de crédito: no hay columna para ellas y tienen su propio circuito.
- No se exporta ninguna fila incompleta; el sistema dice antes qué falta.
- Los cinco datos quedan **congelados** en la solicitud: si mañana se corrige el albarán, el
  archivo ya presentado sigue siendo el que se presentó.

### 4. Presentar (a mano) y registrarlo

Se descarga el archivo y **una persona lo sube al portal de Calleja**. El sistema no lo
sube. Por eso descargar no presenta nada: hay que pulsar **«Registrar presentación»**, que
guarda quién y cuándo.

Si hubo que rehacerla, la **corrección** crea un reenvío: los documentos se *mueven* a la
solicitud nueva, la anterior queda como constancia y la deuda no se duplica. Los que se
dejan fuera vuelven a la bandeja.

### 5. Guardar el acuse del cliente

Cuando Calleja responde —`RECIBIDO (000123202609040951)`, `REFERENCIA #31001`,
`PROGRAMACION DE PAGO: 07/09/2026`— se captura la referencia y la fecha programada en la
fila de la solicitud, o se lee del correo (paso 7).

La **referencia** importa más de lo que parece: es lo que después ata el descuento de pronto
pago (`QD PPQ/31001`) a esta presentación.

### 6. Aplicar el archivo de pagos

Se sube el TXT del cliente en **«Aplicar pagos»**. Junto al archivo hay un campo de **fecha
del pago**: el archivo trae la fecha del *documento*, no la del pago, y si no se captura
ninguna los documentos quedan cobrados **sin fecha de pago** en vez de con una inventada.

Lo que hace y lo que no:

- `CF` identifica facturas informadas; `NC`, notas aplicadas; `QD`, ajustes.
- **Solo toca lo que el archivo menciona.** Un documento ausente conserva el pago que ya
  tenía, y se listan aparte los conservados.
- **No duplica nada**, con dos defensas distintas:
  - la **misma línea del mismo archivo** no entra dos veces (llave documento + tipo + huella
    + línea): recargarlo o subir dos archivos solapados no suma;
  - el **mismo pago en dos archivos distintos** —huellas distintas— no se suma solo. El
    segundo queda **EN REVISIÓN**: se registra entero, con su evidencia, pero **no cuenta**
    hasta que una persona diga si es una repetición o un abono más. Desde el archivo las dos
    cosas se ven idénticas, y elegir por el sistema significa, la mitad de las veces, cobrar
    dos veces lo mismo o perder un abono. Se resuelve en la ficha del documento, con motivo
    obligatorio, y el importe en revisión se muestra al lado del cobrado.
- Antes de marcar pagado **compara importes**: menos es *pago parcial*, más es *diferencia*.
- Los documentos que no reconoce, las filas incompletas y los tipos raros **se muestran**,
  no se descartan.
- Un archivo que se **contradice** (el mismo documento con dos importes) se rechaza entero.
- Los **QD no se reparten** entre las facturas de la referencia. Desde el ajuste se puede
  crear, a pedido, una NC de pronto pago por el **importe neto del TXT**, usando el motor
  fiscal existente (IVA y retención); cualquier diferencia de redondeo se muestra en el
  borrador. También se puede vincular o desvincular una NC existente del mismo cliente.
  El ajuste se resuelve cuando Hacienda acepta realmente la nota y vuelve a pendiente si
  se invalida o se borra el borrador. El mismo cliente, referencia y monto en otro archivo
  conserva la NC vinculada; nunca se comparte entre deducciones distintas.

### 7. Leer el correo (apagado por defecto)

Corre **solo cada media hora** cuando las tres llaves están encendidas, y a mano:

```
php artisan cobros:leer-correos                 # ENSAYO EN SECO: no escribe nada
php artisan cobros:leer-correos --aplicar       # necesita COBROS_CORREO_ENABLED=true
```

Lee los acuses y las observaciones. **Solo lee**: no envía, no responde, no marca como
leído y no mueve etiquetas.

- La búsqueda **no filtra por asunto**. El acuse real llega como respuesta a nuestro propio
  correo —asunto `RE: SOLICITUD DE QUEDAN (PRONTO PAGO)`— y el `RECIBIDO (0001232026…)`
  viene **dentro del cuerpo**. Filtrar por asunto era buscar la marca donde no está.
- Se baja el **cuerpo completo**, texto plano y HTML, nunca el snippet. Las observaciones
  vienen en una **tabla**: las celdas se separan antes de quitar las etiquetas, porque si
  no, el número de control queda soldado al importe de al lado y no se reconoce ninguno.
- **El barrido AVANZA entre corridas.** Cada una mira dos sitios: la **cabeza**
  (`after:{último visto}`) para lo nuevo, que no puede esperar detrás del backlog; y la
  **cola** (`before:{hasta dónde llegó}`) para ir recorriendo lo viejo, una tanda por
  corrida. Sin esto, la tarea de cada media hora leía siempre los mismos primeros mensajes y
  el número 51 no se leía jamás. Subir el límite no lo arregla: mueve la frontera.
- **Lo ya registrado no gasta cupo.** Primero se piden los ids —barato— y se descartan los
  que ya están; solo se baja el cuerpo de los que quedan. El límite cuenta mensajes *por
  procesar*, no mensajes mirados.
- El avance se guarda en `cobro_correo_progresos`, con la **fecha** del mensaje y no un
  token de página: un token caduca y se invalida en cuanto entra un correo nuevo.
- **La marca solo se mueve después de guardar, y solo sobre lo guardado.** El barrido va en
  dos pasos —preparar, procesar, confirmar— y confirmar vuelve a preguntar a la base cuáles
  de esos mensajes quedaron registrados de verdad. Si el procesamiento se interrumpe, en el
  momento que sea, la marca se queda donde estaba: la corrida siguiente trae lo que faltó y
  descarta por su id lo que ya se había guardado. Y el barrido no se declara **completo**
  mientras quede algo mirado sin registrar.
- Al terminar cada corrida se dice **si queda buzón por recorrer**. Que una corrida no traiga
  nada puede significar «no hay más» o «todavía no llegué ahí», y no es lo mismo: solo
  `barrido_completo` significa cubierto.
- Cambiar la consulta **empieza un barrido nuevo**: el universo es otro, y heredar el avance
  daría por cubierto lo que nunca se miró.
- El **acuse** se ata a la solicitud por el nombre exacto del archivo que nombra; el número
  se toma de su forma marcada (`RECIBIDO (…)`), no del primer número largo del cuerpo.
- Las **observaciones** se atan a documentos por código de generación o número de control.
- Sin evidencia suficiente el correo queda **`sin_asociar`**, a la vista y con su motivo. Es
  un resultado normal, no un fallo.
- Releer el buzón no duplica nada (`gmail_message_id` es único).

## Configuración pendiente

Todo lo automático nace **apagado**. Instalar el planificador en un servidor no puede
encender un módulo de rebote, así que cada tarea tiene su llave y el comando comprueba la
**misma** llave cuando se lo invoca a mano: un `.bat` viejo o un `--aplicar` de más tampoco
escriben nada.

| Llave | Para qué | Por defecto |
|---|---|---|
| `COBROS_DIAS_REVISION_HISTORICA` | Antigüedad a partir de la cual un documento sin antecedentes se marca para revisión | `30` |
| `COBROS_SOLICITUDES_STORAGE_DIR` | Dónde queda la copia del archivo presentado | `cobros/solicitudes` |
| `COBROS_ALTA_AUTO` | El alta horaria de documentos aceptados corre sola | `false` |
| `COBROS_VINCULACION_AUTO` | La MISMA corrida horaria además vincula los albaranes únicos y sin contradicciones | `false` |
| `COBROS_CORREO_ENABLED` | Permite que `cobros:leer-correos --aplicar` consulte el buzón | `false` |
| `COBROS_CORREO_AUTO` | Y que además lo haga sola, cada media hora | `false` |
| `COBROS_CORREO_QUERY` | Consulta de Gmail (sin `subject:`, a propósito) | `("RECIBIDO" OR "OBSERVACIONES" OR "SOLICITUD DE QUEDAN")` |
| `COBROS_CORREO_LIMITE` | Máximo de mensajes por corrida (paginando) | `50` |

Para el paso 7 hace falta además la conexión de Gmail que ya usa PPQ (`PPQ_GMAIL_ENABLED` y
la cuenta conectada). Son **tres llaves** y cada una responde algo distinto: si el sistema
*puede* hablar con Gmail, si este módulo *puede* leer el buzón cuando se lo piden, y si lo
hace *sin que nadie se lo pida*. Juntarlas obligaría a encender la automática para poder
probar la lectura, que es al revés de como hay que hacerlo.

### Orden para encenderlas

1. `cobros:sincronizar` en seco, revisar cuántos entrarían y cuántos van a revisión
   histórica. Después `COBROS_ALTA_AUTO=true`.
2. `cobros:sincronizar --cliente=ID --vincular` **en seco** (sin `--aplicar`) sobre los
   datos reales del cliente: cuántos saldrían vinculados, cuántos «revisar» y por qué. Solo
   cuando ese resultado sea el esperado, `COBROS_VINCULACION_AUTO=true`. La tarea horaria ya
   lleva `--vincular`, así que enciende la vinculación automática sin tocar `routes/console.php`.
3. `cobros:leer-correos` en seco contra el buzón real, comprobar que los acuses se
   interpretan. Después `COBROS_CORREO_ENABLED=true` y, cuando eso lleve días bien,
   `COBROS_CORREO_AUTO=true`.
4. `php artisan config:clear` si la configuración está cacheada, y algo que ejecute
   `schedule:run` cada minuto en el servidor. Con una sola de las dos cosas, no corre.

## Qué está probado y qué falta validar

**Comprobado contra datos reales** (pruebas automatizadas en `tests/Feature/Cobros/`):

- el archivo de pagos del 07/09/2026 entero: 45 CF $6,826.42, 13 NC −$125.90, QD −$107.21,
  neto $6,593.31, y las dos facturas ausentes sin pago confirmado;
- los encabezados del archivo de solicitud, byte a byte contra la plantilla del cliente;
- el mismo pago en dos archivos distintos, con sus dos salidas posibles;
- el acuse real (marca en el cuerpo, asunto de respuesta) y una tabla de observaciones en
  HTML;
- un buzón de 120 mensajes con límite de 20: varias corridas seguidas los procesan todos,
  cada uno una sola vez, y un correo que entra a mitad del recorrido se lee enseguida;
- una caída a mitad de una tanda del backlog —4 de 10 guardados— y su reintento: los 6 que
  faltaban vuelven, los 4 guardados no se bajan otra vez, y al final están los 40 sin
  duplicar ninguno;
- que las tareas automáticas existan, estén apagadas y no escriban en seco.

**Sin validar todavía** — no se puede desde acá y conviene mirarlo en la primera pasada
real:

- **el archivo de solicitud no se ha subido al portal.** Sala, número y tipo van como texto
  (para no perder el `0017`) y año y mes como número; la plantilla no declara formato en
  ninguna columna, así que es la lectura más fiel, no un dato confirmado. Tampoco se replica
  el objeto «Tabla3» de Excel;
- **el «# ALBARAN» va como número suelto** (`5131`), no canónico: sala y tipo ya tienen
  columna propia. Es una lectura, no un dato confirmado;
- **la consulta a Gmail no se ha ejecutado contra el buzón.** El intérprete de los correos
  sí está probado con los mensajes reales, y la paginación con un doble; lo que falta es una
  corrida en seco de `cobros:leer-correos` contra la cuenta;
- **las migraciones no se han corrido.** `php artisan migrate` está pendiente.

## Lo que este módulo deliberadamente no hace

- No sube el archivo al portal ni comprueba que se haya subido.
- No manda correos ni responde los del cliente.
- No emite notas de crédito ni deduce su desglose fiscal a partir de un importe neto.
- No reparte descuentos globales entre facturas.
- No interpreta la ausencia de un documento en un archivo como un rechazo.
- No asigna ningún significado a `AC06` ni a ningún otro código sin respaldo documental.
