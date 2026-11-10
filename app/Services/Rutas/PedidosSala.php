<?php

namespace App\Services\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Http\Requests\Rutas\PedidoSalaRequest;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Support\HoraNegocio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sacar pedido en la sala desde «Mi salida» (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10).
 *
 * Toda la regla está acá: en qué salas de la salida se puede sacar, quién lo saca, una sola
 * vez por sala mientras no esté anulado (aunque la fábrica ya lo haya marcado), que no se corrija
 * después de que la fábrica empezó, y la auditoría con las líneas. Los datos del formulario llegan
 * ya validados por {@see PedidoSalaRequest}.
 */
class PedidosSala
{
    /**
     * Las salas de la salida: las activas de su ruta y las que llevan un CCF en ella.
     *
     * @return Builder<ClienteSucursal>
     */
    public function salasDeSalida(SalidaRuta $salida): Builder
    {
        return ClienteSucursal::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('ruta_id', $salida->ruta_id)->where('activo', true))
            ->orWhereIn('id', $salida->entregas()->select('cliente_sucursal_id')));
    }

    /** Productos de facturación activos, para el formulario. */
    public function productos(): Collection
    {
        return Producto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']);
    }

    /**
     * Saca el pedido de una sala o lo corrige (`$pedido`). Con la salida bloqueada: el
     * compañero puede estar sacando el de la misma sala a la vez.
     *
     * @param  array{lineas: list<array{producto_id: int|string, cantidad: string}>, numero_orden_compra?: ?string, notas?: ?string}  $datos
     */
    public function guardar(SalidaRuta $salida, ClienteSucursal $sala, array $datos, User $usuario, ?PedidoSala $pedido = null): PedidoSala
    {
        return DB::transaction(function () use ($salida, $sala, $datos, $usuario, $pedido) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            Gate::forUser($usuario)->authorize('sacarPedido', $salida);
            abort_unless($this->salasDeSalida($salida)->whereKey($sala->id)->exists(), 404);

            if ($pedido) {
                $pedido = $salida->pedidos()->lockForUpdate()->findOrFail($pedido->id);
                $this->exigirSinFabrica($pedido);
                Gate::forUser($usuario)->authorize('corregir', $pedido);
            } elseif ($salida->pedidos()->vigentes()->where('cliente_sucursal_id', $sala->id)->exists()) {
                throw ValidationException::withMessages(['lineas' => 'El pedido de «'.$sala->nombre.'» ya está sacado. Si cambió algo, corregilo.']);
            }

            $campos = [
                'numero_orden_compra' => filled($datos['numero_orden_compra'] ?? null) ? trim($datos['numero_orden_compra']) : null,
                'notas' => filled($datos['notas'] ?? null) ? trim($datos['notas']) : null,
            ];

            if ($pedido) {
                $pedido->update($campos);
                $pedido->lineas()->delete();
            } else {
                $pedido = $salida->pedidos()->create($campos + [
                    'cliente_sucursal_id' => $sala->id,
                    'ruta_id' => $salida->ruta_id,
                    // Quien lo saca es quien lo guarda: su ficha de campo en esta salida.
                    'sacado_por_id' => $salida->personal()->where('user_id', $usuario->id)->where('activo', true)->firstOrFail()->id,
                    'registrado_por' => $usuario->id,
                    'fecha' => HoraNegocio::fechaHoy(),
                    'estado' => EstadoPedidoSala::Registrado,
                ]);
            }
            $pedido->lineas()->createMany(array_map(fn (array $l) => [
                'producto_id' => (int) $l['producto_id'],
                'cantidad' => $l['cantidad'],
            ], $datos['lineas']));

            $this->auditar($pedido, $usuario, $pedido->wasRecentlyCreated ? 'sacó el pedido de la sala' : 'corrigió el pedido de la sala');

            return $pedido;
        });
    }

    /** Anula el pedido. Se conserva con sus líneas, para la oficina. */
    public function anular(SalidaRuta $salida, PedidoSala $pedido, User $usuario): void
    {
        DB::transaction(function () use ($salida, $pedido, $usuario) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $pedido = $salida->pedidos()->lockForUpdate()->findOrFail($pedido->id);
            $this->exigirSinFabrica($pedido);
            Gate::forUser($usuario)->authorize('corregir', $pedido);
            $pedido->update(['estado' => EstadoPedidoSala::Anulado]);
            $this->auditar($pedido, $usuario, 'anuló el pedido de la sala');
        });
    }

    /**
     * La OC desde la oficina. Casi nunca se sabe en la sala: la sala la hace después y llega
     * por el portal EDI. Su forma solo avisa (#169); vacía, se quita.
     */
    public function cambiarOrdenCompra(PedidoSala $pedido, ?string $orden, User $usuario): PedidoSala
    {
        return DB::transaction(function () use ($pedido, $orden, $usuario) {
            $pedido = PedidoSala::lockForUpdate()->findOrFail($pedido->id);
            Gate::forUser($usuario)->authorize('cambiarOrdenCompra', $pedido);
            $anterior = $pedido->numero_orden_compra;
            $pedido->update(['numero_orden_compra' => filled($orden) ? trim($orden) : null]);
            activity('pedido_sala')
                ->performedOn($pedido)
                ->causedBy($usuario)
                ->withProperties(['anterior' => $anterior, 'nueva' => $pedido->numero_orden_compra])
                ->log('cambió la orden de compra del pedido');

            return $pedido;
        });
    }

    public const EN_FABRICA = 'La fábrica ya marcó este pedido; ya no se puede corregir. Avisá a la oficina.';

    /**
     * Freno del vendedor: si la fábrica ya empezó (algún envío, aunque esté anulado, un
     * estado suyo o el armado empezado), ni se corrige ni se anula. El estado y el armado
     * se leen de la fila YA BLOQUEADA; los envíos, con una lectura con bloqueo, que ve lo
     * último confirmado aunque la vista de lectura ya se haya abierto. Así no llega al 500
     * de la FK restrict de los envíos al borrar las líneas.
     */
    private function exigirSinFabrica(PedidoSala $pedido): void
    {
        if (in_array($pedido->estado, [EstadoPedidoSala::Parcial, EstadoPedidoSala::Enviado, EstadoPedidoSala::NoSeTrae], true)
            || $pedido->armando_desde !== null
            || $pedido->envios()->sharedLock()->exists()) {
            throw ValidationException::withMessages(['lineas' => self::EN_FABRICA]);
        }
    }

    /** ¿El usuario es quien sacó el pedido? Su ficha de campo activa. */
    public static function loSaco(User $usuario, PedidoSala $pedido): bool
    {
        return $pedido->sacado_por_id !== null && PersonalRuta::query()
            ->whereKey($pedido->sacado_por_id)->where('user_id', $usuario->id)->where('activo', true)->exists();
    }

    private function auditar(PedidoSala $pedido, User $usuario, string $descripcion): void
    {
        activity('pedido_sala')
            ->performedOn($pedido)
            ->causedBy($usuario)
            ->withProperties($pedido->only(['cliente_sucursal_id', 'salida_ruta_id', 'sacado_por_id', 'fecha', 'estado', 'numero_orden_compra', 'notas'])
                + ['lineas' => $pedido->lineas()->get(['producto_id', 'cantidad'])->toArray()])
            ->log($descripcion);
    }
}
