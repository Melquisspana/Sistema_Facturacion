<?php

namespace App\Jobs;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Mail\PaqueteContabilidadCorreo;
use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\Contabilidad\AuditoriaPaquete;
use App\Services\Contabilidad\CoberturaPaquete;
use App\Services\Contabilidad\EstadoPaquete;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\Contabilidad\PeriodoPaquete;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Arma el paquete y lo envía a contabilidad en segundo plano. Las guardas (frase
 * exacta, correo válido, documentos en el rango, cobertura) ya se revisaron en la
 * petición; acá se hace lo que antes hacía `PaqueteContabilidadController::enviar()`
 * después de ellas, que con un mes completo pasaba de los 100 s de Cloudflare.
 *
 * Respeta el CANDADO de correo real ({@see CandadoCorreoReal}): fuera de producción no
 * se llama al transporte, se audita como simulado y no se marca nada. NO toca DTE
 * emitidos, correlativos, firmador, transmisión ni el buzón. Si el envío termina
 * EXITOSO, marca como "enviado" solo las compras incluidas que estaban "pendiente".
 *
 * Un solo intento: reintentar solo podría mandar el correo dos veces.
 */
class EnviarPaqueteContabilidad implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    /** @param  array{desde: string, hasta: string, etiqueta: string, mes: int, anio: int}  $rango */
    public function __construct(
        public int $usuarioId,
        public string $correo,
        public array $rango,
        public bool $incluirCompras,
        public bool $incluirVentas,
    ) {}

    public function handle(PaqueteContabilidadZip $zip, PeriodoPaquete $periodo, CoberturaPaquete $cobertura, AuditoriaPaquete $auditoria): void
    {
        $usuario = User::find($this->usuarioId);
        $estado = $this->estado();
        $compras = $this->incluirCompras ? $periodo->compras($this->rango) : new Collection;
        $ventas = $this->incluirVentas ? $periodo->ventas($this->rango) : new Collection;
        $cob = $this->incluirCompras ? $cobertura->para($this->rango['desde'], $this->rango['hasta']) : null;

        $resumen = [
            'compras_cantidad' => $compras->count(),
            'compras_total' => round((float) $compras->sum('total'), 2),
            'ventas_cantidad' => $ventas->count(),
            'ventas_total' => round((float) $ventas->sum('total_pagar'), 2),
            'desde' => $this->rango['desde'],
            'hasta' => $this->rango['hasta'],
            'incluir_compras' => $this->incluirCompras,
            'incluir_ventas' => $this->incluirVentas,
        ];

        $r = $zip->generar($this->rango['etiqueta'], $compras, $ventas, $this->incluirCompras, $this->incluirVentas, $cob);
        $nombreZip = $zip->nombreArchivo($this->rango['etiqueta'], $r['incompleto']);

        // CANDADO de correo real: fuera de producción NO se llama al transporte. Se audita
        // como 'simulado' y NO se marca ninguna compra como enviada. El ZIP se generó
        // igual, así que el ensayo es realista.
        $candado = app(CandadoCorreoReal::class);
        if ($candado->debeSimular()) {
            $auditoria->registrar($usuario, 'simulado', $this->correo, $this->rango, $resumen, $nombreZip, $candado->motivo(), 0);
            @unlink($r['ruta']);
            $estado->terminar('simulado', "Paquete {$this->rango['etiqueta']} NO enviado: ".$candado->motivo()
                .' Se registró como simulado y no se marcó ninguna compra como enviada.');

            return;
        }

        try {
            $bytes = (string) file_get_contents($r['ruta']);

            // Configuración de correo vigente antes de construir el transporte. En la cola
            // también la aplica el listener de JobProcessing; pedirla acá no cuesta nada
            // y cubre el driver sync.
            app(ConfiguracionCorreoRuntime::class)->aplicar();

            Mail::to($this->correo)->send(new PaqueteContabilidadCorreo($this->rango['etiqueta'], $bytes, $nombreZip, $resumen));
        } catch (Throwable $e) {
            // Falla: no cambia estados; el ZIP queda en paquetes/ (se limpia a las 24 h)
            // por si hay que revisarlo; registra auditoría "fallido".
            $auditoria->registrar($usuario, 'fallido', $this->correo, $this->rango, $resumen, $nombreZip, $e->getMessage());
            GenerarPaqueteContabilidad::guardarZip($r['ruta'], $estado->rutaZip());
            $estado->terminar('envio_fallido', 'No se pudo enviar el paquete a contabilidad: '.$e->getMessage().' (no se cambió ningún estado).');

            return;
        }

        // Éxito: marca como "enviado" solo las compras incluidas que estaban "pendiente"
        // (no toca "ignorado" ni las ya "enviado"). Las ventas/DTE no se tocan nunca.
        $marcadas = 0;
        if ($this->incluirCompras && $compras->isNotEmpty()) {
            $marcadas = DocumentoRecibido::whereIn('id', $compras->pluck('id'))
                ->where('estado', 'pendiente')
                ->update(['estado' => 'enviado']);
        }

        $auditoria->registrar($usuario, 'enviado', $this->correo, $this->rango, $resumen, $nombreZip, null, $marcadas);
        @unlink($r['ruta']);

        $estado->terminar('enviado', "Paquete {$this->rango['etiqueta']} enviado a {$this->correo} ({$resumen['compras_cantidad']} compras, {$resumen['ventas_cantidad']} ventas). {$marcadas} compra(s) marcada(s) como enviada(s). Las ventas no se modificaron.");
    }

    public function failed(Throwable $e): void
    {
        $this->estado()->terminar('envio_fallido', 'No se pudo preparar el paquete para enviarlo: '.$e->getMessage().' (no se envió nada ni se cambió ningún estado).');
    }

    private function estado(): EstadoPaquete
    {
        return EstadoPaquete::para($this->usuarioId, 'envio', $this->rango, $this->incluirCompras, $this->incluirVentas);
    }
}
