<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Backups automáticos básicos (locales). Solo se ejecutan si una tarea programada
| corre `php artisan schedule:run` cada minuto. Sin subida a nube todavía.
*/
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');

/*
| Albaranes de Calleja desde Gmail. SOLO LECTURA de Gmail; escribe únicamente en
| `ppq_albaranes`. No toca DTE, correlativos, conciliación ni los lotes de PPQ.
|
| `--aplicar` es obligatorio acá: sin él la corrida sería un dry-run que no guarda nada.
| Es idempotente (identidad número + OC), así que repetirla no duplica.
|
| Cada 5 minutos: el requisito es detectar los albaranes casi apenas llegan. La ventana va
| de la marca de progreso hasta hoy. Si el servidor estuvo apagado varios días, se ensancha
| sola hasta ponerse al día.
|
| `--solape=1` (en vez del 3 por defecto) por el costo a esta frecuencia:
| `albaranesDeFecha()` baja y parsea el PDF de CADA correo de la ventana antes de que el
| Command pueda saltarse los ya sincronizados, así que cada corrida repite ese trabajo sobre
| todo el solape. Con 1 día alcanza: la marca de progreso ya protege contra pérdida (el
| solape amplio hacía falta cuando el ancla salía de la fecha parseada del PDF, y ya no).
|
| El lock se acota a 10 minutos: con corridas cada 5, el lock de 24 h por defecto podría
| frenar la sincronización un día entero si un proceso muere de forma abrupta.
|
| PRIMERA corrida: conviene hacerla a mano con `--desde` para fijar la marca sobre el
| backlog real. Si la primera es esta, la marca se establece sobre los últimos días y el
| correo anterior queda fuera del barrido incremental (el comando lo avisa por salida).
|
| La salida se guarda: los avisos que importan (ventana truncada, salas desconocidas,
| albaranes dados de baja) solo sirven si alguien puede leerlos después.
*/
Schedule::command('ppq:sincronizar-albaranes --aplicar --solape=1')
    ->everyFiveMinutes()
    // INTERRUPTOR. Igual que en Compras: `when()` se evalúa cuando el planificador decide
    // si corre, no al registrar la tarea, así que la definición SIEMPRE existe —se puede
    // inspeccionar con `schedule:list` y probar— pero no ejecuta nada hasta que se
    // enciende en .env. Apagado por defecto.
    //
    // Sin esto, esta tarea era la ÚNICA de las cuatro sin llave propia: el día que el
    // servidor registrara `schedule:run`, PPQ habría empezado a consultar Gmail cada
    // cinco minutos y a escribir en `ppq_albaranes` sin que nadie lo decidiera. Instalar
    // el planificador no puede encender un módulo de rebote.
    //
    // El comando comprueba la MISMA llave cuando recibe `--aplicar`, así que una
    // invocación accidental por fuera del planificador tampoco consulta el correo.
    ->when(fn () => (bool) config('ppq.albaranes.sincronizacion_automatica', false))
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/ppq-albaranes.log'));

/*
| Compras (documentos recibidos) desde el buzón Yahoo/IMAP. SOLO LECTURA del buzón; no
| borra, no mueve, no marca leído. Escribe únicamente en `documentos_recibidos`, en los
| adjuntos del disco local y en `documentos_recibidos_progreso`.
|
| `--aplicar` es obligatorio acá: sin él la corrida sería un dry-run que no guarda nada.
| Es idempotente (identidad por Message-ID), así que repetirla no duplica.
|
| Cada 15 minutos: un CCF de proveedor no es urgente al minuto, pero sí tiene que estar
| antes de que alguien arme el paquete del mes sin saber que faltaba. A esta frecuencia,
| la ventana incremental (marca de progreso menos el solape) es de pocos días y cada
| corrida cuesta una conexión y unas pocas búsquedas por día.
|
| `--solape=2` recupera los correos que llegan fechados el día anterior y absorbe los
| desfases de zona horaria del encabezado Date. Releer un día ya cubierto es barato: el
| cursor por UID hace que la relectura empiece donde terminó la anterior.
|
| El lock se acota a 20 minutos, por encima de lo que tarda una corrida incremental y por
| debajo del intervalo acumulado, para que un proceso muerto de golpe no frene la
| sincronización durante horas. El comando toma ADEMÁS su propio bloqueo (Cache::lock),
| que cubre también al botón de la pantalla —cosa que `withoutOverlapping` no ve—.
|
| PRIMERA corrida: conviene hacerla a mano con `--desde` para recuperar el backlog. Si la
| primera es esta, la marca se establece sobre los últimos días y lo anterior queda fuera
| del barrido incremental (el comando lo avisa por salida).
|
| La salida se guarda: los avisos que importan (días sin cerrar, buzón inaccesible,
| autenticación fallida) solo sirven si alguien puede leerlos después.
|
| DOS LLAVES, las dos necesarias:
|   1. `DOCUMENTOS_RECIBIDOS_AUTO_SYNC=true` en .env (apagado por defecto);
|   2. algo que ejecute `php artisan schedule:run` cada minuto en el servidor.
| Con una sola, no corre. Ver docs/SINCRONIZACION_COMPRAS.md §3.
|
| `--aplicar` NO es decorativo: el comando es dry-run por defecto para que una corrida
| manual sea segura, así que la tarea programada tiene que pedir el modo de aplicación
| explícitamente. Sin él la automática leería el buzón y no guardaría nada — el peor
| resultado posible, porque parecería estar funcionando. Hay una prueba que falla si
| este flag desaparece.
*/
Schedule::command('compras:sincronizar --aplicar --solape=2')
    ->everyFifteenMinutes()
    // INTERRUPTOR. `when()` se evalúa cuando el scheduler decide si corre, no al
    // registrar la tarea: así la definición SIEMPRE existe (y se puede inspeccionar y
    // probar) pero no ejecuta nada hasta que se enciende en .env. Apagado por defecto.
    ->when(fn () => (bool) config('documentos_recibidos.sincronizacion_automatica', false))
    ->withoutOverlapping(20)
    ->appendOutputTo(storage_path('logs/compras-sincronizacion.log'));
