<?php

namespace App\Jobs;

use App\Services\Contabilidad\CoberturaPaquete;
use App\Services\Contabilidad\EstadoPaquete;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\Contabilidad\PeriodoPaquete;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Arma el ZIP del paquete mensual en segundo plano y lo deja en
 * `storage/app/private/paquetes/{usuario}/` para que la pantalla ofrezca la descarga.
 *
 * Antes se armaba dentro de la petición web: un mes completo regenera un PDF por cada
 * venta y pasaba de los 100 s que Cloudflare espera (error 524), así que el usuario se
 * quedaba con la página cargando y sin archivo.
 *
 * Un solo intento: reintentar un ZIP que falló por un archivo ilegible da el mismo
 * error, y lo que el usuario necesita es ver el motivo en la pantalla. `$timeout` va
 * por encima del `--timeout` del worker; `retry_after` de la conexión tiene que ser
 * mayor que este valor (ver config/queue.php).
 */
class GenerarPaqueteContabilidad implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    /** @param  array{desde: string, hasta: string, etiqueta: string, mes: int, anio: int}  $rango */
    public function __construct(
        public int $usuarioId,
        public array $rango,
        public bool $incluirCompras,
        public bool $incluirVentas,
    ) {}

    public function handle(PaqueteContabilidadZip $zip, PeriodoPaquete $periodo, CoberturaPaquete $cobertura): void
    {
        $compras = $this->incluirCompras ? $periodo->compras($this->rango) : new Collection;
        $ventas = $this->incluirVentas ? $periodo->ventas($this->rango) : new Collection;

        // Sin compras incluidas la cobertura del buzón no dice nada del paquete: un ZIP
        // solo de ventas no puede estar incompleto por correos sin leer.
        $cob = $this->incluirCompras ? $cobertura->para($this->rango['desde'], $this->rango['hasta']) : null;

        $r = $zip->generar($this->rango['etiqueta'], $compras, $ventas, $this->incluirCompras, $this->incluirVentas, $cob);

        $estado = $this->estado();
        self::guardarZip($r['ruta'], $estado->rutaZip());

        $estado->terminar('listo', null, [
            'nombre_descarga' => $zip->nombreArchivo($this->rango['etiqueta'], $r['incompleto']),
            'incompleto' => $r['incompleto'],
            'compras' => $compras->count(),
            'ventas' => $ventas->count(),
            'incidencias' => count($r['incidencias']),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->estado()->terminar('error', 'No se pudo generar el paquete: '.$e->getMessage());
    }

    /** Pasa el ZIP temporal al disco local y borra el temporal. */
    public static function guardarZip(string $temporal, string $destino): void
    {
        $origen = fopen($temporal, 'rb');
        if ($origen === false) {
            throw new RuntimeException('No se pudo leer el ZIP temporal '.$temporal.'.');
        }

        try {
            if (! Storage::disk('local')->writeStream($destino, $origen)) {
                throw new RuntimeException('No se pudo guardar el ZIP en '.$destino.'.');
            }
        } finally {
            if (is_resource($origen)) {
                fclose($origen);
            }
            @unlink($temporal);
        }
    }

    private function estado(): EstadoPaquete
    {
        return EstadoPaquete::para($this->usuarioId, 'zip', $this->rango, $this->incluirCompras, $this->incluirVentas);
    }
}
