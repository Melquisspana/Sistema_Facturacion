# Sincronización automática de albaranes (PPQ ← Gmail)

Cómo encender —en este orden, y no en otro— la tarea que importa los albaranes de
Calleja desde Gmail a `ppq_albaranes` cada cinco minutos.

## Las dos llaves, y por qué son dos

| Llave | Qué permite | Por defecto |
|---|---|---|
| `PPQ_GMAIL_ENABLED` | que el sistema pueda **hablar** con Gmail (pantalla de conexión, prueba manual, dry-run) | `false` |
| `PPQ_ALBARANES_AUTO_SYNC` | que además lo haga **solo**, cada 5 minutos, **escribiendo** en `ppq_albaranes` | `false` |

Con una sola llave no habría forma de hacer la prueba manual sin encender a la vez la
automática, que es justo lo que hay que evitar: la prueba existe para decidir si se
enciende.

`PPQ_ALBARANES_AUTO_SYNC` gobierna **dos puertas**:

1. la tarea programada de `routes/console.php` (`->when(...)`);
2. el propio comando cuando recibe `--aplicar`.

Por eso una invocación accidental por fuera del planificador —un `--aplicar` de más,
un `.bat` viejo— tampoco consulta el correo mientras esté apagada. El **dry-run** (sin
`--aplicar`) sigue disponible siempre: no escribe nada y es el paso previo.

> `schedule:list` sigue mostrando la tarea aunque la llave esté apagada. Eso es
> correcto y buscado: la definición existe para poder inspeccionarla y probarla. Lo
> que la llave decide es si **se ejecuta**, no si aparece.

## Procedimiento

### 1. Conectar Gmail

`PPQ_GMAIL_ENABLED=true` en el `.env` del servidor, credenciales OAuth cargadas y
cuenta conectada. Comprobar con el botón «Probar conexión» de la pantalla de
integraciones. Si eso no está verde, lo demás no tiene sentido.

### 2. Probar en seco (sin escribir nada)

```cmd
php artisan ppq:sincronizar-albaranes --dias=3 --limite=10
```
Sin `--aplicar` no escribe: solo enumera lo que haría. Revisar en la salida que los
albaranes que aparecen son los esperados, que las salas se resuelven y que no hay días
truncados. Repetir con una ventana más ancha si hace falta.

**No seguir hasta que esta salida sea la esperada.**

### 3. Primera corrida real, a mano

```cmd
php artisan ppq:sincronizar-albaranes --desde=AAAA-MM-DD --aplicar
```
Requiere `PPQ_ALBARANES_AUTO_SYNC=true` (paso 4); si está apagada, el comando lo dice
y no toca Gmail. Conviene hacerla a mano y con `--desde` para fijar la marca de
progreso sobre el backlog real: si la primera corrida fuera la automática, la marca se
establecería sobre los últimos días y el correo anterior quedaría fuera del barrido
incremental.

### 4. Encender la automática

```
PPQ_ALBARANES_AUTO_SYNC=true
```
Y `php artisan config:clear` si la configuración está cacheada.

A partir de acá la tarea corre **si además** existe algo que ejecute
`php artisan schedule:run` cada minuto en el servidor. Con una sola de las dos cosas,
no corre.

### 5. Comprobar

- `storage/logs/ppq-albaranes.log` — la salida de cada corrida se guarda ahí.
- Contar filas nuevas en `ppq_albaranes` después de 10-15 minutos.

## Apagarla

Poner `PPQ_ALBARANES_AUTO_SYNC=false` y `config:clear`. No hace falta desregistrar
nada: la tarea sigue definida y deja de ejecutarse. Lo ya importado no se toca.

## Qué NO hace, ni encendida

Solo **lee** Gmail y escribe en `ppq_albaranes` (y guarda el PDF del adjunto). No toca
DTE, correlativos, conciliación ni los lotes o items de PPQ. Es idempotente por
`gmail_message_id` y por número + orden de compra, así que repetir una corrida no
duplica nada.
