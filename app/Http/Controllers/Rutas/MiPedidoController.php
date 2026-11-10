<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\PedidoSalaRequest;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Services\Rutas\PedidosSala;
use App\Services\Rutas\Ritmo;
use App\Services\Rutas\RitmoPedidos;
use App\Services\Rutas\RitmoRutas;
use App\Services\Rutas\Viajes;
use App\Support\Dte\ReglaOrdenCompra;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * «Sacar pedido» del vendedor, sin salida armada (10/11/2026): buscar cualquier sala, el
 * formulario del sugerido, guardarlo, corregirlo y anularlo. La regla está en
 * {@see PedidosSala}; el viaje al que se suma, en {@see Viajes}. Acá solo se arma la pantalla.
 */
class MiPedidoController extends Controller
{
    public function __construct(private readonly PedidosSala $pedidos, private readonly Viajes $viajes, private readonly RitmoPedidos $ritmo) {}

    /** El buscador de salas y los pedidos que sacó hoy. */
    public function index(Request $request): View
    {
        $texto = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $persona = PedidosSala::persona($request->user());
        $salas = $persona && mb_strlen($texto) >= 2
            ? ClienteSucursal::query()->buscarTexto($texto)->with(['cliente:id,nombre', 'distrito:id,nombre'])->orderBy('nombre')->limit(20)->get()
            : null;
        $pedidosHoy = $persona
            ? PedidoSala::query()->where('sacado_por_id', $persona->id)->whereDate('fecha', HoraNegocio::fechaHoy())->vigentes()
                ->withExists('envios')->with(['sala:id,nombre', 'lineas.producto:id,nombre', 'salida'])->orderBy('id')->get()
            : collect();

        return view('rutas.sacar-pedido.index', [
            'persona' => $persona,
            'viaje' => $this->viajeQueSuma($persona),
            'texto' => $texto,
            'salas' => $salas,
            'sacadas' => $salas === null ? collect() : PedidoSala::vigentes()->whereIn('cliente_sucursal_id', $salas->pluck('id'))
                ->whereDate('fecha', HoraNegocio::fechaHoy())->pluck('cliente_sucursal_id')->flip(),
            'ritmos' => $salas === null ? [] : $this->ritmos($salas),
            'pedidosHoy' => $pedidosHoy,
        ]);
    }

    public function create(Request $request, ClienteSucursal $sucursal): View|RedirectResponse
    {
        Gate::authorize('create', PedidoSala::class);
        abort_unless($sucursal->activo, 404);

        // Ya sacado: no se saca otro. Quien lo sacó lo corrige (si la fábrica no empezó); los
        // demás vuelven a la búsqueda con el aviso.
        $sacado = $this->pedidos->yaSacado($sucursal, $request->user());
        if ($sacado) {
            if ($request->user()->can('corregir', $sacado)) {
                return redirect()->route('rutas.sacar-pedido.edit', $sacado);
            }

            return redirect()->route('rutas.sacar-pedido.index')->with('error', $sacado->fabricaEmpezo()
                ? 'El pedido de «'.$sucursal->nombre.'» ya está en la fábrica. Si cambió algo, avisá a la oficina.'
                : 'El pedido de «'.$sucursal->nombre.'» ya está sacado.');
        }

        return $this->formulario($request, $sucursal, null);
    }

    public function store(PedidoSalaRequest $request, ClienteSucursal $sucursal): RedirectResponse
    {
        $antes = $this->viajeQueSuma(PedidosSala::persona($request->user()));
        $pedido = $this->pedidos->guardar($sucursal, $request->validated(), $request->user());
        $nuevo = $antes?->id !== $pedido->salida_ruta_id ? ' Se abrió tu viaje: '.$pedido->salida->nombreViaje().'.' : '';

        return $this->volver($pedido, 'Pedido de «'.$sucursal->nombre.'» guardado.'.$nuevo);
    }

    /** Tienda nueva: el sugerido con el nombre que escribió en el buscador. */
    public function createTiendaNueva(Request $request): View
    {
        Gate::authorize('create', PedidoSala::class);
        $nombre = preg_replace('/\s+/u', ' ', trim((string) ($request->validate(['nombre' => ['nullable', 'string', 'max:100']])['nombre'] ?? '')));

        return $this->formulario($request, null, null, $nombre)->with('parecidas', $this->parecidas($nombre));
    }

    /**
     * «¿Es alguna de estas?»: las salas que ya existen con un nombre parecido al que escribió,
     * para no cargar dos veces la misma tienda. Busca el texto entero y cada palabra que dice
     * algo (sin «tienda», «sala», «súper»…).
     *
     * @return Collection<int, ClienteSucursal>
     */
    private function parecidas(string $nombre): Collection
    {
        $comunes = ['tienda', 'sala', 'super', 'súper', 'minisuper', 'minisúper', 'despensa', 'abarrotes', 'comercial'];
        $palabras = collect(preg_split('/\s+/u', mb_strtolower($nombre)))
            ->filter(fn (string $p) => mb_strlen($p) >= 4 && ! in_array($p, $comunes, true))->push($nombre)->unique()->values();
        if (mb_strlen($nombre) < 3) {
            return collect();
        }

        return ClienteSucursal::query()->where('activo', true)
            ->where(fn ($q) => $palabras->each(fn (string $p) => $q->orWhere(fn ($o) => $o->buscarTexto($p))))
            ->with(['cliente:id,nombre', 'distrito:id,nombre'])->orderBy('nombre')->limit(8)->get();
    }

    /** Tienda nueva: solo el nombre. La oficina la da de alta y la enlaza al facturar. */
    public function storeTiendaNueva(PedidoSalaRequest $request): RedirectResponse
    {
        $antes = $this->viajeQueSuma(PedidosSala::persona($request->user()));
        $pedido = $this->pedidos->guardar(null, $request->validated(), $request->user());
        $nuevo = $antes?->id !== $pedido->salida_ruta_id ? ' Se abrió tu viaje: '.$pedido->salida->nombreViaje().'.' : '';

        return $this->volver($pedido, 'Pedido de «'.$pedido->nombreSala().'» guardado. La oficina carga sus datos al facturar.'.$nuevo);
    }

    public function edit(Request $request, PedidoSala $pedido): View|RedirectResponse
    {
        // Con una pantalla vieja (o volviendo de un error al guardar): un aviso, no un 403.
        if ($pedido->estaVigente() && $pedido->fabricaEmpezo() && PedidosSala::loSaco($request->user(), $pedido)) {
            return redirect()->route('rutas.sacar-pedido.index')
                ->with('error', 'El pedido de «'.$pedido->nombreSala().'» ya está en la fábrica. Si cambió algo, avisá a la oficina.');
        }
        // Ya sacó el de la sala siguiente: desde ahí lo corrige la oficina.
        if ($pedido->estaVigente() && PedidosSala::loSaco($request->user(), $pedido) && PedidosSala::sacoElSiguiente($pedido)) {
            return redirect()->route('rutas.sacar-pedido.index')
                ->with('error', 'Ya sacaste el pedido de otra sala: este lo corrige la oficina.');
        }
        Gate::authorize('corregir', $pedido);

        return $this->formulario($request, $pedido->sala, $pedido);
    }

    public function update(PedidoSalaRequest $request, PedidoSala $pedido): RedirectResponse
    {
        $pedido = $this->pedidos->guardar($pedido->sala, $request->validated(), $request->user(), $pedido);

        return $this->volver($pedido, 'Pedido de «'.$pedido->nombreSala().'» corregido.');
    }

    public function anular(Request $request, PedidoSala $pedido): RedirectResponse
    {
        $this->pedidos->anular($pedido, $request->user());

        return redirect()->route('rutas.sacar-pedido.index')
            ->with('status', 'Pedido de «'.$pedido->nombreSala().'» anulado. Si hace falta, sacalo de nuevo.');
    }

    /** El sugerido. Sin `$sala`, la tienda nueva: con `$nombreNueva` o el del pedido. */
    private function formulario(Request $request, ?ClienteSucursal $sala, ?PedidoSala $pedido, string $nombreNueva = ''): View
    {
        $sala?->loadMissing('cliente:id,requiere_orden_compra');
        $pedido?->load('lineas');

        return view('rutas.mi-salida.pedido', [
            'viaje' => $pedido?->salida ?? $this->viajeQueSuma(PedidosSala::persona($request->user())),
            'sala' => $sala,
            'nombreNueva' => $pedido?->sala_nueva_nombre ?? $nombreNueva,
            'pedido' => $pedido,
            'productos' => $this->pedidos->productos(),
            'cantidades' => $pedido ? $pedido->lineas->mapWithKeys(fn ($l) => [$l->producto_id => $l->cantidadLegible()])->all() : [],
            // La OC solo si la sala la usa (la misma regla de facturación) o si ya se anotó una.
            'conOc' => ReglaOrdenCompra::requerida($sala?->cliente, $sala) || filled($pedido?->numero_orden_compra),
        ]);
    }

    /**
     * «Le toca» en el buscador: el ritmo de compra de cada sala encontrada, con los CCF (el
     * mismo de «Tu próxima salida» y el tablero). Pronto o atrasada: le toca visita.
     *
     * @param  Collection<int, ClienteSucursal>  $salas
     * @return array<int, array{leToca: bool, dias: ?int}>
     */
    private function ritmos(Collection $salas): array
    {
        $hoy = CarbonImmutable::parse(HoraNegocio::fechaHoy());
        $ritmos = $this->ritmo->porSala($salas->modelKeys(), $hoy);

        return $salas->mapWithKeys(function (ClienteSucursal $sala) use ($ritmos, $hoy) {
            $fila = RitmoRutas::filaSala($sala, $ritmos[$sala->id], $hoy);

            return [$sala->id => ['leToca' => in_array($fila['estado'], [Ritmo::PRONTO, Ritmo::ATRASADA], true), 'dias' => $fila['dias']]];
        })->all();
    }

    /** El viaje del vendedor si todavía suma pedidos: adonde iría el próximo. */
    private function viajeQueSuma(?PersonalRuta $persona): ?SalidaRuta
    {
        $viaje = $persona ? $this->viajes->abierto($persona) : null;

        return $viaje !== null && $this->viajes->sigueSumando($viaje) ? $viaje : null;
    }

    /** A «Sacar pedido», con el aviso de la OC si su forma no cuadra (no bloquea). */
    private function volver(PedidoSala $pedido, string $mensaje): RedirectResponse
    {
        $avisos = $pedido->avisosOrdenCompra();

        return redirect()->route('rutas.sacar-pedido.index')
            ->with('status', trim($mensaje.($avisos ? ' Revisá la orden de compra: '.implode(' ', $avisos) : '')));
    }
}
