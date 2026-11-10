<?php

namespace App\Services\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Http\Requests\Rutas\PedidoSalaRequest;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\User;
use App\Support\HoraNegocio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sacar pedido en la sala (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10; sin salida armada desde el
 * 10/11/2026).
 *
 * Toda la regla está acá: cualquier sala activa, quién lo saca (su ficha de Rutas), una sola
 * vez por sala en el viaje y en el día mientras no esté anulado (aunque la fábrica ya lo haya
 * marcado), que no se corrija después de que la fábrica empezó, y la auditoría con las líneas.
 * El pedido nuevo se suma solo al viaje del vendedor ({@see Viajes::paraPedido()}). Los datos
 * del formulario llegan ya validados por {@see PedidoSalaRequest}.
 */
class PedidosSala
{
    public function __construct(private readonly Viajes $viajes) {}

    /** Quien saca el pedido: la ficha de Rutas activa del usuario. */
    public static function persona(User $usuario): ?PersonalRuta
    {
        return PersonalRuta::where('user_id', $usuario->id)->where('activo', true)->first();
    }

    /** Productos de facturación activos, para el formulario. */
    public function productos(): Collection
    {
        return Producto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']);
    }

    /**
     * Saca el pedido de una sala o lo corrige (`$pedido`). Con la sala bloqueada: otro vendedor
     * puede estar sacando el de la misma sala a la vez. El pedido nuevo va al viaje del
     * vendedor, que se abre o se continúa en la misma transacción: si algo falla, no queda un
     * viaje vacío.
     *
     * @param  array{lineas: list<array{producto_id: int|string, cantidad: string}>, numero_orden_compra?: ?string, notas?: ?string}  $datos
     */
    public function guardar(ClienteSucursal $sala, array $datos, User $usuario, ?PedidoSala $pedido = null): PedidoSala
    {
        return DB::transaction(function () use ($sala, $datos, $usuario, $pedido) {
            Gate::forUser($usuario)->authorize('create', PedidoSala::class);
            $sala = ClienteSucursal::whereKey($sala->id)->where('activo', true)->lockForUpdate()->first() ?? abort(404);

            $campos = [
                'numero_orden_compra' => filled($datos['numero_orden_compra'] ?? null) ? trim($datos['numero_orden_compra']) : null,
                'notas' => filled($datos['notas'] ?? null) ? trim($datos['notas']) : null,
            ];

            if ($pedido) {
                $pedido = PedidoSala::whereKey($pedido->id)->where('cliente_sucursal_id', $sala->id)->lockForUpdate()->firstOrFail();
                $this->exigirSinFabrica($pedido);
                Gate::forUser($usuario)->authorize('corregir', $pedido);
                $pedido->update($campos);
                $pedido->lineas()->delete();
            } else {
                $persona = self::persona($usuario);
                $this->exigirSinSacar($sala, $usuario);
                $viaje = $this->viajes->paraPedido($persona, $sala, $usuario);
                $pedido = $viaje->pedidos()->create($campos + [
                    'cliente_sucursal_id' => $sala->id,
                    // La zona de la sala, no la del viaje: un viaje cruza zonas vecinas.
                    'ruta_id' => $this->viajes->zona($sala)?->id,
                    'sacado_por_id' => $persona->id,
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

    /**
     * El pedido vigente que ya tiene la sala y frena otro: el de hoy, lo haya sacado quien sea
     * (dos vendedores en la misma sala mandarían dos pedidos a la fábrica), o el del viaje del
     * vendedor que todavía suma pedidos.
     */
    public function yaSacado(ClienteSucursal $sala, User $usuario): ?PedidoSala
    {
        $persona = self::persona($usuario);
        $viaje = $persona ? $this->viajes->abierto($persona) : null;
        $viajeId = $viaje !== null && $this->viajes->sigueSumando($viaje) ? $viaje->id : null;

        return PedidoSala::vigentes()->where('cliente_sucursal_id', $sala->id)
            ->where(fn (Builder $q) => $q->whereDate('fecha', HoraNegocio::fechaHoy())
                ->when($viajeId, fn (Builder $q) => $q->orWhere('salida_ruta_id', $viajeId)))
            ->with('sacadoPor:id,nombre,user_id')->latest('id')->first();
    }

    private function exigirSinSacar(ClienteSucursal $sala, User $usuario): void
    {
        $sacado = $this->yaSacado($sala, $usuario);
        if ($sacado === null) {
            return;
        }

        throw ValidationException::withMessages(['lineas' => self::loSaco($usuario, $sacado)
            ? 'El pedido de «'.$sala->nombre.'» ya está sacado. Si cambió algo, corregilo.'
            : 'El pedido de «'.$sala->nombre.'» ya lo sacó hoy '.($sacado->sacadoPor?->nombre ?? 'otro vendedor').'.']);
    }

    /** Anula el pedido. Se conserva con sus líneas, para la oficina. */
    public function anular(PedidoSala $pedido, User $usuario): void
    {
        DB::transaction(function () use ($pedido, $usuario) {
            $pedido = PedidoSala::lockForUpdate()->findOrFail($pedido->id);
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
