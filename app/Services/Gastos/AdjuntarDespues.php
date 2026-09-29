<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adjuntar el papel que llegó tarde.
 *
 * Es un caso normal, no una excepción: el proveedor manda el recibo días después,
 * o el comprobante del banco se descarga al día siguiente. Por eso «falta
 * comprobante» se registra con su motivo y se puede resolver más tarde sin tocar
 * el pago ni la deuda.
 *
 * Los candados son los mismos que al registrar: el documento hereda el permiso
 * del gasto y el comprobante el del pago COMPLETO —quien no alcanza todos los
 * ámbitos de un pago mixto tampoco puede añadirle comprobantes, porque después
 * podría descargarlos—.
 */
final class AdjuntarDespues
{
    public function __construct(private AccesoGastos $acceso, private AlmacenAdjuntos $almacen) {}

    /**
     * Documentos de cobro de un gasto. Al adjuntar el primero, un gasto marcado
     * «lo adjuntaré después» pasa a «adjunto»; uno marcado «no entregaron documento»
     * NO se toca: si aparece un papel, quien lo sube decide qué significa.
     *
     * @param  array<int, UploadedFile>  $archivos
     */
    public function documentos(User $usuario, Gasto $gasto, array $archivos): int
    {
        abort_unless($usuario->activo && $usuario->can('gastos.registrar'), 403);
        abort_unless($this->acceso->ver($usuario, $gasto), 403);

        $this->comprobarTope($archivos, $this->almacen->cuantos($gasto->id, null), 'documentos');

        $rutas = [];

        try {
            return DB::transaction(function () use ($usuario, $gasto, $archivos, &$rutas) {
                $ids = $this->almacen->guardar($archivos, $gasto->id, null, $usuario->id, $rutas, 'documentos');

                if ($gasto->documentacion === 'pendiente') {
                    $gasto->update(['documentacion' => 'adjunto']);
                }

                $this->registrarEvento($gasto->id, null, $usuario->id, 'documentos_adjuntados', count($ids));

                return count($ids);
            });
        } catch (\Throwable $e) {
            $this->almacen->limpiar($rutas);
            throw $e;
        }
    }

    /**
     * Comprobantes de un pago. Al adjuntar el primero se limpia el motivo de
     * «falta comprobante»: ya no falta. El motivo queda en el historial, así que no
     * se pierde por qué faltaba.
     *
     * @param  array<int, UploadedFile>  $archivos
     */
    public function comprobantes(User $usuario, Pago $pago, array $archivos): int
    {
        abort_unless($usuario->activo && $usuario->can('gastos.pagos.registrar'), 403);
        abort_unless($this->acceso->pagoCompleto($usuario, $pago), 403);

        if (! $pago->vigente()) {
            throw ValidationException::withMessages([
                'comprobantes' => 'Este pago está revertido. Adjuntá el comprobante al pago que lo sustituye.',
            ]);
        }

        $this->comprobarTope($archivos, $this->almacen->cuantos(null, $pago->id), 'comprobantes');

        $rutas = [];

        try {
            return DB::transaction(function () use ($usuario, $pago, $archivos, &$rutas) {
                $ids = $this->almacen->guardar($archivos, null, $pago->id, $usuario->id, $rutas, 'comprobantes');

                $motivoPrevio = $pago->sin_comprobante;
                if ($motivoPrevio !== null) {
                    $pago->update(['sin_comprobante' => null]);
                }

                $this->registrarEvento(null, $pago->id, $usuario->id, 'comprobantes_adjuntados', count($ids), [
                    'motivo_previo' => $motivoPrevio,
                ]);

                return count($ids);
            });
        } catch (\Throwable $e) {
            $this->almacen->limpiar($rutas);
            throw $e;
        }
    }

    /** @param  array<int, UploadedFile>  $archivos */
    private function comprobarTope(array $archivos, int $yaTiene, string $campo): void
    {
        $tope = (int) config('gastos.max_archivos');

        if ($yaTiene + count($archivos) > $tope) {
            throw ValidationException::withMessages([
                $campo => "Ya hay {$yaTiene} archivo(s) y el tope es {$tope}. Quitá alguno antes de subir más.",
            ]);
        }
    }

    private function registrarEvento(?int $gastoId, ?int $pagoId, int $usuarioId, string $accion, int $cuantos, array $extra = []): void
    {
        DB::table('gastos_eventos')->insert([
            'gasto_id' => $gastoId,
            'pago_id' => $pagoId,
            'usuario_id' => $usuarioId,
            'accion' => $accion,
            'datos' => json_encode(['cantidad' => $cuantos] + $extra, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
