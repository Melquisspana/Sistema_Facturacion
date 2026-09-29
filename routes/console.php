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

/*
| Gastos recurrentes. Crea las obligaciones que les tocan a las reglas activas.
|
| ESTE PROCESO CREA DEUDA, y es el único del sistema que lo hace sin que una persona
| apriete nada. De ahí que lleve TRES llaves, y hagan falta las tres:
|   1. `GASTOS_ENABLED=true`               — el módulo existe;
|   2. `GASTOS_RECURRENCIAS_AUTO=true`     — la generación desatendida está permitida;
|   3. algo que ejecute `schedule:run`     — el planificador corre.
| Con dos de tres, no genera. El comando comprueba la segunda ADEMÁS de este `when()`,
| así que una invocación a mano con `--aplicar` tampoco escribe si está apagada.
|
| `--aplicar` no es decorativo: el comando es dry-run por defecto para que una corrida
| manual sea segura, así que la tarea programada tiene que pedir el modo de aplicación
| explícitamente.
|
| UNA VEZ AL DÍA y temprano: una obligación mensual no gana nada con generarse a las
| 03:00 en vez de a las 06:00, pero conviene que exista antes de que alguien abra la
| pantalla a trabajar. No cada cinco minutos: no hay nada que detectar «apenas llega»,
| el calendario ya se sabe de antemano.
|
| Idempotente por índice único (regla + período): si corre dos veces, no duplica. Y si
| el servidor estuvo caído, recupera solo dentro de la ventana configurada; lo anterior
| se informa por salida y espera decisión humana.
|
| La salida se guarda: los períodos que quedaron fuera de la ventana solo sirven si
| alguien puede leerlos después.
*/
Schedule::command('gastos:generar-recurrentes --aplicar')
    ->dailyAt('05:30')
    ->when(fn () => (bool) config('gastos.enabled', false)
        && (bool) config('gastos.recurrencias.generacion_automatica', false))
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/gastos-recurrentes.log'));

/*
| Avisos de gastos: bandeja interna y resúmenes por correo.
|
| Va DESPUÉS de la generación del día, para que un vencimiento generado esta mañana
| pueda avisarse hoy mismo si corresponde.
|
| No manda «todo salió bien» ni un correo por cada movimiento: solo avisa de
| PENDIENTES REALES, y si no hay ninguno no escribe ni envía nada. Una corrida que no
| hace nada es una corrida normal.
|
| Fuera de producción el correo NO sale: queda registrado como simulado por
| App\Support\Correo\CandadoCorreoReal. Encender GASTOS_AVISOS_AUTO en una máquina de
| desarrollo no le escribe a nadie.
|
| Diario aunque la mayoría tenga resumen semanal: la bandeja interna sí es diaria, y
| el resumen semanal se emite únicamente el día de la semana que cada quien eligió.
*/
Schedule::command('gastos:avisos --aplicar')
    ->dailyAt('06:00')
    ->when(fn () => (bool) config('gastos.enabled', false)
        && (bool) config('gastos.avisos.automaticos', false))
    ->withoutOverlapping(20)
    ->appendOutputTo(storage_path('logs/gastos-avisos.log'));

/*
|--------------------------------------------------------------------------
| Cobros Calleja — alta de documentos aceptados
|--------------------------------------------------------------------------
| Incorpora al seguimiento de cobros los CCF/NC que Hacienda ya aceptó.
|
| Es la pieza que hace que el módulo cumpla lo que promete: controlar CADA factura,
| «incluidas las que nunca se presentaron». Mientras el alta dependiera solo del botón
| de la pantalla, la factura olvidada era exactamente la que no entraba —y un agujero
| que nadie ve no se puede reclamar—. El botón sigue estando, como recuperación.
|
| Solo LEE `dtes` ya aceptados y escribe en `cobro_documentos`: no emite, no firma, no
| transmite, no cambia ningún estado fiscal y no bloquea filas que la emisión necesite.
| Una corrida a mitad de una facturación no la estorba.
|
| INTERRUPTOR propio y apagado por defecto, igual que las demás tareas del sistema: la
| definición existe siempre —se puede inspeccionar con `schedule:list` y probar en seco—
| pero no ejecuta nada hasta que se enciende en .env. El comando comprueba la MISMA llave
| cuando recibe `--aplicar`, así que una invocación accidental tampoco escribe.
|
| Cada hora y no cada cinco minutos: una factura que entra al seguimiento sesenta minutos
| más tarde no cambia nada del cobro, y consultar `dtes` doce veces por hora tampoco.
|
| Lleva `--vincular`, pero eso NO enciende la vinculación por sí solo: el comando exige
| además `cobros.vinculacion.automatica` (COBROS_VINCULACION_AUTO), una llave APARTE de la
| del alta. Con esa segunda llave apagada —el valor de fábrica—, esta corrida da de alta los
| CCF/NC aceptados de siempre y audita la vinculación sin escribir ningún vínculo. Se
| enciende a mano cuando `cobros:sincronizar --cliente=ID --vincular` en seco, sobre datos
| reales, respalde ese resultado.
*/
Schedule::command('cobros:sincronizar --aplicar --vincular')
    ->everyTenMinutes() // el albarán se une a su CCF poco después de llegar al correo
    ->when(fn () => (bool) config('cobros.alta.automatica', false))
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/cobros-alta.log'));

/*
|--------------------------------------------------------------------------
| Cobros Calleja — lectura de los acuses y observaciones del cliente
|--------------------------------------------------------------------------
| SOLO LECTURA del buzón: no envía, no responde, no marca como leído y no mueve
| etiquetas. Escribe únicamente en `cobro_correos`, en `cobro_eventos` y en el acuse de
| la solicitud correspondiente.
|
| TRES llaves, y cada una responde algo distinto: que el sistema pueda hablar con Gmail
| (`ppq.gmail.enabled`), que este módulo pueda leer el buzón cuando alguien se lo pide
| (`cobros.correo.enabled`), y que lo haga sin que nadie se lo pida (esta). Juntarlas
| obligaría a encender la automática para poder probar la lectura, que es al revés de
| como hay que hacerlo.
|
| Idempotente: la identidad es el id del mensaje de Gmail, así que releer el buzón no
| crea filas nuevas ni vuelve a aplicar ningún acuse. Y pagina, así que una tanda grande
| no deja el backlog viejo fuera para siempre.
|
| Cada media hora: el acuse de Calleja llega cuando llega y media hora de retraso no
| cambia ninguna decisión de cobro.
*/
Schedule::command('cobros:leer-correos --aplicar')
    ->everyThirtyMinutes()
    ->when(fn () => (bool) config('ppq.gmail.enabled', false)
        && (bool) config('cobros.correo.enabled', false)
        && (bool) config('cobros.correo.automatica', false))
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/cobros-correos.log'));
