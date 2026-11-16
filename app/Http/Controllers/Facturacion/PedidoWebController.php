<?php

namespace App\Http\Controllers\Facturacion;

use App\Ajustes\Integraciones\ConfiguracionTiendaWeb;
use App\Enums\EstadoPedidoWeb;
use App\Enums\PermisoSistema;
use App\Http\Controllers\Controller;
use App\Models\PedidoWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PedidoWebController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize(PermisoSistema::DteVer->value);
        $estado = $request->validate([
            'estado' => ['nullable', Rule::in(['todos', ...array_column(EstadoPedidoWeb::cases(), 'value')])],
        ])['estado'] ?? '';
        $pedidos = PedidoWeb::query()
            // No cargar ni descifrar teléfono, dirección, correo ni regalos para esta lista.
            ->select(['id', 'codigo', 'pagado_en', 'cliente_nombre', 'entrega_tipo', 'total_centavos', 'estado', 'alerta', 'motivo_revision', 'dte_id', 'pedido_sala_id', 'updated_at'])
            ->with(['pedidoSala.ruta:id,nombre', 'pedidoSala.salida.ruta:id,nombre'])
            ->when($estado === '', fn ($q) => $q->where(fn ($q) => $q
                ->where('estado', EstadoPedidoWeb::ParaRevisar)
                ->orWhere('alerta', '<>', '')->orWhereNotNull('motivo_revision')
                ->orWhere(fn ($q) => $q->where('estado', EstadoPedidoWeb::Facturando)->where('updated_at', '<', now()->subMinutes(30)))))
            ->when($estado !== '' && $estado !== 'todos', fn ($q) => $q->where('estado', $estado))
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [EstadoPedidoWeb::ParaRevisar->value])
            ->orderBy('pagado_en')->orderBy('id')->paginate(25)->withQueryString();

        return view('facturacion.pedidos-web.index', compact('pedidos', 'estado'));
    }

    public function revisado(Request $request, PedidoWeb $pedidoWeb, ConfiguracionTiendaWeb $tienda): RedirectResponse
    {
        Gate::authorize(PermisoSistema::DteGestionar->value);
        $datos = $request->validate([
            'nota' => ['required', 'string', 'max:300', 'not_regex:/[\\x00-\\x1F\\x7F]/u', 'not_regex:/(?:\\d[ -]?){13,19}/'],
            'confirmacion' => ['accepted'],
        ], ['nota.not_regex' => 'Escribí una nota corta sin datos de tarjeta.']);
        if (trim($datos['nota']) === '') {
            throw ValidationException::withMessages(['nota' => 'Escribí una nota corta sin datos de tarjeta.']);
        }
        try {
            $respuesta = $tienda->cliente()->post($tienda->url().'/api/sistema/pedidos/'.$pedidoWeb->web_id.'/revisado', ['nota' => trim($datos['nota'])]);
            $ok = $respuesta->status() === 200;
        } catch (Throwable) {
            $ok = false;
        }
        if (! $ok) {
            return back()->withErrors(['pedido' => 'La página no confirmó la revisión. Se conserva la alerta; volvé a intentar.']);
        }
        DB::transaction(function () use ($request, $pedidoWeb) {
            $pedido = PedidoWeb::whereKey($pedidoWeb->id)->lockForUpdate()->firstOrFail();
            if (blank($pedido->alerta)) {
                return;
            }
            $pedido->update(['alerta' => '']);
            activity('pedido_web')->performedOn($pedido)->causedBy($request->user())
                ->withProperties(['alerta_anterior' => 'pago_doble', 'alerta' => ''])
                ->log('revisó alerta de pago doble del pedido web');
        });

        return redirect()->route('facturacion.pedidos-web.index')->with('status', 'La revisión quedó confirmada en la página.');
    }

    public function volverAAvisar(Request $request, PedidoWeb $pedidoWeb): RedirectResponse
    {
        Gate::authorize(PermisoSistema::DteGestionar->value);
        $request->validate(['confirmacion' => ['accepted']]);
        DB::transaction(function () use ($request, $pedidoWeb) {
            $pedido = PedidoWeb::whereKey($pedidoWeb->id)->lockForUpdate()->firstOrFail();
            if ($pedido->estado === EstadoPedidoWeb::ParaRevisar) {
                throw ValidationException::withMessages(['pedido' => 'El pedido está para revisar. Usá Reintentar o corregí su factura en Facturación.']);
            }
            if (blank($pedido->motivo_revision)) {
                throw ValidationException::withMessages(['pedido' => 'El pedido no tiene un motivo de revisión para volver a avisar.']);
            }
            // Solo copiar mensajes propios de rechazo; otros motivos pueden contener datos personales.
            preg_match_all('/La página rechazó el aviso: (?:preparando|en_ruta|lista|entregado|factura) \(HTTP (?:404|409|422)\)\. Revisalo en la página\./u', $pedido->motivo_revision, $avisos);
            $motivoAnterior = implode("\n", $avisos[0]) ?: 'Motivo omitido por privacidad.';
            $pedido->update(['motivo_revision' => null]);
            activity('pedido_web')->performedOn($pedido)->causedBy($request->user())
                ->withProperties(['motivo_anterior' => $motivoAnterior, 'motivo_revision' => null])
                ->log('volver a avisar pedido web');
        }, 3);

        return redirect()->route('facturacion.pedidos-web.index')->with('status', 'El pedido quedó listo para volver a avisar en la próxima pasada.');
    }

    public function reintentar(Request $request, PedidoWeb $pedidoWeb): RedirectResponse
    {
        Gate::authorize(PermisoSistema::DteGestionar->value);
        DB::transaction(function () use ($request, $pedidoWeb) {
            $pedido = PedidoWeb::query()->lockForUpdate()->findOrFail($pedidoWeb->id);
            if ($pedido->estado !== EstadoPedidoWeb::ParaRevisar || $pedido->dte_id !== null) {
                throw ValidationException::withMessages(['pedido' => 'Solo se puede reintentar un pedido para revisar sin factura. Si tiene factura, corregila en Facturación.']);
            }
            $pedido->update(['estado' => EstadoPedidoWeb::Importado, 'motivo_revision' => null]);
            activity('pedido_web')->performedOn($pedido)->causedBy($request->user())
                ->withProperties(['estado_anterior' => EstadoPedidoWeb::ParaRevisar->value, 'estado' => EstadoPedidoWeb::Importado->value])
                ->log('reintentar pedido web');
        }, 3);

        return redirect()->route('facturacion.pedidos-web.index')->with('status', 'El pedido quedó listo para reintentar.');
    }
}
