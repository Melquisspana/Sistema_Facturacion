<?php

namespace App\Jobs;

use App\Console\Commands\ComprasSincronizarCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recupera un período histórico de compras desde el buzón, en segundo plano.
 *
 * POR QUÉ ES UN JOB. Recuperar agosto entero son 31 días, cada uno con una o varias
 * páginas de correos y sus adjuntos: minutos, no segundos. Hacerlo dentro de la petición
 * web daría un timeout del navegador con la recuperación a medias — y aunque el progreso
 * por día la haría reanudable, el usuario no tendría forma de saber qué pasó.
 *
 * NO duplica la lógica: invoca el MISMO comando que corre el scheduler, con un rango
 * explícito. Así hay un solo recorrido que mantener y una sola forma de fallar.
 *
 * `tries = 1`: el comando ya es idempotente y reanudable, pero un reintento automático
 * sobre un buzón caído solo generaría ruido. La recuperación se vuelve a lanzar a mano,
 * y retoma donde quedó.
 */
class RecuperarPeriodoCompras implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Sin límite de tiempo: un período largo tarda, y cortarlo a mitad no aporta nada. */
    public int $timeout = 3600;

    /**
     * Candado de RECUPERACIÓN, distinto del que toma el comando al ejecutarse.
     *
     * Hacen falta los dos y cubren cosas distintas: el del comando impide que dos
     * corridas se pisen el cursor MIENTRAS corren; este impide que se ENCOLEN dos
     * recuperaciones. Sin él, alguien podría apretar «Recuperar» tres veces seguidas y
     * dejar tres trabajos en cola que después se van bloqueando entre sí de a uno.
     */
    public const LOCK = 'compras:recuperacion';

    /** Cuánto vive el candado si el proceso muere de golpe sin soltarlo. */
    public const LOCK_SEGUNDOS = 3600;

    /**
     * @param  string|null  $lockOwner  dueño del candado tomado al encolar, para soltarlo
     *                                  al terminar. Null cuando se invoca sin pasar por
     *                                  la pantalla (una prueba, o una llamada directa).
     */
    public function __construct(
        public readonly string $desde,
        public readonly string $hasta,
        public readonly int $limite = 100,
        public readonly ?string $lockOwner = null,
    ) {}

    public function handle(): void
    {
        try {
            $this->recuperar();
        } finally {
            $this->soltarCandado();
        }
    }

    /** Si el trabajo muere de forma fatal, el candado igual se suelta. */
    public function failed(\Throwable $e): void
    {
        $this->soltarCandado();
    }

    private function soltarCandado(): void
    {
        if ($this->lockOwner !== null) {
            Cache::restoreLock(self::LOCK, $this->lockOwner)->release();
        }
    }

    private function recuperar(): void
    {
        $codigo = Artisan::call(ComprasSincronizarCommand::class, [
            '--desde' => $this->desde,
            '--hasta' => $this->hasta,
            '--limite' => $this->limite,
            // Solape 0: el rango ya es explícito, no hay marca de la que retroceder.
            '--solape' => 0,
            '--aplicar' => true,
        ]);

        // La salida del comando es el único informe de una corrida que nadie miró en
        // vivo. Va al log con su código de salida, para que el resultado no dependa de
        // que alguien tuviera la pantalla abierta.
        Log::info('compras.recuperacion_periodo', [
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'codigo_salida' => $codigo,
            'salida' => trim(Artisan::output()),
        ]);
    }
}
