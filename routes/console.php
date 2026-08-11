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
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/ppq-albaranes.log'));
