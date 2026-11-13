<?php

namespace App\Services\Rutas;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PedidoSalaEnvioLinea;
use App\Models\PedidoSalaLinea;
use App\Models\User;
use App\Policies\PedidoSalaPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * La oficina corrige o anula un pedido de sala (Melqui, 13/11/2026). Quién, lo dice
 * {@see PedidoSalaPolicy::corregirEnOficina()}; acá está la regla:
 *
 *  - Corregir: la sala (si el vendedor se equivocó; la nueva pasa las reglas de sacar pedido)
 *    y las cantidades mientras el pedido siga por mandar. Lo ya mandado no se toca y ninguna
 *    cantidad baja de lo que ya salió; en 0, sale del pedido lo que no se mandó. Dejar cada producto en lo mandado es «lo que falta no
 *    se trae»: el pedido queda mandado.
 *  - Anular: solo sin envíos vigentes. Con algo ya mandado, se corrige lo que falta.
 *  - Nunca si ya se facturó (un CCF de alguno de sus envíos): va por nota de crédito.
 *  - Siempre con motivo, y la auditoría guarda el antes y el después.
 *
 * CANDADOS, como {@see PedidosSala} y {@see PedidosFabrica}: la sala nueva primero (como al
 * sacar pedido: sala → pedido) y después pedido → líneas → envíos. La {@see version()}
 * del formulario frena una corrección hecha sobre lo que la PC de pedidos ya cambió, y al
 * cambiar las cantidades cambia la versión de la PC de pedidos: su «Mandar» con la vieja no
 * pasa. Las pruebas corren en SQLite: los candados los confirma la CI con MySQL.
 */
class PedidosOficina
{
    public const FACTURADO = 'Ya se facturó: va por nota de crédito.';

    public const EN_BORRADOR = 'Este pedido ya tiene un CCF en borrador. Eliminá el borrador antes de corregirlo.';

    public const CON_ENVIO = 'Ya salió una parte de este pedido: no se anula. Para que no se traiga lo que falta, tocá «Corregir» y dejá cada producto en lo que ya se mandó.';

    public const TODO_EN_CERO = 'Así el pedido queda sin nada. Para eso tocá «Anular» y escribí el motivo.';

    public const AUDITORIA_CORRIGE = 'la oficina corrigió el pedido';

    public const AUDITORIA_ANULA = 'la oficina anuló el pedido';

    public function __construct(private readonly Viajes $viajes) {}

    /**
     * La versión del formulario: la misma de la PC de pedidos (estado, sala, líneas y envíos
     * vigentes, {@see PedidoSala::versionProduccion()}). Con `lineas` y `envios` cargados.
     */
    public static function version(PedidoSala $pedido): string
    {
        return $pedido->versionProduccion();
    }

    /**
     * @param  array{version: string, motivo: string, cliente_sucursal_id?: int|string|null, cantidades?: array<int|string, string>}  $datos
     */
    public function corregir(PedidoSala $pedido, array $datos, User $usuario): PedidoSala
    {
        Gate::forUser($usuario)->authorize('corregirEnOficina', $pedido);
        // Antes de la transacción: adentro, lo primero son los candados.
        $sinPedidos = PedidosSala::salasSinPedidos();
        $salaId = filled($datos['cliente_sucursal_id'] ?? null) ? (int) $datos['cliente_sucursal_id'] : null;

        return DB::transaction(function () use ($pedido, $datos, $usuario, $sinPedidos, $salaId) {
            $salaNueva = null;
            if ($salaId !== null && $salaId !== $pedido->cliente_sucursal_id) {
                $salaNueva = ClienteSucursal::whereKey($salaId)->where('activo', true)->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(['cliente_sucursal_id' => 'Elegí una sala activa.']);
            }
            $pedido = $this->bloquear($pedido, $usuario, $datos['version']);
            $antes = $this->foto($pedido);

            if ($salaNueva !== null && $salaNueva->id !== $pedido->cliente_sucursal_id) {
                $this->exigirSalaParaPedido($pedido, $salaNueva, $sinPedidos);
                $pedido->update(['cliente_sucursal_id' => $salaNueva->id, 'ruta_id' => $this->viajes->zona($salaNueva)?->id]);
            }
            $quitadas = $this->corregirCantidades($pedido, $datos['cantidades'] ?? []);

            $pedido->refresh()->load(['sala:id,nombre', 'lineas' => fn ($q) => $q->orderBy('id'), 'lineas.producto:id,nombre']);
            $despues = $this->foto($pedido);
            // Lo que se quitó también figura en el después, en 0.
            $despues['lineas'] = [...$despues['lineas'], ...$quitadas];
            if ($antes === $despues) {
                throw ValidationException::withMessages(['pedido' => 'No cambiaste nada: elegí otra sala o cambiá alguna cantidad.']);
            }

            activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                ->withProperties(['motivo' => trim($datos['motivo']), 'antes' => $antes, 'despues' => $despues])
                ->log(self::AUDITORIA_CORRIGE);

            return $pedido;
        }, 3);
    }

    /** Anula el pedido sin envíos vigentes. Se conserva con sus líneas. */
    public function anular(PedidoSala $pedido, string $version, string $motivo, User $usuario): void
    {
        Gate::forUser($usuario)->authorize('corregirEnOficina', $pedido);

        DB::transaction(function () use ($pedido, $version, $motivo, $usuario) {
            $pedido = $this->bloquear($pedido, $usuario, $version);
            if ($pedido->envios->whereNull('anulado_en')->isNotEmpty()) {
                throw ValidationException::withMessages(['pedido' => self::CON_ENVIO]);
            }

            $anterior = $pedido->estado;
            $pedido->update(['estado' => EstadoPedidoSala::Anulado, 'armando_desde' => null, 'armando_por' => null, 'armando_sigue' => false]);
            activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                ->withProperties(['motivo' => trim($motivo), 'estado_anterior' => $anterior->value] + $this->foto($pedido))
                ->log(self::AUDITORIA_ANULA);
        }, 3);
    }

    /**
     * ¿Ya se facturó? Un CCF (también en borrador) en alguno de sus envíos vigentes. Con
     * `envios.dte` cargados. Null si no; si sí, el mensaje que lo explica.
     */
    public static function porQueNoSeCorrige(PedidoSala $pedido): ?string
    {
        $dtes = $pedido->envios->whereNull('anulado_en')
            ->filter(fn (PedidoSalaEnvio $e) => $e->estaFacturado())->map(fn (PedidoSalaEnvio $e) => $e->dte);

        return match (true) {
            $dtes->contains(fn ($d) => $d->estado !== EstadoDte::Borrador) => self::FACTURADO,
            $dtes->isNotEmpty() => self::EN_BORRADOR,
            default => null,
        };
    }

    /**
     * El pedido bloqueado con sus líneas y envíos (pedido → líneas → envíos, el orden de
     * {@see PedidosFabrica}), y lo que frena antes de cambiar nada: el permiso con la fila
     * fresca, anulado, facturado y la versión vieja.
     */
    private function bloquear(PedidoSala $pedido, User $usuario, string $version): PedidoSala
    {
        $pedido = PedidoSala::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
        $pedido->setRelation('lineas', $pedido->lineas()->orderBy('id')->lockForUpdate()->with(['producto:id,nombre', 'envioLineasVigentes'])->get());
        $pedido->setRelation('envios', $pedido->envios()->orderBy('id')->lockForUpdate()->with('dte')->get());
        $pedido->load('sala:id,nombre');

        if (! $pedido->estaVigente()) {
            throw ValidationException::withMessages(['pedido' => 'Este pedido ya está anulado.']);
        }
        Gate::forUser($usuario)->authorize('corregirEnOficina', $pedido);
        if (($motivo = self::porQueNoSeCorrige($pedido)) !== null) {
            throw ValidationException::withMessages(['pedido' => $motivo]);
        }
        if (! hash_equals(self::version($pedido), $version)) {
            throw ValidationException::withMessages(['pedido' => PedidosFabrica::CAMBIO]);
        }

        return $pedido;
    }

    /**
     * Las mismas reglas que al sacar pedido ({@see PedidosSala::guardar()}): activa (ya
     * bloqueada), no en las salas donde no se sacan pedidos y sin otro pedido vigente de ese
     * día o de ese viaje. La tienda nueva se enlaza con «Dar de alta la tienda».
     *
     * @param  list<int>  $sinPedidos
     */
    private function exigirSalaParaPedido(PedidoSala $pedido, ClienteSucursal $sala, array $sinPedidos): void
    {
        if ($pedido->esTiendaNueva()) {
            throw ValidationException::withMessages(['cliente_sucursal_id' => 'La tienda nueva se enlaza a su sala con «Dar de alta la tienda».']);
        }
        if (in_array($sala->id, $sinPedidos, true)) {
            throw ValidationException::withMessages(['cliente_sucursal_id' => 'En «'.$sala->nombre.'» no se sacan pedidos: elegí otra sala.']);
        }
        $otro = PedidoSala::vigentes()->where('cliente_sucursal_id', $sala->id)->whereKeyNot($pedido->id)
            ->where(fn (Builder $q) => $q->whereDate('fecha', $pedido->fecha)
                ->when($pedido->salida_ruta_id, fn (Builder $q, $viaje) => $q->orWhere('salida_ruta_id', $viaje)))
            ->with('sacadoPor:id,nombre')->first();
        if ($otro !== null) {
            throw ValidationException::withMessages(['cliente_sucursal_id' => '«'.$sala->nombre.'» ya tiene su pedido del '.$otro->fecha->format('d/m/Y')
                .($otro->sacadoPor ? ', de '.$otro->sacadoPor->nombre : '').'. Si es el mismo, anulá uno de los dos.']);
        }
    }

    /**
     * Las cantidades nuevas, por línea. Solo con el pedido por mandar, nunca por debajo de lo
     * ya mandado: 0 solo en lo que no salió nada (el producto anotado de más, 13/11/2026), y
     * no todo el pedido en 0 (eso es «Anular»). Lo marcado por la PC de pedidos que ya no
     * entra baja a lo que falta, y el estado sigue la regla de {@see PedidosFabrica}: sin nada
     * por mandar, queda mandado. Devuelve lo quitado, para el después de la auditoría.
     *
     * @param  array<int|string, string>  $cantidades
     * @return list<array{producto_id: int, producto: ?string, cantidad: string}>
     */
    private function corregirCantidades(PedidoSala $pedido, array $cantidades): array
    {
        $ids = $pedido->lineas->modelKeys();
        foreach (array_keys($cantidades) as $id) {
            if (! in_array((int) $id, $ids, true)) {
                throw ValidationException::withMessages(['pedido' => PedidosFabrica::CAMBIO]);
            }
        }

        $errores = [];
        $cambiadas = [];
        foreach ($pedido->lineas as $linea) {
            if (! array_key_exists($linea->id, $cantidades) || bccomp((string) $cantidades[$linea->id], (string) $linea->cantidad, 4) === 0) {
                continue;
            }
            $nueva = bcadd((string) $cantidades[$linea->id], '0', 4);
            $mandado = $linea->mandado();
            $nombre = $linea->producto?->nombre ?? 'ese producto';
            if (! $pedido->estaPorMandar()) {
                $errores['cantidades.'.$linea->id] = 'El pedido ya está cerrado: la cantidad de '.$nombre.' ya no se corrige.';
            } elseif (bccomp($nueva, $mandado, 4) === -1) {
                $errores['cantidades.'.$linea->id] = 'De '.$nombre.' ya se mandó '.PedidoSalaLinea::legible($mandado).': no puede quedar en menos.';
            } else {
                $cambiadas[] = [$linea, $nueva];
            }
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
        if ($cambiadas === []) {
            return [];
        }
        $nuevas = collect($cambiadas)->mapWithKeys(fn (array $c) => [$c[0]->id => $c[1]]);
        $total = $pedido->lineas->reduce(fn (string $s, PedidoSalaLinea $l) => bcadd($s, $nuevas[$l->id] ?? (string) $l->cantidad, 4), '0');
        if (bccomp($total, '0', 4) === 0) {
            throw ValidationException::withMessages(['pedido' => self::TODO_EN_CERO]);
        }

        $quitadas = [];
        foreach ($cambiadas as [$linea, $nueva]) {
            $linea->cantidad = $nueva;
            // En 0 (solo llega si no se mandó nada): el producto sale del pedido. Si un envío
            // ya deshecho lo nombra, la fila queda en 0: ese envío sigue diciendo qué llevó.
            if (bccomp($nueva, '0', 4) === 0 && ! PedidoSalaEnvioLinea::where('pedido_sala_linea_id', $linea->id)->exists()) {
                $quitadas[] = ['producto_id' => $linea->producto_id, 'producto' => $linea->producto?->nombre, 'cantidad' => '0.0000'];
                $linea->delete();

                continue;
            }
            $pendiente = $linea->pendiente();
            $linea->update([
                'cantidad' => $nueva,
                'va_borrador' => $linea->va_borrador !== null && bccomp((string) $linea->va_borrador, $pendiente, 4) === 1 ? $pendiente : $linea->va_borrador,
                'motivo_no_enviado' => bccomp($pendiente, '0', 4) === 1 ? $linea->motivo_no_enviado : null,
            ]);
        }
        if ($pedido->envios->whereNull('anulado_en')->isNotEmpty()) {
            $pedido->update(['estado' => $pedido->faltaAlgo() ? EstadoPedidoSala::Parcial : EstadoPedidoSala::Enviado]);
        }

        return $quitadas;
    }

    /**
     * Lo que se audita: la sala y cada cantidad.
     *
     * @return array{cliente_sucursal_id: ?int, sala: string, lineas: list<array{producto_id: int, producto: ?string, cantidad: string}>}
     */
    private function foto(PedidoSala $pedido): array
    {
        return [
            'cliente_sucursal_id' => $pedido->cliente_sucursal_id,
            'sala' => $pedido->nombreSala(),
            'lineas' => $pedido->lineas->sortBy('id')->map(fn (PedidoSalaLinea $l) => [
                'producto_id' => $l->producto_id, 'producto' => $l->producto?->nombre, 'cantidad' => (string) $l->cantidad,
            ])->values()->all(),
        ];
    }
}
