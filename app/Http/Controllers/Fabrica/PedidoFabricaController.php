<?php

namespace App\Http\Controllers\Fabrica;

use App\Enums\AreaSistema;
use App\Enums\EstadoPedidoSala;
use App\Enums\OrigenPedidoSala;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fabrica\BorradorPedidoRequest;
use App\Http\Requests\Fabrica\MandarPedidoRequest;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Services\Dte\DtePdfService;
use App\Services\Rutas\PedidosFabrica;
use App\Support\HoraNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $ver = $request->validate(['ver' => ['nullable', 'in:por-mandar,hechos,para-recoger']])['ver'] ?? 'por-mandar';
        $hoy = HoraNegocio::fechaHoy();

        $porMandar = $ver === 'por-mandar' ? $this->fabrica->porMandar()->get() : null;
        // Arriba, las cajas listas para salir (24/11/2026): lo que se lleva la persona que se
        // regresa en la tarde. Debajo, lo demás por día, como antes.
        [$listos, $armando] = ($porMandar ?? collect())->partition(fn (PedidoSala $p) => $p->estado === EstadoPedidoSala::Listo);

        return view('fabrica.pedidos.index', [
            'ver' => $ver,
            'paraRecoger' => $this->fabrica->paraRecoger()->get(),
            'cuantosPorMandar' => $porMandar?->count() ?? $this->fabrica->porMandar()->count(),
            'listos' => $listos->values(),
            'atrasados' => $armando->filter(fn (PedidoSala $p) => $p->fecha->toDateString() < $hoy)->values(),
            'deHoy' => $armando->filter(fn (PedidoSala $p) => $p->fecha->toDateString() >= $hoy)->values(),
            'hechos' => $ver === 'hechos' ? $this->fabrica->hechos() : null,
            // La recarga sola renueva la sesión: para quien ve más del sistema que Fábrica se
            // corta antes de que la sesión venza por inactividad (SESSION_LIFETIME). La
            // tableta de la fábrica (solo Pedidos) se recarga sin tope.
            'limiteRecarga' => AreaSistema::soloFabrica($request->user()) ? null : max(1, (int) config('session.lifetime') - 1) * 60000,
        ]);
    }

    public function otros(PedidoSala $pedido): JsonResponse
    {
        Gate::authorize('mandar', $pedido);
        $otros = $this->fabrica->otrosPorMandar($pedido);

        return response()->json([
            'html' => view('fabrica.pedidos._otros', compact('otros'))->render(),
            'ids' => $otros->where('origen', OrigenPedidoSala::Web)->pluck('id')->values(),
        ]);
    }

    /** La hoja «Qué va a <sala>». Si ya no está por mandar, a la lista con un aviso (no un 403). */
    public function show(PedidoSala $pedido): View|RedirectResponse
    {
        if (! $pedido->estaPorMandar()) {
            return redirect()->route('fabrica.pedidos.index')->with('status', 'Ese pedido ya está resuelto.');
        }
        Gate::authorize('mandar', $pedido);
        $pedido->load($this->fabrica->relaciones(conSala: true));

        return view('fabrica.pedidos.show', [
            'pedido' => $pedido,
            'clave' => (string) Str::uuid(),
            'otros' => $this->fabrica->otrosPorMandar($pedido),
        ]);
    }

    /**
     * «Imprimir para la caja» (24/11/2026): la «Orden para la caja», sacada de lo marcado,
     * para dejarla encima de la caja armada que todavía no sale. Solo lectura (GET): no
     * registra envío, no cambia el estado ni llega a facturación, y se saca las veces que
     * haga falta. La hoja guarda antes de abrirla, aunque no se haya tocado nada: desde ahí el
     * pedido queda «Armando» y el vendedor ya no lo cambia. El pedido web tiene su propio papel.
     */
    public function caja(PedidoSala $pedido): View|RedirectResponse
    {
        abort_if($pedido->origen === OrigenPedidoSala::Web, 404);
        if (! $pedido->estaPorMandar()) {
            return redirect()->route('fabrica.pedidos.index')->with('status', 'Ese pedido ya está resuelto.');
        }
        Gate::authorize('mandar', $pedido);
        $pedido->load($this->fabrica->relaciones(conSala: true));

        return view('fabrica.pedidos.caja', ['pedido' => $pedido]);
    }

    /**
     * La hoja para imprimir de UN envío: lo que va en la caja, lo que no va y lo que queda.
     * Solo lectura (GET): no cambia datos. Un envío deshecho ya no se manda y no se imprime.
     * El envío es de ese pedido por `scopeBindings` (otro pedido en la URL: 404).
     */
    public function imprimir(Request $request, PedidoSala $pedido, PedidoSalaEnvio $envio): View
    {
        abort_unless($pedido->origen !== OrigenPedidoSala::Web && $envio->estaVigente(), 404);
        $pedido->load($this->fabrica->relaciones(conSala: true));
        $envio->load('lineas.linea.producto:id,nombre,codigo');
        // Recién mandado, el aviso y el «Deshacer» siguen para cuando vuelva a la lista.
        $request->session()->reflash();

        return view('fabrica.pedidos.imprimir', ['pedido' => $pedido, 'envio' => $envio, 'corrige' => $this->fabrica->corrigeA($pedido, $envio)]
            + $this->fabrica->faltanteAlEnvio($pedido, $envio));
    }

    public function imprimirWeb(Request $request, PedidoSala $pedido, PedidoSalaEnvio $envio, DtePdfService $pdf): Response|View
    {
        Gate::authorize('imprimirWeb', $pedido);
        abort_unless($pedido->origen === OrigenPedidoSala::Web && $envio->pedido_sala_id === $pedido->id && $envio->estaVigente(), 404);
        $web = $pedido->pedidoWeb()->with('dte')->firstOrFail();
        abort_unless($web->facturaLista(), 409, 'Esperando factura');
        $request->session()->reflash();
        if ($web->regalo) {
            return view('fabrica.pedidos.tarjeta-regalo', ['regalo' => $web->regalo]);
        }
        $barra = view('fabrica.pedidos._imprimir-web')->render();
        $html = $pdf->html($web->dte);

        return response(str_replace('</body>', $barra.'</body>', $html));
    }

    public function recogido(Request $request, PedidoSala $pedido): RedirectResponse
    {
        $this->fabrica->recogido($pedido, $request->user());

        return redirect()->route('fabrica.pedidos.index', ['ver' => 'para-recoger'])
            ->with('status', 'El pedido ya fue recogido.');
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

    /**
     * «Listo para salir» (listo=1) o «Quitar de listos» (listo=0), sin confirmar. Al marcarlo
     * vuelve a la lista, donde el aviso trae «Deshacer»; al quitarlo vuelve a donde estaba: la
     * hoja (para seguir armando) o la lista (su «Deshacer»). Marcarlo lleva la versión de la
     * hoja, como «Imprimir y mandar»; quitarlo, no.
     */
    public function listo(Request $request, PedidoSala $pedido): RedirectResponse
    {
        $vieja = 'Esta pantalla quedó vieja. Volvé a abrir el pedido.';
        $datos = $request->validate([
            'listo' => ['required', 'boolean'],
            'version' => ['exclude_unless:listo,1,true', 'required', 'string', 'size:64'],
        ], [
            'listo.required' => $vieja,
            'listo.boolean' => $vieja,
            'version.required' => $vieja,
            'version.string' => $vieja,
            'version.size' => $vieja,
        ]);
        $listo = (bool) $datos['listo'];
        $cambio = $this->fabrica->marcarListo($pedido, $listo, $request->user(), $datos['version'] ?? null);
        $sala = $pedido->nombreSala();

        if (! $listo) {
            return redirect()->to(url()->previous(route('fabrica.pedidos.show', $pedido)))
                ->with('status', 'El pedido de '.$sala.($cambio ? ' ya no está' : ' ya no estaba').' en «Listos para salir».');
        }

        return redirect()->route('fabrica.pedidos.index')
            ->with('status', 'El pedido de '.$sala.($cambio ? ' quedó' : ' ya estaba').' en «Listos para salir».')
            ->with('deshacer', $cambio ? ['tipo' => 'listo', 'pedido' => $pedido->id] : null);
    }

    /** «Imprimir y mandar»: registra el envío y abre su hoja para imprimir. */
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

        return redirect()->route($pedido->origen === OrigenPedidoSala::Web ? 'fabrica.pedidos.imprimir-web' : 'fabrica.pedidos.imprimir', [$pedido, $envio])->with('status', $mensaje)
            ->with('deshacer', $pedido->origen === OrigenPedidoSala::Web ? null : ['tipo' => 'envio', 'pedido' => $pedido->id, 'envio' => $envio->id]);
    }

    /**
     * «No mandar nada de este pedido» o «No mandar lo que falta», con su motivo (obligatorio
     * desde el 13/11/2026: uno de los que se tocan o el que escribió). Sin motivo vuelve al
     * pedido con el aviso y no cierra nada.
     */
    public function cerrar(Request $request, PedidoSala $pedido): RedirectResponse
    {
        $vieja = 'Esta pantalla quedó vieja. Volvé a abrir el pedido.';
        $datos = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'motivo' => ['required', 'string', 'max:200'],
        ], [
            'version.required' => $vieja,
            'version.string' => $vieja,
            'version.size' => $vieja,
            'motivo.required' => PedidosFabrica::SIN_MOTIVO,
            'motivo.string' => PedidosFabrica::SIN_MOTIVO,
            'motivo.max' => PedidosFabrica::MOTIVO_LARGO,
        ]);
        $estado = $this->fabrica->cerrar($pedido, $datos['version'], $request->user(), $datos['motivo']);

        if ($estado === null) {
            return redirect()->route('fabrica.pedidos.index')->with('status', 'Ese pedido ya estaba resuelto.');
        }
        $sala = $pedido->nombreSala();

        return redirect()->route('fabrica.pedidos.index')
            ->with('status', $estado === EstadoPedidoSala::NoSeTrae
                ? 'No se manda nada del pedido de '.$sala.'.'
                : 'Lo que falta del pedido de '.$sala.' no se manda.')
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

    /**
     * «Corregir» un envío del día: lo anula como «Deshacer» y abre el pedido con lo que
     * llevaba ya marcado. El segundo toque de un doble toque solo abre el pedido.
     */
    public function corregirEnvio(Request $request, PedidoSala $pedido, PedidoSalaEnvio $envio): RedirectResponse
    {
        $cambio = $this->fabrica->corregirEnvio($pedido, $envio, $request->user());
        $destino = redirect()->route('fabrica.pedidos.show', $pedido);

        return $cambio
            ? $destino->with('status', 'Envío '.$envio->numero.' anulado para corregirlo. Cambiá lo que haga falta y tocá «Imprimir y mandar».')
            : $destino;
    }
}
