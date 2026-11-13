<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\PedidoSalaRequest;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Services\Rutas\FichaVendedor;
use App\Services\Rutas\HistoriaSala;
use App\Services\Rutas\PedidosSala;
use App\Services\Rutas\RitmoRutas;
use App\Services\Rutas\Viajes;
use App\Support\Dte\ReglaOrdenCompra;
use App\Support\HoraNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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
    /** Cuántas salas trae, como mucho, la lista que aparece mientras escribe. */
    public const TOPE_LISTA = 10;

    public function __construct(private readonly PedidosSala $pedidos, private readonly Viajes $viajes, private readonly RitmoRutas $ritmo) {}

    /** El buscador de salas y los pedidos que sacó hoy. */
    public function index(Request $request): View
    {
        $texto = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        app(FichaVendedor::class)->asegurar($request->user());
        $persona = PedidosSala::persona($request->user());
        $salas = $persona && mb_strlen($texto) >= 2
            ? ClienteSucursal::query()->buscarTexto($texto)->whereKeyNot(PedidosSala::salasSinPedidos())->with(['cliente:id,nombre', 'distrito:id,nombre'])->orderBy('nombre')->limit(20)->get()
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

    /**
     * La lista que aparece mientras escribe (12/11/2026, pedido del dueño): las salas activas
     * que contienen el texto, con la misma consulta que el botón «Buscar»
     * ({@see ClienteSucursal::scopeBuscarTexto()}; mayúsculas y tildes las iguala la collation
     * de MySQL). Solo lo que la lista muestra: nada fiscal ni precios. Como mucho
     * {@see self::TOPE_LISTA}. El permiso y el límite de pedidos los pone la ruta.
     */
    public function salas(Request $request): JsonResponse
    {
        $texto = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];

        $salas = ClienteSucursal::query()->buscarTexto(trim($texto))->whereKeyNot(PedidosSala::salasSinPedidos())
            ->with(['cliente:id,nombre', 'distrito:id,nombre'])
            ->orderBy('nombre')->limit(self::TOPE_LISTA)
            ->get(['id', 'nombre', 'codigo', 'cliente_id', 'distrito_id']);

        return response()->json(['salas' => $salas->map(fn (ClienteSucursal $sala) => [
            'nombre' => $sala->nombre,
            'detalle' => $sala->detalleBuscador(),
            'url' => route('rutas.sacar-pedido.create', $sala),
        ])->values()]);
    }

    public function create(Request $request, ClienteSucursal $sucursal): View|RedirectResponse
    {
        Gate::authorize('create', PedidoSala::class);
        // Inactiva, o una sala donde no se sacan pedidos: como si no existiera.
        abort_unless($sucursal->activo && ! in_array($sucursal->id, PedidosSala::salasSinPedidos(), true), 404);

        // Ya sacado: no se saca otro. Quien lo sacó lo corrige (si la fábrica no empezó); los
        // demás vuelven a la búsqueda con el aviso.
        $sacado = $this->pedidos->yaSacado($sucursal, $request->user());
        if ($sacado) {
            if ($request->user()->can('corregir', $sacado)) {
                return redirect()->route('rutas.sacar-pedido.edit', $sacado);
            }

            return redirect()->route('rutas.sacar-pedido.index')->with('error', $sacado->fabricaEmpezo()
                ? 'El pedido de «'.$sucursal->nombre.'» ya está en despacho. Si cambió algo, avisá a la oficina.'
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

        return ClienteSucursal::query()->where('activo', true)->whereKeyNot(PedidosSala::salasSinPedidos())
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
                ->with('error', 'El pedido de «'.$pedido->nombreSala().'» ya está en despacho. Si cambió algo, avisá a la oficina.');
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
            // La historia de la sala: su último pedido y entrega, cada cuánto compra, si le toca y lo que debe.
            'historia' => $sala ? app(HistoriaSala::class)->de($sala) : null,
            // La OC solo si la sala la usa (la misma regla de facturación) o si ya se anotó una.
            'conOc' => ReglaOrdenCompra::requerida($sala?->cliente, $sala) || filled($pedido?->numero_orden_compra),
        ]);
    }

    /**
     * «Le toca» en el buscador: el ritmo real de cada sala encontrada, el mismo del tablero
     * ({@see RitmoRutas::deSalas()}): los días desde su última visita (la entrega o el pedido
     * más nuevo) contra cada cuánto compra.
     *
     * @param  Collection<int, ClienteSucursal>  $salas
     * @return array<int, array{leToca: bool, dias: ?int}>
     */
    private function ritmos(Collection $salas): array
    {
        return array_map(
            fn (array $fila) => ['leToca' => RitmoRutas::leToca($fila), 'dias' => $fila['dias']],
            $this->ritmo->deSalas($salas, CarbonImmutable::parse(HoraNegocio::fechaHoy())),
        );
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
