# Control de Asistencia — lector de huella ESP32

Marcaciones con un dispositivo físico:

```
huella -> ESP32 -> Wi-Fi -> HTTP -> Laravel -> respuesta JSON -> pantalla TFT
```

Estado: **base funcional**. Hay empleados, huellas, lectores y marcaciones reales.
Todavía **no** hay horarios, tardanzas, ausencias, horas trabajadas, reportes,
planilla ni pantallas de administración: el módulo se administra con los comandos
`asistencia:*`.

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
| Rotación del token | sí (el hecho) | el valor no aparece en ningún lado |
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

`AreaSistema::Asistencia` **no existe todavía**, y no es un olvido: un área exige
una `rutaInicio()` real, y hasta que el módulo tenga pantalla ese enlace sería un
enlace muerto en la barra de todos los administradores.

## Endpoints

| | Ping | Marcar |
|---|---|---|
| Ruta | `GET`/`POST` `/api/asistencia/ping` | `POST` `/api/asistencia/marcar` |
| Nombre | `api.asistencia.ping` | `api.asistencia.marcar` |
| Middleware | `modulo.asistencia`, `throttle:60,1` | + `dispositivo.asistencia` |
| Escribe | Nada | Una marcación (y la última conexión del lector) |

**Por qué viven en `/api`.** Un dispositivo no es un navegador: no tiene sesión,
ni cookie, ni token CSRF. El grupo `api` no monta ninguna de las tres, así que el
POST del firmware funciona sin desactivarle candados al sitio web.

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

`asistencia:empleado` delega la asignación en `AsignarHuella`, así que la
comprobación y la auditoría son las mismas desde la consola que desde la pantalla
que viene. **Liberar una ranura todavía no tiene comando**: la operación existe
(`LiberarHuella`, auditada y probada) y la expondrá la pantalla de administración
de la fase siguiente.

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
