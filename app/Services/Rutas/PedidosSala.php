<?php

namespace App\Services\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Facades\Ajustes;
use App\Http\Requests\Rutas\PedidoSalaRequest;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PedidoSalaLinea;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\User;
use App\Support\Dte\OrdenProductosOc;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sacar pedido en la sala (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10; sin salida armada desde el
 * 10/11/2026).
 *
 * Toda la regla está acá: cualquier sala activa, quién lo saca (su ficha de Rutas), una sola
 * vez por sala en el viaje y en el día mientras no esté anulado (aunque la fábrica ya lo haya
 * marcado), que no se corrija después de que la fábrica empezó ni después de sacar el de la
 * sala siguiente ({@see sacoElSiguiente()}), y la auditoría con las líneas.
 * El pedido nuevo se suma solo al viaje del vendedor ({@see Viajes::paraPedido()}). Los datos
 * del formulario llegan ya validados por {@see PedidoSalaRequest}.
 */
class PedidosSala
{
    public function __construct(private readonly Viajes $viajes, private readonly EstimadoPedidos $estimado) {}

    /** Desde cuántas veces lo habitual de la sala una cantidad se avisa como «mucho más». */
    public const VECES_HABITUAL = 3;

    /**
     * «Revisá: Galleta lleva 1,000 y esta sala suele llevar 10.» El 1000 en vez de 10: lo que
     * pasa de {@see self::VECES_HABITUAL} veces lo que la sala suele llevar de ese producto (la
     * mediana de {@see EstimadoPedidos}, con sus pedidos hasta ayer: no se compara consigo mismo). Solo
     * con 3 pedidos o más y en lo que suele pedir. Solo avisa: el pedido ya se guardó (puede
     * ser cierto) y quien lo sacó lo corrige mientras pueda.
     *
     * @return list<string>
     */
    public function avisosCantidad(PedidoSala $pedido): array
    {
        if ($pedido->cliente_sucursal_id === null) {
            return [];
        }
        $estimado = $this->estimado->paraSala($pedido->cliente_sucursal_id, CarbonImmutable::parse(HoraNegocio::fechaHoy())->subDay());
        if ($estimado->confianza() === PedidoEstimado::POCO_DATO) {
            return [];
        }
        $habitual = collect($estimado->lineas)->keyBy('producto_id');

        return $pedido->lineas()->with('producto:id,nombre')->orderBy('id')->get()
            ->map(function (PedidoSalaLinea $linea) use ($habitual) {
                $suele = $habitual->get($linea->producto_id);
                // Contra la mediana, no la última vez: una vez rara no sube el umbral.
                $tope = $suele === null ? null : (float) $suele['cantidad'] * self::VECES_HABITUAL;

                return $tope !== null && (float) $linea->cantidad > $tope
                    ? 'Revisá: '.$linea->producto?->nombre.' lleva '.$linea->cantidadLegible().' y esta sala suele llevar '.PedidoSalaLinea::legible($suele['cantidad']).'.'
                    : null;
            })->filter()->values()->all();
    }

    /** Quien saca el pedido: la ficha de Rutas activa del usuario. */
    public static function persona(User $usuario): ?PersonalRuta
    {
        return PersonalRuta::where('user_id', $usuario->id)->where('activo', true)->first();
    }

    /**
     * Productos de facturación activos, para el formulario, en el orden de Facturación
     * (12/11/2026, pedido del dueño): el de la orden de compra ({@see OrdenProductosOc}), el
     * mismo del editor del CCF y del CCF emitido; lo que no está en ella, al final por nombre.
     */
    public function productos(): Collection
    {
        return Producto::query()->where('activo', true)->get(['id', 'codigo', 'codigo_barra', 'nombre'])
            ->sortBy(fn (Producto $p) => [OrdenProductosOc::rank($p->codigo_barra, $p->nombre), mb_strtoupper($p->nombre)])
            ->values();
    }

    /**
     * Saca el pedido de una sala o lo corrige (`$pedido`). Con la sala bloqueada: otro vendedor
     * puede estar sacando el de la misma sala a la vez. El pedido nuevo va al viaje del
     * vendedor, que se abre o se continúa en la misma transacción: si algo falla, no queda un
     * viaje vacío.
     *
     * Sin `$sala` es una tienda nueva (10/11/2026): lleva solo el nombre que escribió el
     * vendedor (`sala_nueva_nombre`) y la oficina la enlaza al facturar ({@see enlazarSala()}).
     * Sin sala no hay con qué compararla con la de otro vendedor; sí se frena el doble toque:
     * el mismo vendedor, hoy, con el mismo nombre ({@see exigirTiendaSinSacar()}).
     *
     * El permiso y la persona se resuelven ANTES de abrir la transacción, y adentro lo primero
     * son los candados (la persona y después la sala). En MySQL (REPEATABLE READ) la primera
     * lectura sin candado fija la foto de la transacción: si fuera antes de los candados, el
     * que espera la sala no vería el pedido que el otro acaba de guardar y la sala quedaría
     * con dos. El candado de la persona pone en fila los toques del mismo vendedor.
     *
     * @param  array{lineas: list<array{producto_id: int|string, cantidad: string}>, numero_orden_compra?: ?string, notas?: ?string, sala_nueva_nombre?: ?string}  $datos
     */
    public function guardar(?ClienteSucursal $sala, array $datos, User $usuario, ?PedidoSala $pedido = null): PedidoSala
    {
        Gate::forUser($usuario)->authorize('create', PedidoSala::class);
        $personaId = self::persona($usuario)?->id ?? abort(403);
        // Antes de la transacción: adentro, lo primero son los candados.
        $sinPedidos = self::salasSinPedidos();

        return DB::transaction(function () use ($sala, $datos, $usuario, $pedido, $personaId, $sinPedidos) {
            $persona = PersonalRuta::whereKey($personaId)->where('user_id', $usuario->id)->where('activo', true)->lockForUpdate()->first() ?? abort(403);
            if ($sala !== null) {
                $sala = ClienteSucursal::whereKey($sala->id)->where('activo', true)->lockForUpdate()->first() ?? abort(404);
            }

            $campos = [
                'numero_orden_compra' => filled($datos['numero_orden_compra'] ?? null) ? trim($datos['numero_orden_compra']) : null,
                'notas' => filled($datos['notas'] ?? null) ? trim($datos['notas']) : null,
            ];
            if ($sala === null) {
                $nombre = trim((string) ($datos['sala_nueva_nombre'] ?? ''));
                $campos['sala_nueva_nombre'] = $nombre !== '' ? $nombre : throw ValidationException::withMessages(['sala_nueva_nombre' => 'Escribí el nombre de la tienda.']);
            }

            if ($pedido) {
                $pedido = PedidoSala::whereKey($pedido->id)
                    ->when($sala, fn (Builder $q) => $q->where('cliente_sucursal_id', $sala->id), fn (Builder $q) => $q->whereNull('cliente_sucursal_id'))
                    ->lockForUpdate()->firstOrFail();
                $this->exigirSinFabrica($pedido);
                Gate::forUser($usuario)->authorize('corregir', $pedido);
                $pedido->update($campos);
                $pedido->lineas()->delete();
            } else {
                if ($sala !== null && in_array($sala->id, $sinPedidos, true)) {
                    throw ValidationException::withMessages(['lineas' => self::SALA_SIN_PEDIDOS]);
                }
                $sala !== null ? $this->exigirSinSacar($sala, $usuario) : $this->exigirTiendaSinSacar($persona, $campos['sala_nueva_nombre']);
                $viaje = $this->viajes->paraPedido($persona, $sala, $usuario);
                $pedido = $viaje->pedidos()->create($campos + [
                    'cliente_sucursal_id' => $sala?->id,
                    // La zona de la sala, no la del viaje: un viaje cruza zonas vecinas. La
                    // tienda nueva la toma cuando la oficina la enlaza.
                    'ruta_id' => $sala ? $this->viajes->zona($sala)?->id : null,
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

    public const SALA_SIN_PEDIDOS = 'En esta sala no se sacan pedidos.';

    /**
     * Las salas donde no se sacan pedidos (Configuración › Rutas; por ejemplo, la bodega
     * central de un cliente que solo recibe notas de crédito). No salen en «Sacar pedido» y
     * {@see guardar()} no saca uno nuevo en ellas, ni {@see enlazarSala()} les enlaza una
     * tienda nueva. Un pedido que ya tenían se sigue corrigiendo y facturando. Vacío = todas.
     *
     * @return list<int>
     */
    public static function salasSinPedidos(): array
    {
        return array_map('intval', Ajustes::lista('pedidos.salas_sin_pedidos'));
    }

    public const AUDITORIA_ENLACE = 'enlazó la tienda nueva a su sala';

    /**
     * La tienda nueva que se está dando de alta en Clientes (`?pedido_sala=`): solo si sigue
     * vigente y sin sala, y el usuario la puede enlazar. Si no, el alta es una más.
     */
    public function tiendaNuevaPorEnlazar(mixed $id, User $usuario): ?PedidoSala
    {
        $pedido = filter_var($id, FILTER_VALIDATE_INT) !== false
            ? PedidoSala::vigentes()->whereKey((int) $id)->whereNull('cliente_sucursal_id')->first()
            : null;

        return $pedido !== null && $usuario->can('enlazarSala', $pedido) ? $pedido : null;
    }

    /**
     * El nombre de la tienda nueva para compararlo: sin tildes, mayúsculas ni espacios de más.
     * Se compara acá y no en la consulta, para que no dependa de la collation.
     */
    public static function nombreTienda(?string $nombre): string
    {
        return mb_strtolower(Str::ascii((string) preg_replace('/\s+/u', ' ', trim((string) $nombre))));
    }

    /**
     * Los otros pedidos de la misma tienda nueva (13/11/2026): vigentes, todavía sin sala y
     * con el mismo nombre ({@see nombreTienda()}), de cualquier día. Al dar de alta la tienda
     * se enlazan todos ({@see enlazarTiendaNueva()}).
     *
     * @return Collection<int, PedidoSala>
     */
    public static function mismaTiendaNueva(PedidoSala $pedido): Collection
    {
        $nombre = self::nombreTienda($pedido->sala_nueva_nombre);

        return $nombre === '' ? collect() : PedidoSala::vigentes()->whereNull('cliente_sucursal_id')->whereKeyNot($pedido->id)
            ->with('sacadoPor:id,nombre')->orderBy('fecha')->orderBy('id')->get()
            ->filter(fn (PedidoSala $p) => self::nombreTienda($p->sala_nueva_nombre) === $nombre)->values();
    }

    /**
     * Para «Llegó de pedidos»: cuántos pedidos MÁS hay de la misma tienda nueva, por pedido.
     * Una sola consulta para toda la página. Los que ya tienen sala no cuentan.
     *
     * @param  iterable<PedidoSala>  $pedidos
     * @return array<int, int>
     */
    public static function cuantosMasDeLaTienda(iterable $pedidos): array
    {
        $nuevas = collect($pedidos)->filter(fn (PedidoSala $p) => $p->esTiendaNueva());
        if ($nuevas->isEmpty()) {
            return [];
        }
        $porNombre = PedidoSala::vigentes()->whereNull('cliente_sucursal_id')->pluck('sala_nueva_nombre')
            ->countBy(fn (?string $n) => self::nombreTienda($n));

        return $nuevas->mapWithKeys(fn (PedidoSala $p) => [$p->id => max(0, ($porNombre[self::nombreTienda($p->sala_nueva_nombre)] ?? 1) - 1)])->all();
    }

    /**
     * Enlaza la tienda nueva y, con ella, los demás pedidos de esa tienda
     * ({@see mismaTiendaNueva()}): lo que Facturación da de alta es la tienda, no un pedido.
     * El abierto, como siempre ({@see enlazarSala()}): si no se puede, no se enlaza ninguno.
     * Los demás, uno por uno y con las mismas reglas; el que no se puede (por ejemplo, la sala
     * ya tiene otro pedido en su viaje) queda afuera sin frenar a los otros, con el motivo.
     *
     * @return array{pedido: PedidoSala, enlazados: Collection<int, PedidoSala>, afuera: list<string>}
     */
    public function enlazarTiendaNueva(PedidoSala $pedido, int $salaId, User $usuario): array
    {
        $otros = self::mismaTiendaNueva($pedido);
        $nombre = self::nombreTienda($pedido->sala_nueva_nombre);
        $pedido = $this->enlazarSala($pedido, $salaId, $usuario);

        $enlazados = collect();
        $afuera = [];
        foreach ($otros as $otro) {
            try {
                $enlazados->push($this->enlazarSala($otro, $salaId, $usuario, $nombre));
            } catch (ValidationException|AuthorizationException $e) {
                $motivo = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'no tenés permiso para enlazarlo.';
                $afuera[] = 'N.º '.$otro->id.($otro->sacadoPor ? ', de '.$otro->sacadoPor->nombre : '').': '.$motivo;
            }
        }

        return ['pedido' => $pedido, 'enlazados' => $enlazados, 'afuera' => $afuera];
    }

    /**
     * La oficina enlaza la tienda nueva a su sala, ya dada de alta en Clientes (o una que ya
     * existía). Solo una vez, con el pedido vigente y la sala activa. El pedido toma la zona de
     * la sala y conserva el nombre que escribió el vendedor. Recién entonces se puede facturar.
     *
     * Con `$mismaTienda` es otro pedido de esa tienda que se enlaza con ella
     * ({@see enlazarTiendaNueva()}): además, que siga con ese nombre y que la sala no tenga ya
     * otro pedido en su viaje (serían dos pedidos de la sala en el mismo viaje).
     */
    public function enlazarSala(PedidoSala $pedido, int $salaId, User $usuario, ?string $mismaTienda = null): PedidoSala
    {
        // Antes de la transacción, como en guardar(): adentro, lo primero son los candados.
        Gate::forUser($usuario)->authorize('enlazarSala', $pedido);
        $sinPedidos = self::salasSinPedidos();

        return DB::transaction(function () use ($pedido, $salaId, $usuario, $sinPedidos, $mismaTienda) {
            // Primero la sala y después el pedido, en el orden de guardar(): así un vendedor no
            // saca a la vez otro pedido de la sala en el mismo viaje mientras se enlaza.
            $sala = ClienteSucursal::whereKey($salaId)->where('activo', true)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['cliente_sucursal_id' => 'Elegí una sala activa.']);
            $pedido = PedidoSala::lockForUpdate()->findOrFail($pedido->id);
            if (! $pedido->estaVigente()) {
                throw ValidationException::withMessages(['pedido' => 'El pedido está anulado: no se enlaza.']);
            }
            if (! $pedido->esTiendaNueva()) {
                throw ValidationException::withMessages(['pedido' => 'Este pedido ya tiene su sala: «'.$pedido->sala?->nombre.'».']);
            }
            // Una sala donde no se sacan pedidos tampoco recibe uno por la tienda nueva.
            if (in_array($sala->id, $sinPedidos, true)) {
                throw ValidationException::withMessages(['cliente_sucursal_id' => 'En «'.$sala->nombre.'» no se sacan pedidos: elegí otra sala.']);
            }
            if ($mismaTienda !== null) {
                if (self::nombreTienda($pedido->sala_nueva_nombre) !== $mismaTienda) {
                    throw ValidationException::withMessages(['pedido' => 'le cambiaron el nombre de la tienda.']);
                }
                if ($pedido->salida_ruta_id !== null && PedidoSala::vigentes()->where('cliente_sucursal_id', $sala->id)
                    ->where('salida_ruta_id', $pedido->salida_ruta_id)->whereKeyNot($pedido->id)->exists()) {
                    throw ValidationException::withMessages(['pedido' => '«'.$sala->nombre.'» ya tiene otro pedido en ese viaje.']);
                }
            }

            $pedido->update(['cliente_sucursal_id' => $sala->id, 'ruta_id' => $pedido->ruta_id ?? $this->viajes->zona($sala)?->id]);
            activity('pedido_sala')
                ->performedOn($pedido)
                ->causedBy($usuario)
                ->withProperties(['sala_nueva_nombre' => $pedido->sala_nueva_nombre, 'cliente_sucursal_id' => $sala->id, 'cliente_id' => $sala->cliente_id])
                ->log(self::AUDITORIA_ENLACE);

            return $pedido->setRelation('sala', $sala);
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

    /**
     * El doble toque en «Guardar pedido» de una tienda nueva: el mismo vendedor, hoy, con un
     * pedido vigente y todavía sin sala del mismo nombre ({@see nombreTienda()}), no crea
     * otro. Con la persona ya bloqueada.
     */
    private function exigirTiendaSinSacar(PersonalRuta $persona, string $nombre): void
    {
        $repetida = PedidoSala::vigentes()->whereNull('cliente_sucursal_id')->where('sacado_por_id', $persona->id)
            ->whereDate('fecha', HoraNegocio::fechaHoy())->pluck('sala_nueva_nombre')
            ->contains(fn (?string $n) => self::nombreTienda($n) === self::nombreTienda($nombre));
        if ($repetida) {
            throw ValidationException::withMessages(['lineas' => 'El pedido de «'.$nombre.'» ya está sacado. Si cambió algo, corregilo.']);
        }
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

    public const EN_FABRICA = 'Este pedido ya está en despacho; ya no se puede corregir. Avisá a la oficina.';

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

    /**
     * ¿Quien lo sacó ya sacó el pedido de otra sala después? Desde ahí el pedido es de la
     * oficina: el vendedor ya no lo corrige ni lo anula (Melqui, 10/11/2026).
     */
    public static function sacoElSiguiente(PedidoSala $pedido): bool
    {
        return $pedido->sacado_por_id !== null && PedidoSala::vigentes()
            ->where('sacado_por_id', $pedido->sacado_por_id)->where('id', '>', $pedido->id)->exists();
    }

    /**
     * Por qué quien lo sacó ya no puede corregir su pedido, para decírselo en «Tus pedidos de
     * hoy». Null si no hay un motivo que decir (anulado, o todavía se corrige).
     */
    public static function porQueNoSeCorrige(PedidoSala $pedido): ?string
    {
        return match (true) {
            ! $pedido->estaVigente() => null,
            $pedido->fabricaEmpezo() => 'Ya está en despacho: si cambió algo, avisá a la oficina.',
            self::sacoElSiguiente($pedido) => 'Ya sacaste otro pedido: este lo corrige la oficina.',
            default => null,
        };
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
            ->withProperties($pedido->only(['cliente_sucursal_id', 'sala_nueva_nombre', 'salida_ruta_id', 'sacado_por_id', 'fecha', 'estado', 'numero_orden_compra', 'notas'])
                + ['lineas' => $pedido->lineas()->get(['producto_id', 'cantidad'])->toArray()])
            ->log($descripcion);
    }
}
