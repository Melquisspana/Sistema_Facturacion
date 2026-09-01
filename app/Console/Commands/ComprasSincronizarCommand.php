<?php

namespace App\Console\Commands;

use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\Buzon\IdentidadCorreo;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use App\Services\DocumentosRecibidos\ResumenSincronizacion;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Sincroniza las COMPRAS (documentos recibidos) desde el buzón Yahoo/IMAP.
 *
 * Un solo comando para los dos usos, porque son el mismo recorrido con distinta
 * ventana: sin fechas es la corrida INCREMENTAL que programa el scheduler; con
 * `--desde/--hasta` es la RECUPERACIÓN de un período histórico. Tener dos comandos
 * con la misma mecánica solo garantizaba que uno se arreglara y el otro no.
 *
 * SOLO LECTURA del buzón: no borra, no mueve, no marca leído. Escribe en
 * `documentos_recibidos`, en el disco local (adjuntos) y en la tabla de progreso.
 * DRY-RUN por defecto: sin `--aplicar` no guarda nada.
 *
 * IDEMPOTENTE: repetir el mismo rango deja el mismo resultado. La identidad del correo
 * es su `Message-ID` ({@see IdentidadCorreo}),
 * no el UID, así que el solape y los reintentos no duplican.
 *
 * PROGRESO POR DÍA: un día solo cuenta como cubierto si se recorrió ENTERO. Un día
 * truncado por el límite, cortado a mitad o con error queda sin cerrar, y la corrida
 * siguiente vuelve a él. Es la pieza que evita perder correos: la versión anterior
 * avanzaba la marca por encima de los que el límite había dejado afuera.
 */
class ComprasSincronizarCommand extends Command
{
    protected $signature = 'compras:sincronizar
        {--desde= : Fecha inicial YYYY-MM-DD (recuperación de un período histórico)}
        {--hasta= : Fecha final YYYY-MM-DD (por defecto: hoy)}
        {--dias= : Ventana de N días hacia atrás desde --hasta (alternativa a --desde)}
        {--solape=2 : Días hacia atrás sobre la marca de progreso, para correos con retraso}
        {--limite= : Correos por PÁGINA (por defecto, el configurado; el día se agota paginando)}
        {--reiniciar-uid-validity : Suelta los cursores tras una reconstrucción del buzón}
        {--aplicar : Escribe de verdad (por defecto solo informa lo que haría)}';

    protected $description = 'Sincroniza compras desde el buzón IMAP por días completos y páginas de UID (incremental o recuperación de un período)';

    /** Nombre del bloqueo. Una sola sincronización de compras a la vez, venga de donde venga. */
    public const LOCK = 'compras:sincronizar';

    /** Cuánto puede durar el bloqueo antes de soltarse solo, si el proceso muere de golpe. */
    private const LOCK_SEGUNDOS = 1800;

    public function handle(
        SincronizadorDocumentosRecibidos $sync,
        ProgresoSincronizacionCompras $progreso,
        BitacoraSincronizacionCompras $bitacora,
        ConfiguracionDocumentosRecibidos $configuracion,
    ): int {
        // El bloqueo cubre las dos vías (scheduler y botón de la pantalla). Sin él, dos
        // corridas sobre el mismo día se pisarían el cursor y podrían saltear una página.
        $lock = $this->tomarBloqueo();
        if ($lock === null) {
            $this->error('Ya hay una sincronización de compras en curso. No se arrancó una segunda: se pisarían el progreso.');

            return self::FAILURE;
        }

        try {
            return $this->correr($sync, $progreso, $bitacora, $configuracion);
        } finally {
            $lock->release();
        }
    }

    private function correr(
        SincronizadorDocumentosRecibidos $sync,
        ProgresoSincronizacionCompras $progreso,
        BitacoraSincronizacionCompras $bitacora,
        ConfiguracionDocumentosRecibidos $configuracion,
    ): int {
        $aplicar = (bool) $this->option('aplicar');
        $carpeta = $configuracion->carpeta();

        if ($this->option('reiniciar-uid-validity')) {
            if (! $aplicar) {
                $this->error('--reiniciar-uid-validity toca el progreso guardado: hay que confirmarlo con --aplicar.');

                return self::FAILURE;
            }
            $filas = $progreso->reiniciarPorUidValidity($carpeta, null);
            $this->warn("Se soltaron los cursores de {$filas} día(s) de la carpeta {$carpeta}. Los documentos NO se tocaron: la deduplicación por identidad impide que se dupliquen al releer.");
        }

        [$desde, $hasta] = $this->ventana($progreso, $carpeta);

        if ($desde->gt($hasta)) {
            $this->error('La fecha --desde ('.$desde->toDateString().') es posterior a --hasta ('.$hasta->toDateString().').');

            return self::FAILURE;
        }

        $dias = count($progreso->dias($desde, $hasta));
        $this->info("Ventana: {$desde->toDateString()} → {$hasta->toDateString()} ({$dias} día/s, carpeta {$carpeta}).");

        if ($aplicar) {
            $bitacora->iniciar();
        }

        // El tamaño de página ya no puede hacer perder correos: si el día no entra,
        // se pagina. Es una perilla de rendimiento, no un tope de lo que se lee.
        $pagina = max(1, (int) ($this->option('limite') ?: $configuracion->limite()));

        $r = $sync->sincronizarRango($desde, $hasta, $pagina, $aplicar);

        $this->informar($r);

        if (! $aplicar) {
            $this->warn('DRY-RUN: no se escribió nada (ni documentos ni progreso). Corré con --aplicar para guardar.');

            return $r->fallo() ? self::FAILURE : self::SUCCESS;
        }

        if ($r->fallo()) {
            $bitacora->fallo($r->mensaje(), $r->aArreglo());

            return self::FAILURE;
        }

        // "Incompleta" no es un fallo del buzón: quedaron días por cerrar y la corrida
        // siguiente los toma. Se registra como éxito con días pendientes a la vista.
        $bitacora->exito($r->aArreglo());

        return self::SUCCESS;
    }

    /**
     * Ventana a recorrer. Prioridad: `--desde` explícito > `--dias` > incremental desde
     * la marca de progreso (menos el solape).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function ventana(ProgresoSincronizacionCompras $progreso, string $carpeta): array
    {
        $hasta = ($this->option('hasta') ? Carbon::parse((string) $this->option('hasta')) : Carbon::today())->startOfDay();
        $solape = max(0, (int) $this->option('solape'));

        if ($this->option('desde')) {
            return [Carbon::parse((string) $this->option('desde'))->startOfDay(), $hasta];
        }

        if ($this->option('dias')) {
            return [$hasta->copy()->subDays(max(0, (int) $this->option('dias') - 1))->startOfDay(), $hasta];
        }

        $marca = $progreso->ultimoDiaCompletoContiguo($carpeta);
        if ($marca !== null) {
            $this->line("Último día cubierto por completo: {$marca->toDateString()} (se relee con {$solape} día/s de solape).");
        } else {
            $this->warn('Todavía no hay progreso guardado. Esta corrida NO cubre el histórico anterior: '
                .'para traerlo, usá --desde con la fecha desde la que querés recuperar.');
        }

        $desde = $progreso->inicioIncremental($carpeta, $solape);

        // La ventana nunca se invierte: si la marca quedó adelante de hoy (relojes,
        // fechas raras), se acota en vez de dejar el comando plantado.
        return [$desde->min($hasta), $hasta];
    }

    private function informar(ResumenSincronizacion $r): void
    {
        $this->newLine();

        match (true) {
            $r->desenlace === ResumenSincronizacion::AUTENTICACION_FALLIDA => $this->error('AUTENTICACIÓN FALLIDA: '.$r->error),
            $r->desenlace === ResumenSincronizacion::SIN_CONFIGURAR => $this->error('SIN CONFIGURAR: '.$r->error),
            $r->desenlace === ResumenSincronizacion::BUZON_INACCESIBLE => $this->error('BUZÓN INACCESIBLE: '.$r->error),
            $r->desenlace === ResumenSincronizacion::UID_VALIDITY_CAMBIADO => $this->error('BUZÓN RECONSTRUIDO: '.$r->error),
            default => $this->info($r->etiqueta()),
        };

        if ($r->fallo()) {
            $this->line('Lo procesado hasta acá '.($r->aplicado ? 'quedó guardado' : 'no se guardó (dry-run)')
                .'. Es idempotente: volver a correr no duplica nada.');

            return;
        }

        $this->table(
            ['Correos', 'Nuevos', 'Ya registrados', 'Descartados (no-DTE)', 'Sin DTE legible', 'Días cerrados'],
            [[$r->correos, $r->nuevos, $r->duplicados, $r->descartados, $r->rechazados, count($r->diasCompletos)]],
        );

        if ($r->diasIncompletos !== []) {
            $this->warn('DÍAS SIN CERRAR ('.count($r->diasIncompletos).'): '.implode(', ', $r->diasIncompletos));
            $this->line('No se dan por cubiertos: el límite por página se alcanzó o el día falló. '
                .'La próxima corrida vuelve a ellos; para cerrarlos ahora, repetí el comando.');
        }

        $this->line('El buzón no se modificó: ningún correo leído, movido ni borrado.');
    }

    /** @return Lock|null null si otra corrida ya lo tiene */
    private function tomarBloqueo(): ?Lock
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SEGUNDOS);

        return $lock->get() ? $lock : null;
    }
}
