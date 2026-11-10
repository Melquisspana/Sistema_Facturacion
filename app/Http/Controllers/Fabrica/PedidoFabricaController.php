<?php

namespace App\Http\Controllers\Fabrica;

use App\Enums\AreaSistema;
use App\Enums\EstadoPedidoSala;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fabrica\BorradorPedidoRequest;
use App\Http\Requests\Fabrica\MandarPedidoRequest;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Services\Rutas\PedidosFabrica;
use App\Support\HoraNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * «Pedidos» de la fábrica: lo que falta mandar, la hoja «Qué va» y lo hecho hoy. La regla
 * está en {@see PedidosFabrica}; acá solo se arma la pantalla y se elige el aviso.
 */
class PedidoFabricaController extends Controller
{
    public function __construct(private readonly PedidosFabrica $fabrica) {}

    public function index(Request $request): View
    {
        $ver = $request->validate(['ver' => ['nullable', 'in:por-mandar,hechos']])['ver'] ?? 'por-mandar';
        $hoy = HoraNegocio::fechaHoy();

        $porMandar = $ver === 'por-mandar' ? $this->fabrica->porMandar()->get() : null;

        return view('fabrica.pedidos.index', [
            'ver' => $ver,
            'cuantosPorMandar' => $porMandar?->count() ?? $this->fabrica->porMandar()->count(),
            'atrasados' => $porMandar?->filter(fn (PedidoSala $p) => $p->fecha->toDateString() < $hoy)->values() ?? collect(),
            'deHoy' => $porMandar?->filter(fn (PedidoSala $p) => $p->fecha->toDateString() >= $hoy)->values() ?? collect(),
            // Una clave por tarjeta: si «Se manda todo» se toca dos veces, sale un solo envío.
            'claves' => $porMandar?->mapWithKeys(fn (PedidoSala $p) => [$p->id => (string) Str::uuid()])->all() ?? [],
            'hechos' => $ver === 'hechos' ? $this->fabrica->hechos() : null,
            // La recarga sola renueva la sesión: para quien ve más del sistema que Fábrica se
            // corta antes de que la sesión venza por inactividad (SESSION_LIFETIME). La
            // tableta de la fábrica (solo Pedidos) se recarga sin tope.
            'limiteRecarga' => AreaSistema::soloFabrica($request->user()) ? null : max(1, (int) config('session.lifetime') - 1) * 60000,
        ]);
    }

    /** La hoja «Qué va a <sala>». Si ya no está por mandar, a la lista con un aviso (no un 403). */
    public function show(PedidoSala $pedido): View|RedirectResponse
    {
        if (! $pedido->estaPorMandar()) {
            return redirect()->route('fabrica.pedidos.index')->with('status', 'Ese pedido ya está resuelto.');
        }
        Gate::authorize('mandar', $pedido);
        $pedido->load($this->fabrica->relaciones());

        return view('fabrica.pedidos.show', ['pedido' => $pedido, 'clave' => (string) Str::uuid()]);
    }

    /** Lo marcado en la hoja, sin recargar. Responde JSON (los errores, 422 con su texto). */
    public function borrador(BorradorPedidoRequest $request, PedidoSala $pedido): JsonResponse
    {
        $this->fabrica->guardarBorrador($pedido, $request->validated() + ['sigue' => $request->boolean('sigue')], $request->user());

        return response()->json(['guardado' => true]);
    }

    public function borrarBorrador(Request $request, PedidoSala $pedido): RedirectResponse
    {
        $this->fabrica->borrarBorrador($pedido, $request->user());

        return redirect()->route('fabrica.pedidos.show', $pedido)->with('status', 'Se borró lo marcado.');
    }

    public function mandar(MandarPedidoRequest $request, PedidoSala $pedido): RedirectResponse
    {
        $envio = $this->fabrica->mandar($pedido, $request->validated() + ['sigue' => $request->boolean('sigue')], $request->user());
        $pedido = $envio->loadMissing('pedido.sala', 'pedido.lineas.envioLineasVigentes')->pedido;
        $sala = $pedido->nombreSala();

        $mensaje = match (true) {
            $pedido->estado === EstadoPedidoSala::Parcial => 'Salió una parte de '.$sala.'. Lo que falta sigue pendiente.',
            $pedido->faltaAlgo() => 'Salió el pedido de '.$sala.'. Lo que faltó no va.',
            default => 'Salió el pedido de '.$sala.'.',
        };

        return redirect()->route('fabrica.pedidos.index')->with('status', $mensaje)
            ->with('deshacer', ['tipo' => 'envio', 'pedido' => $pedido->id, 'envio' => $envio->id]);
    }

    /** «No se trae» o «Lo que falta no se trae». */
    public function cerrar(Request $request, PedidoSala $pedido): RedirectResponse
    {
        $version = $request->validate(['version' => ['required', 'string', 'size:64']], [
            'version.required' => 'Esta pantalla quedó vieja. Volvé a abrir el pedido.',
            'version.string' => 'Esta pantalla quedó vieja. Volvé a abrir el pedido.',
            'version.size' => 'Esta pantalla quedó vieja. Volvé a abrir el pedido.',
        ])['version'];
        $estado = $this->fabrica->cerrar($pedido, $version, $request->user());

        if ($estado === null) {
            return redirect()->route('fabrica.pedidos.index')->with('status', 'Ese pedido ya estaba resuelto.');
        }
        $sala = $pedido->nombreSala();

        return redirect()->route('fabrica.pedidos.index')
            ->with('status', $estado === EstadoPedidoSala::NoSeTrae
                ? 'El pedido de '.$sala.' no se trae.'
                : 'Pedido de '.$sala.' cerrado: lo que falta no se trae.')
            ->with('deshacer', ['tipo' => 'cierre', 'pedido' => $pedido->id]);
    }

    /** «Volver a pendiente», «Deshacer» un cierre o «Mandar lo que faltó» (abre la hoja). */
    public function reabrir(Request $request, PedidoSala $pedido): RedirectResponse
    {
        $cambio = $this->fabrica->reabrir($pedido, $request->user());

        if ($request->boolean('abrir')) {
            return redirect()->route('fabrica.pedidos.show', $pedido);
        }

        return redirect()->to(url()->previous(route('fabrica.pedidos.index')))
            ->with('status', $cambio ? 'El pedido de '.$pedido->nombreSala().' volvió a pendiente.' : 'Ese pedido ya estaba pendiente.');
    }

    /** «Deshacer» un envío del día: queda anulado, no se borra. */
    public function anularEnvio(Request $request, PedidoSala $pedido, PedidoSalaEnvio $envio): RedirectResponse
    {
        $cambio = $this->fabrica->anularEnvio($pedido, $envio, $request->user());

        return redirect()->to(url()->previous(route('fabrica.pedidos.index')))
            ->with('status', $cambio
                ? 'Envío '.$envio->numero.' de '.$pedido->nombreSala().' deshecho. Lo que llevaba vuelve a quedar pendiente.'
                : 'Ese envío ya estaba deshecho.');
    }
}
