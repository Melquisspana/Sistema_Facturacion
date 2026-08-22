# Control de Asistencia — lector de huella ESP32

Marcaciones con un dispositivo físico:

```
huella -> ESP32 -> Wi-Fi -> HTTP -> Laravel -> respuesta JSON -> pantalla TFT
```

Estado: **administrable y consultable desde la web**. Hay empleados, huellas,
lectores y marcaciones reales, con pantallas para darlos de alta, mantenerlos y
consultar el historial.

Desde la Fase 4 hay además **jornadas**: qué ocurrió cada día, con las entradas y
salidas emparejadas y el tiempo de presencia sumado.

Todavía **no** hay horarios, tardanzas, horas extra, ausencias, feriados, planilla
ni enrolamiento remoto del sensor. Los primeros necesitan reglas laborales que
nadie ha declarado; el último tiene su propia fase.

## Reglas del módulo

1. **La hora oficial la pone el servidor.** El ESP32 no tiene reloj con batería:
   se reinicia y se desfasa. Lo que mande en el cuerpo se ignora.
2. **El ESP32 no sabe de quién es la huella.** Manda el número de ranura del
   sensor AS608; Laravel resuelve la persona. Tampoco decide si es entrada o
   salida.
3. **El módulo se enciende por servidor.** Con `ASISTENCIA_ENABLED=false` (por
   defecto) `/api/asistencia/*` responde **404** para todos.
4. **Aislado.** No toca DTE, facturación, PPQ, notas de crédito, invalidaciones,
   exportaciones, Rutas/Cobros ni Planta.

## Base de datos

Cuatro tablas, todas con prefijo `asistencia_`.

| Tabla | Qué guarda |
|---|---|
| `asistencia_dispositivos` | Lectores dados de alta: código, **hash** del token, activo, última conexión. |
| `asistencia_empleados` | Personas que marcan. `user_id` nullable enlaza con `users` solo si además tiene login. |
| `asistencia_huellas` | «La ranura N de ESTE lector fue de esta persona, de tal día a tal otro». Único **entre las activas**. |
| `asistencia_marcaciones` | Los hechos. **Append-only**: sin `updated_at`, sin borrado lógico. |

Tres decisiones que valen la pena entender:

- **Empleados aparte de `users`.** Un `User` entra al sistema (correo, contraseña,
  roles, permisos fiscales). Quien marca asistencia trabaja acá y puede no tener
  ni correo. Meterlos juntos obligaría a inventar correos y a que cada permiso
  nuevo se pregunte si aplica a gente sin login.
- **`fingerprint_id` es único POR LECTOR, no globalmente.** El número es el índice
  de una plantilla dentro de un sensor concreto: la ranura 1 de la entrada y la
  ranura 1 de la bodega son dos personas distintas. Un único global funcionaría
  hoy y habría que migrar el histórico al instalar el segundo lector.
- **Una fila de `asistencia_huellas` es una ASIGNACIÓN, no una ranura.** «La
  ranura 7 fue de Ana hasta marzo» y «la ranura 7 es de Beto desde abril» son dos
  filas, y la de Ana no se toca nunca más. Ver «Reutilizar una ranura».
- **Dos columnas de tiempo.** `marcado_at` es el instante exacto en UTC (hora
  oficial); `fecha_local` es el día en la zona del módulo. El segundo no es
  redundante: «la primera marcación del día» es una pregunta sobre el día local, y
  derivarla de un UTC en cada consulta exige funciones de zona que difieren entre
  MySQL y SQLite y que ningún índice aprovecha.

Ningún dato biométrico sale del sensor: la plantilla vive en el AS608 y por la red
solo viaja un número de ranura.

## Reutilizar una ranura

Las ranuras del AS608 son finitas (~162) y la gente entra y sale. Cuando alguien
se va, su plantilla se borra del sensor y ese número tiene que poder ser de otra
persona — **sin que las marcaciones de quien se fue cambien de dueño**.

La regla:

- a lo sumo **una** asignación VIGENTE por `(lector, ranura)`;
- cuantas asignaciones **históricas** haga falta para esa misma ranura;
- una asignación liberada no se toca nunca más: conserva su empleado, su fecha y
  sus marcaciones colgando de ella.

### Cómo se impone (y por qué así)

MySQL no tiene índices únicos parciales, y `UNIQUE (lector, ranura, activo)` no
sirve: dejaría una activa y **una sola** inactiva, cuando el histórico tiene que
poder crecer. La solución es una **columna generada nullable**:

```sql
fingerprint_id_activo = CASE WHEN activo = 1 THEN fingerprint_id ELSE NULL END
UNIQUE (asistencia_dispositivo_id, fingerprint_id_activo)
```

y se apoya en que **los NULL no colisionan entre sí** dentro de un índice único:

| Fila | `fingerprint_id_activo` | Efecto en el único |
|---|---|---|
| activa | la ranura | bloquea una segunda activa de esa ranura |
| liberada | `NULL` | invisible: caben todas las que haga falta |

Es **generada**, no una columna que mantenga la aplicación: se deriva de `activo`
dentro del motor, así que liberar libera la ranura en el mismo `UPDATE`. Una
garantía que depende de que ningún código se olvide no es una garantía.

`VIRTUAL` y no `STORED` porque es la única forma que SQLite admite en un
`ALTER TABLE ADD COLUMN`, y la suite corre sobre SQLite mientras producción corre
sobre MySQL: la garantía tiene que ser **la misma** en los dos.

Comprobado contra MySQL 8.4 y SQLite 3.40. Migración
`2026_08_21_090000_unicidad_de_ranura_activa_en_asistencia_huellas`.

### El orden correcto al reutilizar

1. **Liberar** la asignación en el sistema (`LiberarHuella`).
2. **Borrar la plantilla** en el sensor AS608.
3. **Asignar** la ranura a la persona nueva (`AsignarHuella`).

Invertir 2 y 3 deja el sensor reconociendo el dedo viejo y resolviéndolo a la
persona NUEVA. El sistema no puede detectarlo: para él solo llega un número.

### Las dos operaciones

| | `AsignarHuella` | `LiberarHuella` |
|---|---|---|
| Qué hace | crea una asignación vigente | cierra la vigente (`activo=false` + `liberada_at`) |
| Si la ranura está tomada | `RanuraOcupadaException` con el nombre de quien la ocupa | — |
| Idempotente | no | sí: liberar dos veces no mueve la fecha original |
| Audita | sí | sí |

Son el **único** camino por el que debería nacer o cerrarse una asignación. Un
`AsistenciaHuella::create()` suelto funciona, pero deja un cambio de titularidad
sin rastro.

**Revertir la migración puede ser imposible, a propósito.** En cuanto una ranura
se reutilizó una vez, el esquema anterior no puede representar el dato. `down()`
lo comprueba y se detiene con un mensaje explícito, en vez de morir a media faena
con el índice nuevo ya borrado.

## Auditoría

Log `asistencia` de spatie/activitylog:

| Qué | Se audita | Nota |
|---|---|---|
| Alta / cambio / baja de EMPLEADO | sí | `logFillable` |
| Asignar y liberar una HUELLA | sí | es el cambio que decide de quién son las marcaciones |
| Alta, cambio y baja de un LECTOR | sí | **sin `token_hash`**: `logOnly(['codigo','nombre','activo'])` |
| Rotación del token | sí (el hecho) | `RotarTokenDispositivo` la registra a mano. **No basta con `LogsActivity`**: `token_hash` está fuera de las columnas auditadas —a propósito—, así que `logOnlyDirty` producía un diff vacío y `dontSubmitEmptyLogs` descartaba la entrada entera. Se descubrió ejecutándolo. |
| Telemetría del lector (`ultima_conexion_at`) | **no** | `saveQuietly`: una entrada por cada dedo dejaría el log inservible |
| MARCACIONES | **no** | la tabla ya es append-only; auditarla sería guardarlas dos veces |

## Permisos

| Permiso | Para qué |
|---|---|
| `asistencia.ver` | consultar marcaciones y reportes (solo lectura) |
| `asistencia.gestionar` | dar de alta personas y **asignar o liberar ranuras** |
| `asistencia.dispositivos.gestionar` | dar de alta lectores y **rotarles el token** |

Los tres van separados porque son riesgos distintos que tomará gente distinta.
Hoy solo los tiene el administrador (recibe todos); **ningún otro rol se
ensanchó**. Todavía no hay pantallas que los usen: existen para que las de la
fase siguiente nazcan con su candado en vez de heredar `configuracion.gestionar`.

`AreaSistema::Asistencia` existe desde la Fase 2, cuando el módulo estrenó su
pantalla de inicio. Antes no: un área exige una `rutaInicio()` real y la barra de
navegación llama `route()` sobre ella, así que declararla sin pantalla habría
reventado el navbar de todo administrador en cuanto se encendiera el módulo. Hay
un test que recorre TODAS las áreas y comprueba que su ruta de aterrizaje existe.

Con `ASISTENCIA_ENABLED=false` el área desaparece del selector
(`AreaSistema::habilitada()`) **y** sus rutas responden 404 (middleware
`modulo.asistencia`). Son dos candados para dos cosas distintas: uno evita ofrecer
el enlace, el otro evita que sirva escribir la URL.

## Pantallas (área Asistencia)

Prefijo `/asistencia`, nombres `asistencia.*`, `App\Enums\AreaSistema::Asistencia`.
Rutas en `routes/asistencia.php`.

| Pantalla | Ruta | Permiso |
|---|---|---|
| Resumen | `asistencia.dashboard` | `asistencia.ver` |
| Empleados (listado) | `asistencia.empleados.index` | `asistencia.ver` |
| Ficha de empleado | `asistencia.empleados.show` | `asistencia.ver` |
| Alta / edición de empleado | `asistencia.empleados.create` / `.edit` | `asistencia.gestionar` |
| Activar o desactivar empleado | `asistencia.empleados.toggle-activo` | `asistencia.gestionar` |
| Asignar ranura | `asistencia.empleados.huellas.store` | `asistencia.gestionar` |
| Liberar ranura | `asistencia.huellas.liberar` | `asistencia.gestionar` |
| Historial de marcaciones | `asistencia.marcaciones.index` | `asistencia.ver` |
| Jornadas (reporte) | `asistencia.jornadas.index` | `asistencia.ver` |
| Lectores (listado) | `asistencia.dispositivos.index` | `asistencia.ver` |
| Alta / edición de lector | `asistencia.dispositivos.create` / `.edit` | `asistencia.dispositivos.gestionar` |
| Activar o desactivar lector | `asistencia.dispositivos.toggle-activo` | `asistencia.dispositivos.gestionar` |
| Rotar token | `asistencia.dispositivos.rotar-token` (+ `.ejecutar`) | `asistencia.dispositivos.gestionar` |

Candados, en orden: `auth` → `modulo.asistencia` (404 si el módulo está apagado)
→ `permission:asistencia.ver`, más el permiso de escritura **inline** en cada ruta
que escribe.

### Decisiones que no son obvias

**No hay `destroy` de empleado, y no falta.** Borrar a alguien borra su historial
laboral. La base lo respalda (`restrictOnDelete`), pero la garantía real es que
**no existe el endpoint**: no hay que acordarse de comprobar nada. Quien se va se
desactiva.

**Liberar una ranura es `PATCH`, no `DELETE`.** No se borra nada: la asignación se
queda como registro histórico con su empleado y sus marcaciones. Un `DELETE`
prometería lo contrario de lo que hace.

**Las huellas no tienen listado propio.** Se administran dentro de la ficha de
cada persona, que es donde «qué ranura es de quién» se entiende sin cruzar dos
pantallas. La ficha muestra las vigentes y las históricas **por separado**:
esconder el historial haría parecer que se borró algo.

**El resumen solo cuenta lo que existe.** Personas activas, ranuras asignadas,
lectores activos y marcaciones de hoy. No hay tardanzas ni horas trabajadas
porque necesitan horarios, y los horarios no existen: un indicador inventado en
una pantalla de inicio es peor que ninguno. La tarjeta de marcaciones **no enlaza
a ningún lado** — el historial todavía no está hecho y no se promete.

**Desactivar a alguien no libera sus ranuras.** La plantilla sigue en el sensor y
la asignación sigue siendo suya; lo que cambia es que el lector responde
`empleado_inactivo`. Liberar es un acto aparte porque implica borrarla del AS608,
y ese paso el servidor no lo puede dar.

### El token, en la web

Se genera al dar de alta y se renueva rotándolo. **Nunca se escribe a mano**: el
formulario no acepta ningún campo de token, así que no existe un camino para fijar
uno débil o ya conocido.

Se muestra **una vez**, por `flash`: desaparece en la petición siguiente, incluso
si se recarga la pantalla. No hay ninguna ruta que pueda devolverlo y `token_hash`
está en `$hidden` del modelo.

**Rotar es una pantalla, no un botón.** Deja al lector sin autenticar hasta que
alguien reprograme el firmware, y mientras tanto nadie marca. Por eso exige
ESCRIBIR el código del lector —el mismo criterio de la confirmación fuerte del
Centro de Configuración—: quien lo escribe leyó de qué lector se trata.

## Consultar el historial

Código: `app/Support/Asistencia/`. Dos piezas.

| Clase | Responsabilidad |
|---|---|
| `FiltroAsistencia` | QUÉ se quiere. Objeto de criterios inmutable, sin nada de HTTP. |
| `ConsultaAsistencia` | CÓMO se busca. La única consulta de marcaciones del sistema. |

### Por qué existe una capa y no un `where` en el controlador

Porque el módulo de Formatos va a necesitar estos mismos datos, y la diferencia
entre estas dos formas decide si ese módulo se puede construir:

```
Formatos -> ConsultaAsistencia -> datos reales     ✅
Formatos -> copiar el where del controlador        ❌
```

La segunda parece más rápida el primer día y garantiza que, en cuanto alguien
corrija un criterio, la pantalla y el documento empiecen a decir cosas distintas
sobre el mismo mes.

Por eso `MarcacionController` **no tiene un solo `where`**, y por eso las pruebas
de la capa (`ConsultaAsistenciaTest`) no tocan una sola ruta: si solo se probara a
través de la pantalla, nada garantizaría que sirve fuera de ella.

### Contrato

```php
$filtro = FiltroAsistencia::vacio()
    ->conEmpleado($id)
    ->conRango($desde, $hasta)      // días LOCALES, inclusivo en ambos extremos
    ->conDispositivo($id)
    ->conTipo(TipoMarcacion::Entrada)
    ->conOrigen('dispositivo')
    ->ascendente();                 // cronológico: lo que quiere un documento

FiltroAsistencia::desdeArray([...]); // formulario, formato guardado, comando
$filtro->descripcion(['empleado' => 'Ana Pérez']);  // rótulo del documento
```

| Método de `ConsultaAsistencia` | Devuelve | Para |
|---|---|---|
| `query($filtro)` | `Builder` sin ejecutar | **el seam**: componer, `lazyById()`, joins |
| `paginar($filtro)` | `LengthAwarePaginator` | la pantalla |
| `todas($filtro)` | `Collection` | documentos y reportes acotados |
| `porEmpleado($filtro)` | `Collection` agrupada por persona | una hoja por empleado |
| `porDia($filtro)` | `Collection` agrupada por día local | una fila por día |
| `resumen($filtro)` | `total, entradas, salidas, personas, dias` | encabezados |
| `contar($filtro)` | `int` | conteos |
| `ultimasDe($id, $n)` | `Collection` | la ficha del empleado |

`query()` es público a propósito: quien necesite algo que estos métodos no
ofrecen compone sobre él en vez de escribir su propio `where`.

### Las fechas se filtran por DÍA LOCAL, siempre

Sobre `fecha_local`, **nunca** sobre `marcado_at`. Dos razones y las dos mandan:

1. **Corrección.** En El Salvador (UTC−6) una marcación de las **19:30 del día 5**
   se guarda como **01:30 UTC del día 6**. Filtrar por el instante la sacaría del
   día 5 — el turno de la tarde entero, movido de día, sin error y sin aviso.
2. **Rendimiento.** `fecha_local` está indexada sola y junto al empleado.
   Convertir `marcado_at` a hora local dentro del `where` exigiría funciones de
   zona horaria que difieren entre MySQL y SQLite y que ningún índice aprovecha.

Hay una prueba dedicada (`test_una_marcacion_nocturna_pertenece_a_su_dia_local_y_no_al_utc`)
que falla si alguien cambia la columna.

### Índices: no hizo falta esquema nuevo

Verificado con `EXPLAIN` sobre MySQL 8.4:

| Consulta | Índice usado | `type` |
|---|---|---|
| empleado + rango | `asistencia_marc_empleado_fecha_idx` | `range` |
| solo rango | `asistencia_marc_fecha_idx` | `range` |
| lector | índice de su clave foránea | `ref` |

`tipo` y `origen` no llevan índice y no lo necesitan: dos y dos valores, y siempre
acompañados de un filtro de fecha.

### Datos históricos: se muestra el estado real

Nada se rellena ni se adivina. Hay cuatro casos distintos y la pantalla los
distingue (`<x-asistencia.origen-marcacion>`):

| Situación | Qué se muestra |
|---|---|
| `origen=dispositivo` + lector presente | nombre y código del lector |
| `origen=dispositivo` + lector `NULL` | «Lector no disponible» (se borró; la registró un aparato igual) |
| `origen=manual` | «Manual» (corrección hecha por una persona) |
| huella liberada después de la marcación | la ranura + «asignación liberada» |
| empleado desactivado | su nombre + «(inactivo)» |

**Una marcación NUNCA cambia de dueño por reutilizar una ranura.** Sigue colgando
de la asignación con la que se hizo, aunque ese número sea de otra persona hoy.

Los desplegables de filtro incluyen a los empleados y lectores **inactivos**: el
historial de quien ya no trabaja acá es justamente lo que se viene a buscar.

### Append-only, otra vez

La pantalla es de **solo consulta**. No hay `edit`, ni `update`, ni `destroy`, ni
ruta que los invoque: `PUT`, `PATCH` y `DELETE` sobre una marcación responden
**404** porque esa URL no existe. Cuando exista la corrección manual será una fila
NUEVA con `origen = 'manual'`, nunca una edición encima del hecho.

Hay una prueba que compara la tabla entera antes y después de una ronda de
consultas con filtros, y otra que espía el SQL buscando cualquier `insert`,
`update` o `delete`.

### Lo que esta capa NO calcula

Horas trabajadas, jornadas, tardanzas y ausencias. Todo eso necesita horarios, y
los horarios no existen: emparejar una entrada con una salida sin saber qué es una
jornada es adivinar. `resumen()` cuenta hechos y ahí se detiene. `porDia()` es la
base sobre la que la fase siguiente hará ese emparejamiento cuando existan las
reglas que digan cómo.

## Jornadas

Código: `app/Support/Asistencia/` y `App\Enums\Asistencia\EstadoJornada`.

| Clase | Responsabilidad |
|---|---|
| `Jornada` | Lo que ocurrió con UNA persona en UN día local. Objeto derivado. |
| `TramoJornada` | Un tramo `entrada → salida`. Puede quedar abierto. |
| `EstadoJornada` | `Completa` · `Abierta` · `Irregular`. |
| `ConsultaJornadas` | Las arma a partir de `ConsultaAsistencia`. **El seam.** |

### La cadena, y por qué importa

```
Formatos -> ConsultaJornadas -> ConsultaAsistencia -> datos reales
```

`ConsultaJornadas` **no consulta la tabla por su cuenta**: pide los datos a la
capa de la Fase 3. Así toda su garantía —filtrar por `fecha_local` y no por el
instante UTC, usar los índices, no inventar nada cuando falta el lector— sigue
valiendo. Una segunda consulta escrita aparte la perdería en silencio.

`JornadaController` no empareja, no suma y no decide estados; la vista tampoco.
Hay un test (`test_la_pantalla_y_la_capa_reutilizable_producen_lo_mismo`) que
compara fila por fila lo que pinta la pantalla contra lo que devuelve la capa.

### Contrato

```php
$consulta->porRango($filtro, $estado);      // Collection<Jornada>  ← entrada principal
$consulta->deEmpleado($id, $filtro);        // atajo por persona
$consulta->porEmpleado($filtro);            // agrupadas: una hoja por empleado
$consulta->delDia($id, $dia);               // ?Jornada — rellenar una celda
$consulta->paginar($filtro, $estado);       // la pantalla
$consulta->resumen($filtro, $estado);       // conteos + tiempo + tiempo_exacto
```

De cada `Jornada`: `primeraEntrada()`, `ultimaSalida()`, `entradas()`, `salidas()`,
`paresCompletos()`, `sinPareja()`, `trabajadoSegundos()`, `trabajadoLegible()`,
`trabajadoHorasDecimales()`, `tiempoEsExacto()`, `estado`, `toArray()`.

### El tiempo es la SUMA DE LOS TRAMOS

No «última salida − primera entrada». Quien entra a las 07:00, sale a las 12:00,
vuelve a las 13:00 y se va a las 16:00 trabajó **8 h, no 9**: la resta ingenua se
come la hora de almuerzo, y ese error va directo a una planilla.

Un tramo sin cerrar **no aporta tiempo**. Cerrarlo con «ahora» o con el final del
día serían dos formas de inventar una hora que nadie marcó. Cuando eso pasa,
`tiempoEsExacto()` devuelve `false` y la pantalla dice «al menos».

### Los tres estados, y qué los distingue

| Estado | Cuándo | Tiempo |
|---|---|---|
| `Completa` | cada entrada tiene su salida | exacto |
| `Abierta` | la **última** marcación es una entrada sin cerrar | mínimo |
| `Irregular` | una salida sin entrada, o una entrada sin cerrar que **no es la última** | mínimo |

La diferencia entre `Abierta` e `Irregular` es **posicional**, no de cantidad:
«se le olvidó salir» y «hay una entrada duplicada» dejan los dos un tramo abierto,
pero significan cosas distintas. Vía lector `Irregular` no puede ocurrir —la
alternancia lo impide y el día siempre empieza en entrada—: solo llega de
correcciones manuales.

**No hay `Puntual`, `Ausente`, `Tardanza` ni `Extra`.** Todos presuponen una hora
oficial de entrada, una jornada pactada o un calendario laboral, y ninguna de las
tres está declarada. Un estado inventado en una pantalla de asistencia se
convierte en una discusión de planilla.

**Un día sin marcaciones no produce jornada.** No es «ausencia» —eso presupone
saber que ese día se trabajaba—: es un día del que no hay nada que decir.

### ⚠️ Turnos que cruzan la medianoche

**Identificados, no resueltos.** Y el problema es peor de lo que parece.
Comprobado ejecutando el servicio real de marcación:

```
persona entra 20:00 del día 5  y sale 01:00 del día 6

día 5 -> 20:00 entrada          jornada ABIERTA, 0 h
día 6 -> 01:00 **entrada**      ¡no «salida»!
```

La marcación de la 01:00 se registra como **entrada** porque la alternancia se
reinicia a medianoche local (`TipoMarcacion::siguienteTras()` con el día vacío).
El tipo queda invertido y **sigue invertido mientras dure el patrón**.

Unirlas exigiría saber que esa persona hace turno de noche, que es exactamente lo
que un horario declara. Adivinarlo —«si el día abre y el siguiente empieza de
madrugada, unilos»— sería una heurística silenciosa que acertaría casi siempre y
fallaría en la planilla de alguien.

Se deja como `Abierta`, con las horas en cero en vez de inventadas, y con un test
(`test_el_turno_nocturno_queda_identificado_pero_sin_resolver`) que fija el
comportamiento ACTUAL. El día que existan horarios, ese test tiene que cambiar —
y ese cambio será la señal de que el problema se resolvió.

### Coste y rango por defecto

Una jornada no es una fila: es el resultado de agrupar y emparejar varias, así que
la paginación ocurre en memoria y **no hay `LIMIT` que proteja de un «traeme
todo»**. Por eso la pantalla ofrece el **mes en curso** cuando no se pide otro
rango, y con un solo extremo completa el otro con el mes de ese extremo.

## Endpoints

| | Ping | Marcar |
|---|---|---|
| Ruta | `GET`/`POST` `/api/asistencia/ping` | `POST` `/api/asistencia/marcar` |
| Nombre | `api.asistencia.ping` | `api.asistencia.marcar` |
| Middleware | `modulo.asistencia`, `throttle:asistencia-dispositivo` | + `dispositivo.asistencia` |
| Escribe | Nada | Una marcación (y la última conexión del lector) |

**Por qué viven en `/api`.** Un dispositivo no es un navegador: no tiene sesión,
ni cookie, ni token CSRF. El grupo `api` no monta ninguna de las tres, así que el
POST del firmware funciona sin desactivarle candados al sitio web.

**El límite se reparte por LECTOR, no por IP.** `throttle:60,1` repartía por IP y
todos los lectores salen por la misma IP del router: con tres aparatos detrás de
un NAT compartían 60 peticiones por minuto entre todos. El sondeo del enrolamiento
lo volvió bloqueante. El limitador `asistencia-dispositivo`
(`AppServiceProvider::boot()`) aplica **dos** límites a la vez:

| Límite | Clave | Para qué |
|---|---|---|
| 120/min | `lector:<código>` | Cada aparato tiene su presupuesto: uno que sondee mucho no deja sin marcar a los demás. |
| 300/min | `ip:<ip>` | Techo global. El código viaja en una cabecera y **todavía no está autenticado** cuando el limitador actúa; sin este segundo límite bastaría rotar el valor de la cabecera para saltarse el primero. |

120/min por lector sobra: un sondeo cada 3 s son 20, más marcaciones y progreso.

**Por qué el ping sigue abierto.** Es la herramienta para diagnosticar por qué algo
*no* funciona. Cerrado, un 401 dejaría al técnico sin saber si el problema es el
Wi-Fi, el DNS, el vhost, la URL o el token. Abierto, separa el problema en dos.
No revela nada y no escribe nada. Si se le mandan las cabeceras de credencial,
además contesta si son válidas — así se verifica el token del firmware sin generar
una marcación de prueba que después haya que explicar en la planilla.

## Autenticación del dispositivo

Dos cabeceras en cada petición que escribe:

```
X-Dispositivo: lector-entrada
X-Dispositivo-Token: <token del lector>
```

- El token se genera con `php artisan asistencia:dispositivo` y **se muestra una
  sola vez**. En base queda su **SHA-256**; el valor en claro no se guarda, no se
  registra en log y no vuelve en ninguna respuesta.
- Es SHA-256 y no bcrypt a propósito: es un secreto de máquina con entropía alta
  (no adivinable por diccionario) y el lector autentica en cada marcación, así que
  un hash lento solo agregaría latencia frente a quien espera en la puerta. Es el
  mismo criterio de Sanctum. La comparación va con `hash_equals`.
- **Un token por lector**, guardado en su fila. Revocar uno es `activo = false` o
  rotarle el token; los demás siguen trabajando. Un token global en `.env` no
  permitiría ninguna de las dos cosas.
- El 401 es **neutro**: no distingue «falta la cabecera» de «ese lector no existe»
  ni de «el token está mal», para que nadie deduzca desde la red qué lectores hay.

`ASISTENCIA_DISPOSITIVO_TOKEN` (opcional, en `.env`) la lee **solo** el comando de
alta, para poder fijar desde configuración el token que se va a quemar en el
firmware. **Nunca** se lee al autenticar una petición: quien autentica es siempre
la fila del lector.

Pendiente para cuando el lector salga de la LAN de confianza: firmar el cuerpo con
HMAC-SHA256 incluyendo *nonce* y marca de tiempo (evita que quien capture una
petición la reenvíe), y HTTPS obligatorio. Hoy el token viaja en claro sobre la red
local, que es una decisión consciente mientras lector y servidor comparten LAN.

## Entrada o salida

Se **alterna dentro del día local**:

- sin marcaciones hoy → **entrada** (nadie sale de donde no entró);
- la última de hoy fue entrada → **salida**;
- la última de hoy fue salida → **entrada**.

Así quien entra, sale a almorzar y vuelve produce entrada/salida/entrada/salida sin
que el módulo tenga que saber todavía qué es un almuerzo. La regla vive en
`App\Enums\Asistencia\TipoMarcacion::siguienteTras()`.

**Límite conocido:** el día se corta a medianoche local. Un turno de noche que cruce
las 00:00 dejará una salida sin entrada en el segundo día. Se resuelve cuando
existan los horarios — que son los que dicen a qué jornada pertenece una marcación.
Inventar hoy una regla de turnos sin horarios sería adivinar.

## Ventana de cortesía (cooldown)

`ASISTENCIA_COOLDOWN_SEGUNDOS`, **90 s** por defecto.

Si la persona marcó hace menos que eso, **no se escribe nada** y se responde qué
marcó antes y cuánto falta. Se compara contra su última marcación **sin importar el
día**: dos toques separados por diez segundos son un dedo repetido aunque caigan a
un lado y otro de la medianoche.

No es un error rojo a propósito: el lector muestra «Ya marcaste Entrada a las
07:02:10» y la persona se va tranquila. Un error la haría insistir.

Todo corre en una transacción que **bloquea la fila del empleado**: sin ese bloqueo,
dos peticiones simultáneas (el firmware reintentando porque no le llegó la
respuesta) podrían leer las dos «no hay marcación reciente» y escribir las dos.

## Contrato con el firmware

Lo que manda el ESP32:

```json
{ "fingerprint_id": 1 }
```

Todas las respuestas traen `ok`, `estado` y `mensaje`. **El firmware ramifica sobre
`estado`** (cadena estable) y pinta `mensaje` en la pantalla.

| `estado` | HTTP | Cuándo |
|---|---|---|
| `registrada` | 200 | Se escribió la marcación |
| `huella_desconocida` | 404 | Ranura sin asociar, o huella dada de baja |
| `empleado_inactivo` | 403 | La huella es de alguien que ya no marca |
| `cooldown` | 409 | Dedo repetido dentro de la ventana. **No** se escribió nada |
| `payload_invalido` | 422 | Falta `fingerprint_id` o no es un entero |
| `dispositivo_no_autorizado` | 401 | Sin token válido de lector |

409 y no 429 para el cooldown: 429 es lo que devuelve el limitador de peticiones y
el firmware tiene que poder distinguir «marcaste hace un momento» de «estás
saturando el servidor».

### Entrada

```json
{
  "ok": true,
  "estado": "registrada",
  "mensaje": "Entrada registrada",
  "empleado": { "id": 1, "nombre": "Ana María Pérez Rivas", "nombre_corto": "Ana Pérez" },
  "marcacion": {
    "id": 1,
    "tipo": "entrada",
    "tipo_label": "Entrada",
    "fecha": "2026-08-20",
    "hora": "07:02:10",
    "fecha_hora": "2026-08-20 07:02:10",
    "zona": "America/El_Salvador"
  }
}
```

### Salida

Idéntica, con `"tipo": "salida"`, `"tipo_label": "Salida"` y
`"mensaje": "Salida registrada"`.

### Huella desconocida

```json
{ "ok": false, "estado": "huella_desconocida", "mensaje": "Huella no registrada", "fingerprint_id": 99 }
```

### Doble marcación

```json
{
  "ok": false,
  "estado": "cooldown",
  "mensaje": "Ya marcaste Entrada a las 07:02:10",
  "empleado": { "id": 1, "nombre": "Ana María Pérez Rivas", "nombre_corto": "Ana Pérez" },
  "espera_segundos": 88,
  "marcacion_previa": { "id": 1, "tipo": "entrada", "tipo_label": "Entrada", "fecha": "2026-08-20", "hora": "07:02:10", "fecha_hora": "2026-08-20 07:02:10", "zona": "America/El_Salvador" }
}
```

### Dispositivo no autorizado

```json
{ "ok": false, "estado": "dispositivo_no_autorizado", "mensaje": "Dispositivo no autorizado" }
```

`nombre_corto` (primer nombre + primer apellido) existe porque en 128×128 píxeles
no cabe un nombre completo. Se deriva, no se guarda.

## Enrolamiento remoto (registrar una huella desde la web)

Registrar una huella son dos cosas a la vez: **grabar la plantilla en el AS608** y
**anotar de quién es esa ranura**. Antes solo se podía hacer la segunda desde la
web; la primera exigía reprogramar el ESP32 con el número del empleado quemado en
el sketch, subirlo, y acordarse de qué ranura tocaba. El enrolamiento remoto hace
las dos con un botón en la ficha de la persona.

### El flujo, de punta a punta

```
Ficha del empleado
  └─ «Registrar huella» ──► Laravel elige y APARTA una ranura, crea la orden
                                     │
              (el lector sondea cada ~3 s mientras está ocioso)
                                     ▼
                        GET /pendiente ──► se lleva la orden + un token
                                     │
                        TFT: «COLOQUE EL DEDO»      → POST /progreso
                        primera captura
                        TFT: «RETIRE EL DEDO»       → POST /progreso
                        segunda captura
                        AS608 guarda la plantilla en la ranura reservada
                                     ▼
                        POST /resultado {exito, fingerprint_id}
                                     │
                        Laravel llama a AsignarHuella ──► nace asistencia_huella
                                     ▼
                        La ficha lo muestra en el siguiente refresco
```

### El lector PREGUNTA; el servidor no llama

El ESP32 de hoy **no puede recibir órdenes**: comprobado en el firmware actual, es
un cliente HTTP que abre la conexión, manda su marcación y cierra. No escucha en
ningún puerto.

Se eligió mantenerlo así, con sondeo, y no montarle un servidor HTTP dentro:

| | Sondeo (elegido) | Empuje (descartado) |
|---|---|---|
| Credenciales | Reutiliza el token del lector, que ya existe y ya funciona | Haría falta inventar una **en dirección contraria**; sin ella cualquiera en la LAN dispararía enrolamientos |
| Direcciones | El lector conoce la del servidor y no cambia | Laravel necesitaría la IP del lector, que reparte el DHCP y que `ultima_ip` refleja tarde |
| Red | Sobrevive al NAT y al día que este servidor viva fuera de la LAN | Solo funciona mientras los dos estén en la misma red |
| Firmware | Un `GET` más en el bucle | Servidor HTTP corriendo mientras maneja el AS608 y la pantalla |

El precio es la latencia. No importa: enrolar es un acto supervisado, con alguien
de pie frente al lector. Tres segundos no se notan.

### La regla que ordena todo lo demás

> **No existe `asistencia_huella` hasta que el AS608 confirma que grabó.**

Crear la orden no asigna nada. Sondearla, tampoco. Reportar progreso, tampoco. Si
el enrolamiento falla, se cancela o vence, **no queda ninguna huella fantasma**:
solo la orden en su estado final, que sirve para explicar qué pasó.

La asignación se hace llamando a `AsignarHuella` — el mismo servicio de la
asignación manual — nunca escribiendo `asistencia_huellas` a mano. Así una ranura
reutilizada produce una fila **nueva** y jamás toca la anterior, igual que siempre.

### Los estados de una orden

| Estado | Qué significa | ¿Viva? |
|---|---|---|
| `pendiente` | Creada. El lector todavía no la recogió. | sí |
| `tomada` | El lector la sondeó y tiene su token. Espera el dedo. | sí |
| `en_curso` | Reportó progreso: está capturando. | sí |
| `completada` | El AS608 grabó y la huella quedó asignada. | no |
| `fallida` | No pudo. `motivo_fallo` dice por qué. | no |
| `expirada` | Nadie la atendió a tiempo. | no |
| `cancelada` | Alguien la canceló desde la web. | no |

Los tres estados vivos son la lista `EstadoOrdenEnrolamiento::VIVOS`, y **la misma
lista está escrita en las columnas generadas** de la tabla (abajo). Cambiar una sin
la otra rompería los únicos.

### Dos únicos parciales, otra vez con columnas generadas

MySQL no tiene índices únicos parciales. Se usa la misma técnica que ya sostiene
`asistencia_huellas.fingerprint_id_activo`: una columna **generada** que vale
`NULL` cuando la fila no cuenta —y los NULL no colisionan en un índice único—.

```sql
orden_activa_uq    = CASE WHEN estado IN ('pendiente','tomada','en_curso') THEN 1               ELSE NULL END
ranura_reservada_uq = CASE WHEN estado IN ('pendiente','tomada','en_curso') THEN ranura_reservada ELSE NULL END

UNIQUE (asistencia_dispositivo_id, orden_activa_uq)      -- un lector, una orden viva
UNIQUE (asistencia_dispositivo_id, ranura_reservada_uq)  -- una ranura, una reserva viva
```

`VIRTUAL`, no `STORED`: es la única forma que SQLite acepta en `ALTER TABLE ADD
COLUMN`, y las pruebas corren sobre SQLite.

Qué garantiza cada uno:

- **El primero**, que un ESP32 no reciba dos órdenes a la vez. No puede enrolar dos
  huellas al mismo tiempo: tiene un sensor y una pantalla.
- **El segundo**, que dos órdenes vivas no aparten la misma ranura. Es lo que hace
  que la reserva sea real y no una intención.

Cuando una orden termina —del modo que sea— las dos columnas vuelven a `NULL` y el
buzón y la ranura quedan libres. El historial **crece**; nada se borra ni se
reescribe.

### Cómo se elige la ranura

Automáticamente, y **no se escribe ningún número** en el flujo normal. Se toma la
menor libre a partir de 0, excluyendo la **unión** de tres fuentes:

1. **Asignadas** — `asistencia_huellas` activas de ese lector. Las sabe la base.
2. **Reservadas** — órdenes vivas de ese lector. Las sabe la base.
3. **Ocupadas físicamente** — plantillas que el AS608 dice tener. **Solo las sabe
   el sensor.**

La tercera existe porque la verdad está partida: la base sabe a quién corresponde
cada ranura, pero solo el sensor sabe qué ranuras tienen plantilla. Un sensor que
se usó antes de que existiera este sistema tiene plantillas que la base ignora.

**Sin índice sincronizado no se reserva a ciegas.** Si el lector nunca reportó su
capacidad, crear una orden falla con *«el lector todavía no ha sincronizado sus
ranuras»*. `NULL` en `capacidad_sensor` significa «nunca sincronizó», que es un
estado distinto de «sincronizó y está vacío». La capacidad **la dice el sensor**;
162 no es una verdad fija del sistema.

Entre elegir el número y escribirlo hay una ventana en la que otra petición puede
quedarse con él. Cuando el único de la base lo detecta, la creación **reintenta**
con una selección nueva —que ya ve la reserva rival—, hasta tres veces. Devolver un
error ahí sería fallarle a la persona por una carrera que el sistema resuelve solo.

### Cuando el sensor tenía una plantilla que nadie conocía

El caso de los sensores heredados. El lector recibe la orden, va a grabar en la
ranura reservada y el AS608 le dice que ahí ya hay algo.

**No se sobrescribe.** Sobrescribir borraría la huella de alguien —quizá de alguien
que sigue marcando con ella— sin que nadie lo pidiera. En su lugar:

1. El lector reporta `ranura_ocupada_en_sensor` y, con el mismo mensaje, **su
   índice real** (`indice.capacidad`, `indice.ocupadas`).
2. Laravel guarda ese índice **antes** de fallar la orden, para que lo siguiente ya
   lo tenga en cuenta.
3. La orden queda `fallida` con ese motivo.
4. Se crea una orden **nueva**, con otra ranura, ya excluyendo la que resultó
   ocupada. La respuesta la trae en `reintento.orden_id` para que el lector siga sin
   esperar al próximo sondeo.

La cadena está acotada a `MAX_INTENTOS = 3`: un sensor lleno de plantillas
heredadas no puede generar reintentos infinitos.

### La vida de la orden: 3 minutos

Una orden vive `MINUTOS_DE_VIDA = 3`. Es tiempo de sobra para que alguien coloque
el dedo dos veces, y poco para que una orden olvidada siga apartando una ranura.

**Una orden vencida no revive ni se ejecuta después.** No hay cron: las vencidas se
materializan **justo antes** de crear una orden o de entregar el sondeo
(`ExpirarOrdenesVencidas`), que son los dos únicos momentos en que su existencia
estorba. Es idempotente y no depende del scheduler, que en este proyecto no corre.

`POST /progreso` **refresca** el vencimiento: mientras el lector reporta, hay
alguien delante. Capturar la huella de una persona que tarda no debe expirar a
mitad.

### El token de la orden

Cada entrega del sondeo emite un token de un solo uso: `Str::random(32)`, del que
en base queda **solo su SHA-256** (mismo criterio que el token del lector, con
`hash_equals`). Sale del servidor **una vez** — en la respuesta del sondeo — y no
se puede recuperar.

Volver a sondear una orden ya tomada **reemite el token e invalida el anterior**.
Si el lector vuelve a preguntar es porque no recibió la respuesta; que el token
viejo deje de valer cierra la ventana en la que dos copias de la misma orden
podrían responder.

### Idempotencia

El ESP32 está detrás de una red doméstica: puede grabar la plantilla, mandar el
resultado y perder la respuesta. Si reintenta, obtiene **el mismo desenlace**, no
un error ni una segunda asignación. Una orden ya finalizada no se vuelve a
procesar: se devuelve lo que pasó la primera vez.

Sin esto, un reintento con éxito intentaría crear dos huellas para la misma ranura,
el único de la Fase 1 lo rechazaría, y un enrolamiento **correcto** acabaría
contado como fallo.

### Endpoints del lector

Los cuatro exigen token de lector (`dispositivo.asistencia`) y viven bajo
`/api/asistencia/enrolamiento`. **Un navegador no puede tocarlos**: no conoce el
token y la web nunca lo muestra.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/pendiente` | El sondeo. Entrega la orden y su token. |
| `POST` | `/{orden}/progreso` | Informativo. Mueve a `en_curso` y refresca el vencimiento. |
| `POST` | `/{orden}/resultado` | El acto. Idempotente. |
| `POST` | `/indice-sensor` | La capacidad y las ranuras ocupadas que reporta el AS608. |

**Sondeo sin nada que hacer:**

```json
{ "ok": true, "hay_orden": false, "sincronizar_indice": true }
```

`sincronizar_indice: true` es el servidor pidiéndole al lector que reporte su
índice. Así el firmware no tiene que acordarse por su cuenta: en cuanto lo ve, hace
`POST /indice-sensor` y a partir de ahí ya se pueden crear órdenes.

**Sondeo con orden:**

```json
{
  "ok": true,
  "hay_orden": true,
  "orden": {
    "id": 12,
    "empleado": { "id": 3, "nombre_corto": "Ana Pérez" },
    "ranura": 7,
    "capacidad": 162,
    "expira_en": 178,
    "intento": 1,
    "token": "…32 caracteres, la única vez que sale…"
  }
}
```

`nombre_corto` porque la pantalla del lector mide 128×128.

**Resultado, éxito:**

```json
{ "token": "…", "exito": true, "fingerprint_id": 7 }
```

`fingerprint_id` tiene que ser **exactamente** la ranura reservada. Se le dijo
dónde grabar; si dice haber grabado en otro sitio no se asocia nada
(`ranura_no_coincide`): o el firmware improvisó, o el mensaje viene corrupto.

**Resultado, fallo:**

```json
{ "token": "…", "exito": false, "motivo": "dedos_no_coinciden", "detalle": "opcional" }
```

Y con el índice, cuando el motivo es el conflicto de ranura:

```json
{ "token": "…", "exito": false, "motivo": "ranura_ocupada_en_sensor",
  "indice": { "capacidad": 162, "ocupadas": [0, 1, 5] } }
```

**Índice del sensor:**

```json
{ "capacidad": 162, "ocupadas": [0, 1, 5] }
```

Las ranuras fuera de rango se descartan y las repetidas se colapsan. Es telemetría:
se guarda sin generar auditoría.

### Motivos de fallo: quién puede alegar qué

`motivo` es un **código cerrado** sobre el que el firmware ramifica. El texto de
`mensaje` es para la pantalla y puede cambiar; el código, no.

El lector solo puede alegar lo que él puede ver:

| Reportable por el lector | Solo lo decide el servidor |
|---|---|
| `sin_sensor` | `expirada` |
| `timeout_dedo` | `ranura_ya_asignada` |
| `captura_defectuosa` | `ranura_no_coincide` |
| `dedos_no_coinciden` | `empleado_no_elegible` |
| `fallo_modelo` | `cancelada_por_operador` |
| `fallo_guardado` | |
| `ranura_ocupada_en_sensor` | |
| `cancelada_en_dispositivo` | |

Si un lector pudiera declarar los de la derecha, podría cerrar una orden alegando
algo que no observó. El `FormRequest` los rechaza con `422` y
`motivo: payload_invalido`.

### Seguridad

- **Ningún navegador puede hacerse pasar por un lector.** Desde la web solo existen
  dos rutas: pedir el registro y cancelarlo. No hay ninguna que complete una orden
  —hay una prueba que enumera las rutas y lo verifica—, y las del lector exigen un
  token que la web no conoce.
- **Ninguna orden ajena.** Cada endpoint resuelve el lector desde su token y
  comprueba que la orden le pertenece. El rechazo es **el mismo 404** para «no es
  tuya» y para «token equivocado», así nadie puede averiguar desde la red qué
  órdenes existen probando identificadores.
- **El token nunca sale a la web.** Ni el del lector ni el `token_hash` de la orden
  aparecen en la ficha, en la auditoría ni en ninguna respuesta.
- **Auditoría sin secretos.** Las órdenes se registran con `activitylog`
  excluyendo `token_hash` explícitamente del `logOnly`. Queda quién pidió el
  registro (`solicitada_por_user_id`), sobre quién, en qué lector, qué ranura y
  cómo terminó.

### Desde la ficha del empleado

La tarjeta «Registrar huella con el lector» pide el lector y nada más. Mientras hay
una orden viva muestra su estado y un botón de cancelar, y **la página se refresca
sola cada 3 s** — sin conexión permanente es lo único honesto, y el enrolamiento
dura menos de un minuto. Debajo quedan los últimos tres intentos terminados, que es
cómo se entiende por qué falló el anterior.

Cancelar es seguro en cualquier momento: si el lector ya grabó y todavía no
reportó, su resultado llegará sobre una orden final y se tratará como reintento,
sin crear nada.

**Opciones avanzadas → ranura manual.** Cerrado por defecto y con su advertencia.
Existe para **un** caso: sensores que ya traían plantillas de antes, donde hace
falta apuntar a un hueco concreto que la persona conoce. No se salta ninguna
protección —se comprueba contra las tres fuentes igual que la automática y el único
de la base sigue mandando— pero sí desactiva la elección automática, y por eso queda
anotado en la orden (`ranura_manual`). Si esa ranura resulta ocupada en el sensor,
el reintento vuelve a ser **automático**: repetir el número elegido llevaría al
mismo choque.

### El firmware del lector

El firmware vive en **`firmware/asistencia/`** y está escrito contra este
contrato. Su `README.md` tiene el pinout, las versiones de librería verificadas y
las reglas de diseño que no se pueden romper.

Lo que hace, en el orden en que ocurre:

1. `POST /indice-sensor` al arrancar, y cada vez que el sondeo contesta
   `sincronizar_indice: true`. La capacidad sale de `finger.getParameters()` —**no**
   de `verifyPassword()`, que no la llena— y las ocupadas de barrer el sensor con
   `loadModel()` ranura por ranura. El barrido tarda 1-3 s y nunca corre con un
   dedo apoyado.
2. `GET /pendiente` cada 3 s **solo cuando el AS608 devuelve `FINGERPRINT_NOFINGER`**.
   Son 20 peticiones/min, muy por debajo de las 120 que da el limitador.
3. Guarda el `token` de la orden en RAM y lo manda en cada `progreso` y en el
   `resultado`.
4. Captura en **la ranura que dice la orden** — nunca en otra — con la secuencia
   `getImage` → `image2Tz(1)` → soltar → `getImage` → `image2Tz(2)` → `createModel`
   → `loadModel(ranura)` → `storeModel(ranura)`.
5. Mapea cada error del AS608 a su `motivo`, y comprueba la ranura con `loadModel`
   **dos veces**: una antes de pedir el dedo (para no hacer trabajar a la persona
   en vano) y otra justo antes de grabar.
6. Reintenta el `POST /resultado` con backoff y, si aun así no lo entrega, lo deja
   pendiente en RAM y lo reenvía desde el bucle en reposo. Es idempotente: no
   duplica nada.

**El enrolamiento es bloqueante respecto al bucle principal.** Mientras captura no
se sondea otra orden y no se procesa ninguna marcación. Es una condición de
corrección, no una comodidad: `TomarOrdenEnrolamiento` **reemite el token** en cada
sondeo que encuentre la orden viva, así que un sondeo en paralelo invalidaría el
token que el lector tiene en RAM y su `resultado` moriría con un 404 **después** de
haber grabado la plantilla en el sensor.

Las pantallas del TFT («COLOQUE EL DEDO», «RETIRE EL DEDO», el nombre corto de la
persona) salen de los datos de la orden; el `POST /progreso` es secundario —un
fallo de red ahí no aborta nada— pero es lo que hace que quien mira la web vea lo
mismo que quien está frente al lector.

**Lo que el lector todavía no puede alegar.** No existe un motivo para «este dedo
ya está enrolado en otra ranura», así que el firmware **no** lo detecta: inventar un
código rompería el contrato. Si hiciera falta, es un cambio de
{@see MotivoFalloEnrolamiento}, no del firmware.

### Qué se probó con el lector delante

Todo lo de abajo se ejercitó **físicamente**, con el ESP32 conectado y personas
reales poniendo el dedo. No son pruebas simuladas.

| Escenario | Desenlace observado |
|---|---|
| Marcación normal (entrada y salida) | `registrada`, 200 |
| Dedo repetido dentro de la ventana | `cooldown`, 409, sin escribir nada |
| Enrolamiento remoto completo | orden → dos capturas → `storeModel` → `completada` → la persona marca con su huella nueva |
| Dedo mal colocado | `captura_defectuosa`, sin huella |
| Nadie pone el dedo (20 s) | `timeout_dedo`, sin huella |
| Dedos distintos en las dos capturas | `dedos_no_coinciden`, nada grabado en el sensor |
| Ranura con plantilla heredada | `ranura_ocupada_en_sensor`, **sin sobrescribir**, y orden nueva automática en otra ranura |
| Caída de Wi-Fi | `SIN SERVIDOR` en pantalla, ninguna marcación escrita |
| Apache detenido | mismo camino, punto API en rojo, nada encolado |
| Recuperación de red y servidor | los dos puntos vuelven a verde solos, el sondeo se reanuda |
| Sincronización del índice | `POST /indice-sensor` → 200, capacidad y ocupadas correctas |

**El sensor instalado.** AS608 de **capacidad 300**, rango real **0..299**. La
ranura 0 **es válida**, confirmado de dos formas independientes: `storeModel(0)`
devolvió OK y el barrido posterior releyó esa ranura como ocupada. El «ID #0 not
allowed» del ejemplo `enroll.ino` de Adafruit **no es una regla del sensor**: es
una defensa contra `Serial.parseInt()`, que devuelve 0 cuando no se tecleó nada.

### ⚠️ Lo que NO se validó físicamente

**`storeModel` correcto y caída de red ANTES de entregar el resultado.**

Es el escenario en que la plantilla ya está grabada en el AS608 y el `POST
/resultado` no llega. La lógica existe —tres reintentos con espera creciente, el
cuerpo queda en RAM y el bucle lo reenvía en reposo cada 15 s— y el endpoint es
idempotente y está cubierto por pruebas del lado servidor. Lo que **no** ocurrió
nunca es un corte real en esa ventana: dura milisegundos y las dos ventanas
manuales que se intentaron (5 s y 15 s) no alcanzaron para detener Apache a tiempo.

Queda **pendiente de validación física**. La forma de cerrarlo sin ventana manual
es desconectar el cable de red del servidor en vez de parar Apache.

**Límite conocido asociado.** El resultado pendiente vive **solo en RAM**: no hay
NVS ni EEPROM en el firmware. Si el ESP32 se reinicia con un resultado sin
entregar, se pierde — la plantilla queda en el sensor y la orden expira a los 3
minutos. Se auto-repara: al reintentar el enrolamiento, el servidor elegirá esa
misma ranura, el firmware detectará `ranura_ocupada_en_sensor` y saltará a la
siguiente, dejando una plantilla huérfana.

### Antes de desplegar a producción

Dos tareas que **no** se ejecutan solas y que en desarrollo hubo que hacer a mano:

1. **Las dos migraciones de Fase 5**, con la corrección de los nombres de clave
   foránea. La versión original reventaba en MySQL con un `1059` —identificador de
   65 caracteres sobre un límite de 64— y **ninguna prueba podía verlo**, porque
   la suite corre sobre SQLite, que no tiene ese límite.
2. **`php artisan db:seed --class=RolesSeeder`.** Los permisos `asistencia.*` se
   crean ahí. Sin ese paso el módulo queda instalado y el área **invisible en el
   menú**, incluso para el administrador: `AreaSistema::visiblesPara()` filtra por
   permiso, y un permiso que no existe en la tabla no lo tiene nadie.

Las dos comparten causa: **el estado de la base no viaja con el código**, y la
suite no puede avisar porque no corre contra esa base.

## Puesta en servicio del lector definitivo

Las huellas que hay hoy en el AS608 son **de desarrollo**: se grabaron probando el
enrolamiento, algunas pertenecen a la misma persona repetida y otras quedaron
huérfanas de pruebas de robustez. **Ninguna sirve para producción.**

**El sensor definitivo se entrega VACÍO.** Las huellas reales del personal se
enrolan desde cero, una por una, con el lector ya instalado en su ubicación
definitiva. No se migran, no se reaprovechan y no se copian: una plantilla
biométrica grabada en un banco de pruebas no tiene por qué corresponder a quien
dice la base, y comprobarlo cuesta más que volver a tomar el dedo.

### ⚠️ Todavía NO se borra nada

Las huellas actuales **hacen falta** mientras se termina el montaje físico: son las
que permiten comprobar que el lector marca en su sitio nuevo, con su cableado
nuevo y su alimentación nueva.

La limpieza se hace **después** de tres cosas, en este orden:

1. **Firmware congelado** — sin más cambios pendientes en `firmware/asistencia/`.
2. **Pinout congelado** — el cableado definitivo, ya montado.
3. **Montaje validado** — el lector en su ubicación final, marcando de verdad.

Borrar antes obliga a re-enrolar para seguir probando, y esas huellas nuevas
habría que borrarlas otra vez.

### El orden de la limpieza

1. **Liberar en Laravel las asignaciones de prueba**, desde la ficha de cada
   persona. Liberar **no borra**: la fila queda con su `liberada_at` y las
   marcaciones históricas siguen apuntando a ella. Ver «Reutilizar una ranura».
2. **Vaciar el sensor.** El firmware no expone esa operación a propósito —no hay
   ningún camino desde la web ni desde el lector que borre plantillas— así que se
   hace cargando temporalmente el ejemplo `emptyDatabase` de la librería
   Adafruit_Fingerprint y volviendo a cargar `asistencia.ino` después.
3. **Reiniciar el ESP32** para que re-sincronice su índice. Sin este paso el
   servidor sigue creyendo ocupadas las ranuras que ya se vaciaron.

### Verificación antes de dar el lector por listo

Los tres tienen que cumplirse a la vez:

| Qué | Dónde se comprueba | Valor exigido |
|---|---|---|
| Sensor sin plantillas | Serial, al arrancar | `Huellas guardadas: 0` |
| Índice físico sin ocupadas | Serial, al arrancar | `RANURAS OCUPADAS EN EL SENSOR: 0` |
| Sin asignaciones activas de prueba | base | ninguna `asistencia_huellas` con `activo = true` |

En base, lo que no debe devolver ninguna fila:

```sql
SELECT id, asistencia_empleado_id, fingerprint_id
FROM asistencia_huellas
WHERE activo = 1;
```

Y el índice del lector, que debe quedar sincronizado y vacío:

```sql
SELECT capacidad_sensor, ranuras_ocupadas, indice_sincronizado_at
FROM asistencia_dispositivos WHERE codigo = 'lector-entrada';
```

`ranuras_ocupadas` tiene que ser `[]` — **no `NULL`**. Vacío significa «sincronizó
y no tiene nada»; `NULL` significa «nunca sincronizó», y con eso el enrolamiento se
niega a reservar ranura.

### Lo que SÍ se conserva

**El historial no se toca.** Marcaciones, jornadas, órdenes de enrolamiento y
huellas liberadas se quedan donde están: son append-only y su valor es
precisamente registrar lo que pasó, incluidas las pruebas. Nadie va a confundir una
marcación de agosto de un banco de pruebas con una jornada real, y borrarla
destruiría la trazabilidad de cómo se validó el módulo.

Lo que tiene que quedar limpio es **el sensor físico** y **las asignaciones
activas**. Nada más.

## Administración

```powershell
# Dar de alta el lector. Muestra el token UNA vez.
php artisan asistencia:dispositivo lector-entrada --nombre="Entrada principal"

# Rotar el token (el firmware anterior deja de autenticar).
php artisan asistencia:dispositivo lector-entrada --rotar

# Dar de alta un empleado y asociarle la ranura donde ya está su huella.
php artisan asistencia:empleado "Ana María" "Pérez Rivas" --dispositivo=lector-entrada --fingerprint=1
```

Ambos comandos muestran qué van a crear y piden confirmación. Ninguno borra ni pisa
nada: si la ranura tiene una asignación VIGENTE, se detienen. Que tenga
asignaciones HISTÓRICAS no estorba — eso es lo que permite reutilizarla.

Desde la Fase 2 lo habitual es hacer todo esto **desde las pantallas**; los
comandos se quedan por dos motivos concretos: `asistencia:dispositivo` porque un
secreto que pasa por el navegador queda en el historial y en la caché, y
`asistencia:empleado` porque sirve para montar el módulo antes de que exista un
usuario con permisos.

Las dos vías comparten servicio y no compiten: `asistencia:empleado` delega en
`AsignarHuella` y `asistencia:dispositivo` en `RotarTokenDispositivo`, así que la
comprobación de ranura ocupada, el hasheo y la auditoría son los mismos desde la
consola que desde la web.

Guardar la plantilla biométrica en la ranura N es un acto del sensor, no de
Laravel. Estos comandos solo anotan a quién corresponde.

## Probarlo

```powershell
# Diagnóstico (sin credenciales)
Invoke-RestMethod http://facturacion.test/api/asistencia/ping

# Marcación
Invoke-RestMethod -Method Post http://facturacion.test/api/asistencia/marcar `
  -Headers @{ 'X-Dispositivo' = 'lector-entrada'; 'X-Dispositivo-Token' = '<token>' } `
  -ContentType 'application/json' `
  -Body '{"fingerprint_id":1}'
```

**Ojo con el nombre del host.** Apache sirve este proyecto SOLO cuando la petición
llega con `Host: facturacion.test`. Comprobado: `http://localhost/...` y
`http://<IP-LAN>/...` devuelven el 404 *de Apache* (no el de Laravel), porque caen
en el vhost por defecto de Laragon, cuyo DocumentRoot es otro.

Para el ESP32, que le pega a la IP, hay dos caminos:

1. **Mandar el Host a mano** desde el firmware — no hace falta tocar el servidor:

   ```cpp
   http.begin(client, "192.168.1.143", 80, "/api/asistencia/marcar");
   http.addHeader("Host", "facturacion.test");
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Dispositivo", "lector-entrada");
   http.addHeader("X-Dispositivo-Token", TOKEN);
   ```

2. **Darle un `ServerAlias` a la IP** en el vhost de Laragon. Es cambio de
   infraestructura, no de código; ver
   `docs/apache/facturacion-dulceslanegrita.conf.example`.

Pruebas: `php artisan test tests/Feature/Asistencia`
