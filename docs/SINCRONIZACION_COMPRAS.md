# Sincronización de compras (buzón Yahoo/IMAP)

Cómo funciona el recorrido del buzón de compras, qué hay que dejar corriendo en el
servidor y cómo recuperar un período histórico.

> **Nada de esto está activado todavía.** El interruptor viene apagado
> (`DOCUMENTOS_RECIBIDOS_AUTO_SYNC=false`) y la tarea programada del servidor no se
> instaló; este documento dice exactamente qué hay que encender y registrar, y cuándo.

---

## 1. Qué cambió y por qué

El lector anterior pedía «los 30 correos más recientes» de todo el buzón:

```php
$ids = imap_search($conn, 'ALL', SE_UID);
rsort($ids);                        // más nuevos primero
$ids = array_slice($ids, 0, 30);    // se cortan los VIEJOS
```

De ahí salían los dos problemas, y los dos eran el mismo:

1. **«Revisar histórico» no retrocedía nunca.** Sin cursor, cada pulsación releía los
   mismos 30 UID más altos. Repetirlo no avanzaba.
2. **La marca incremental pasaba por encima de correos sin leer.** Como el punto de
   partida se deducía del documento guardado más reciente, todo lo que el corte había
   dejado afuera quedaba del lado «ya cubierto» y no se volvía a mirar.

Ahora el recorrido es **por día, y dentro de cada día por páginas de UID ascendente hasta
agotarlo**. Un día solo cuenta como cubierto si se recorrió entero, sin truncar y sin
error. Si no, la marca no lo pasa y la corrida siguiente vuelve a él.

Además:

- La identidad del correo es su **`Message-ID`**, no el UID. El UID solo es único dentro
  de una carpeta y mientras el `UIDVALIDITY` no cambie: mover un correo lo duplicaba, y
  reconstruir el buzón hacía que un UID viejo apuntara a otro correo.
- Un fallo del buzón es una **excepción**, no un arreglo vacío. Antes una contraseña
  vencida se informaba como «Revisión completada, 0 correos revisados», en verde.

El buzón sigue siendo **estrictamente de solo lectura**: `OP_READONLY` y `FT_PEEK`, sin
borrar, sin mover y sin marcar como leído.

---

## 2. Piezas

| Pieza | Dónde | Qué hace |
| --- | --- | --- |
| Comando | `app/Console/Commands/ComprasSincronizarCommand.php` | El recorrido. Incremental sin fechas; recuperación con `--desde/--hasta`. |
| Recorrido | `app/Services/DocumentosRecibidos/SincronizadorDocumentosRecibidos.php` | Día por día, página por página. |
| Lector | `app/Services/DocumentosRecibidos/ImapMailboxClient.php` | `SINCE`/`BEFORE` de un día, UID ascendente, cursor. |
| Progreso | `documentos_recibidos_progreso` (una fila por día) | Única fuente de verdad del avance y de la cobertura. |
| Bitácora | `configuraciones` (`documentos_recibidos.*`) | Última corrida iniciada, último éxito, último error, conteos. |
| Identidad | `app/Services/DocumentosRecibidos/Buzon/IdentidadCorreo.php` | `mid:` · `hash:` · `legado:` |
| Job | `app/Jobs/RecuperarPeriodoCompras.php` | Encola una recuperación desde la pantalla. |

### Estados de un día

| Estado | Significa | ¿Cuenta como cubierto? |
| --- | --- | --- |
| *(sin fila)* | Nunca se miró | No |
| `pendiente` | Se conoce, no se recorrió | No |
| `parcial` | Se leyó una parte; `ultimo_uid` es el cursor | No |
| `completo` | Se recorrió entero, sin truncar y sin error | **Sí** |
| `error` | El buzón falló ese día; queda el motivo | No |

**Solo `completo` cuenta.** Ante la duda se relee: releer es gratis, perder un correo no.

---

## 3. Qué hay que dejar corriendo en producción

La entrada ya está en `routes/console.php`:

```php
Schedule::command('compras:sincronizar --aplicar --solape=2')
    ->everyFifteenMinutes()
    ->when(fn () => (bool) config('documentos_recibidos.sincronizacion_automatica', false))
    ->withoutOverlapping(20)
    ->appendOutputTo(storage_path('logs/compras-sincronizacion.log'));
```

### Dos llaves, las dos necesarias

**Esa línea no hace nada por sí sola.** Hacen falta dos cosas independientes:

| Llave | Dónde | Por defecto |
| --- | --- | --- |
| 1. El interruptor | `DOCUMENTOS_RECIBIDOS_AUTO_SYNC=true` en `.env` | **apagado** |
| 2. Algo que ejecute el scheduler | Tarea del sistema operativo (abajo) | no registrada |

Con una sola, no corre.

#### El interruptor

```dotenv
# .env del servidor
DOCUMENTOS_RECIBIDOS_AUTO_SYNC=true
```

Apagado por defecto **a propósito**: el código puede llegar al servidor antes de que el
buzón esté configurado y —más importante— antes de recuperar el backlog histórico. Ese es
el orden correcto: si la automática arranca primero, la marca de progreso se establece
sobre los últimos días y el backlog queda fuera del barrido incremental.

Vive en `.env` y no en la base porque es una decisión de despliegue, y porque se lee
cuando el scheduler evalúa la tarea — un momento en el que la base puede no estar migrada.
La tarea **siempre está declarada**: el interruptor decide si se ejecuta, no si existe, así
que se puede inspeccionar y probar aunque esté apagada.

> Si el servidor usa `php artisan config:cache`, hay que volver a cachear después de tocar
> el `.env`: `php artisan config:clear && php artisan config:cache`.

#### `--aplicar` no es decorativo

El comando es **dry-run por defecto**, para que una corrida manual sea segura. Por eso la
tarea programada pide el modo de aplicación explícitamente. Si alguien quitara ese flag,
la sincronización automática leería el buzón y no guardaría nada, y el sistema *parecería*
estar funcionando: franja verde, bitácora con éxitos, y ni un documento. Hay una prueba
que falla si el flag desaparece (`ComprasTareaProgramadaTest`).

#### Quién ejecuta el scheduler

Laravel no tiene un demonio propio: hace falta que el sistema operativo lo ejecute. En este
servidor (Windows) hay dos formas, y solo hace falta **una**.

### Opción A — Programador de tareas de Windows (recomendada)

Un `schtasks` que corra `schedule:run` cada minuto. Es el mismo mecanismo que ya
documenta `docs/ARRANQUE_WINDOWS.md` §4 y del que depende `ppq:sincronizar-albaranes`.

```cmd
schtasks /Create /TN "Scheduler Facturacion" ^
  /TR "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\Facturacion\artisan schedule:run" ^
  /SC MINUTE /MO 1 /RL HIGHEST /F
```

`schedule:run` **arranca, mira qué toca y termina**. No queda vivo: por eso corre cada
minuto. Es la opción robusta en Windows, porque si el proceso muere, el minuto siguiente
hay otro.

Para comprobar que está registrada y corriendo:

```powershell
Get-ScheduledTask -TaskName "Scheduler Facturacion"
Get-ScheduledTaskInfo -TaskName "Scheduler Facturacion"   # LastRunTime / LastTaskResult
```

> **Verificar esto ANTES de dar por hecho nada.** En la máquina de desarrollo no hay
> ninguna tarea registrada. Si en el servidor tampoco está, entonces
> `ppq:sincronizar-albaranes` tampoco se está ejecutando, y el problema es más grande que
> compras.

### Opción B — `schedule:work` como proceso permanente

```cmd
php artisan schedule:work
```

Este **sí queda vivo** y despierta cada minuto por su cuenta. Es cómodo para desarrollo,
pero en un servidor hay que envolverlo en algo que lo reinicie si muere (como hace
`scripts\windows\queue-worker-auto.bat` con el worker de colas). Con la opción A no hace
falta.

### El worker de colas también

«Recuperar período» desde la pantalla **encola** un job. Si el worker de colas no está
corriendo, el botón responde «recuperación encolada» y no pasa nada más. El worker ya
está documentado en `docs/WORKER_WINDOWS.md` y se registra con
`scripts\windows\registrar-tareas-windows.ps1`.

Desde la línea de comandos, la recuperación **no** depende del worker: `compras:sincronizar`
corre en el momento.

### Tres bloqueos, cada uno para algo distinto

- `withoutOverlapping(20)` evita que dos corridas **del scheduler** se solapen.
- `Cache::lock('compras:recuperacion')` se toma al **encolar** una recuperación desde la
  pantalla y lo suelta el trabajo al terminar: impide que se acumulen recuperaciones en
  cola. Si solo se tomara al ejecutar, apretar «Recuperar» tres veces dejaría tres
  trabajos esperando que después se bloquean entre sí de a uno.
- El comando toma además su propio `Cache::lock('compras:sincronizar')`, que cubre también
  al botón de la pantalla y a una corrida manual desde la consola —cosas que
  `withoutOverlapping` no ve—.

---

## 4. Uso del comando

```bash
# Ver qué haría, sin escribir nada (por defecto)
php artisan compras:sincronizar --desde=2026-08-01 --hasta=2026-08-31

# Recuperar un período de verdad
php artisan compras:sincronizar --desde=2026-08-01 --hasta=2026-08-31 --aplicar

# Corrida incremental (lo que hace el scheduler)
php artisan compras:sincronizar --aplicar --solape=2

# Tras una reconstrucción del buzón (UIDVALIDITY cambiado)
php artisan compras:sincronizar --reiniciar-uid-validity --aplicar
```

| Opción | Por defecto | Para qué |
| --- | --- | --- |
| `--desde` / `--hasta` | marca de progreso / hoy | Rango explícito: recuperación histórica. |
| `--dias` | — | Ventana de N días hacia atrás desde `--hasta`. |
| `--solape` | `2` | Días que se releen sobre la marca, para correos con retraso. |
| `--limite` | `100` | Correos por **página**. No es un tope del día: el día se agota paginando. |
| `--reiniciar-uid-validity` | — | Suelta los cursores. Exige `--aplicar`. No borra documentos. |
| `--aplicar` | — | Sin esto no escribe nada. |

### Desenlaces

El comando distingue seis, y el código de salida los acompaña:

| Desenlace | Salida | Significa |
| --- | --- | --- |
| `completa` | 0 | Todos los días del rango, recorridos enteros. |
| `sin_novedades` | 0 | Corrida correcta, ningún correo nuevo. |
| `incompleta` | 0 | Quedaron días sin cerrar. No es un error: la próxima vuelve a ellos. |
| `buzon_inaccesible` | 1 | Red, servidor o carpeta. **Se reintenta.** |
| `autenticacion_fallida` | 1 | Credenciales rechazadas. **Reintentar no sirve**: hay que corregirlas. |
| `uid_validity_cambiado` | 1 | El buzón se reconstruyó. Ver `--reiniciar-uid-validity`. |

---

## 5. Recuperar un período histórico

### Desde la pantalla

Compras → **Recuperar período** → fechas → *Recuperar*. Se encola y el avance aparece en
la franja de estado. Tope de un año por rango (más que eso casi siempre es una fecha mal
tipeada); para más, usar el comando en varios tramos.

### Desde la consola

Es reanudable: si se corta (worker caído, buzón que rechaza, PC apagada), el día queda en
`parcial` con su cursor y la corrida siguiente retoma desde ahí. Repetir el mismo rango
**no duplica nada**.

---

## 6. Backfill de identidad (opcional)

Las filas anteriores a la migración guardan el UID crudo en `gmail_message_id` y no tienen
`identidad`.

```bash
php artisan compras:backfill-identidad            # muestra qué haría
php artisan compras:backfill-identidad --aplicar  # escribe `legado:<uid>`
```

**No es obligatorio.** Sin correrlo el sistema funciona igual: la deduplicación reconoce
esas filas por `gmail_message_id` mientras su `identidad` esté en `NULL`. Correrlo solo
cierra ese camino de compatibilidad y deja la tabla uniforme. No toca ningún otro campo.

---

## 7. Cobertura y el paquete mensual

El paquete de contabilidad ya no cuenta lo que hay y lo entrega: primero pregunta si el
período **se puede verificar**.

- **El período se arma por la fecha FISCAL** (`fecha_dte`, el `fecEmi` del proveedor), no
  por la del correo. Un CCF emitido el 31 de agosto que llega el 2 de septiembre es de
  agosto.
- Las compras marcadas `ignorado` **no entran**.
- Las compras **sin fecha fiscal legible** no entran en ningún período y se listan aparte,
  en Compras y en el resumen del paquete.

| Situación | Descargar | Enviar |
| --- | --- | --- |
| Período cubierto | Sí | Sí |
| Días sin revisar, con error, o sin registro | Sí, con `_INCOMPLETO` en el nombre y el aviso en el `LEEME.txt` | **Bloqueado** |

**Por qué el envío se bloquea y la descarga no.** Enviar hace dos cosas irreversibles a la
vez: manda un correo que sale del sistema y marca las compras como `enviado`. Ese cambio
de estado es el que después hace invisible el hueco —las compras que faltaban ya no
aparecen como pendientes de nadie—, así que un paquete incompleto enviado no se nota hasta
que lo nota la contadora. La descarga marcada cubre el caso legítimo de mandar un avance:
quien lo necesite lo baja y lo envía a mano, con el aviso puesto. Un atajo en el código
agregaría riesgo sin agregar ninguna capacidad que no exista ya.

Un intento bloqueado **queda auditado** (`log_name = paquete_contabilidad`, estado
`bloqueado`) con los días que faltaban.

---

## 8. Diagnóstico

| Síntoma | Dónde mirar |
| --- | --- |
| La franja de Compras está en ámbar y dice «hace N h que no se sincroniza» | El scheduler del servidor no está corriendo (§3). |
| La franja está en rojo | `documentos_recibidos.ultimo_error` en `configuraciones`, o el log. |
| Faltan días en un período | `documentos_recibidos_progreso`, columna `estado`. |
| Un día quedó en `error` | Columna `error` de esa fila; el motivo viene saneado, sin credenciales. |
| Salida de las corridas programadas | `storage/logs/compras-sincronizacion.log` |
| Correos rechazados o descartados | `storage/logs/laravel.log`: `documentos_recibidos.correo_rechazado` / `.correo_descartado` |

---

## 9. Lo que este módulo NUNCA hace

- No borra, no mueve y no marca como leído ningún correo del buzón.
- No abre el buzón en modo escritura, ni siquiera para probar la conexión.
- No toca DTE emitidos, correlativos, firma ni transmisión a Hacienda.
- No guarda credenciales nuevas: usa las del Centro de Configuración
  (`documentos_recibidos.*`), y la contraseña nunca llega a una vista ni a un log.
