<?php

namespace App\Http\Controllers\Facturacion;

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
            ->select(['id', 'codigo', 'pagado_en', 'cliente_nombre', 'entrega_tipo', 'total_centavos', 'estado', 'motivo_revision', 'dte_id', 'pedido_sala_id', 'updated_at'])
            ->with(['pedidoSala.ruta:id,nombre', 'pedidoSala.salida.ruta:id,nombre'])
            ->when($estado === '', fn ($q) => $q->where(fn ($q) => $q
                ->where('estado', EstadoPedidoWeb::ParaRevisar)
                ->orWhere(fn ($q) => $q->where('estado', EstadoPedidoWeb::Facturando)->where('updated_at', '<', now()->subMinutes(30)))))
            ->when($estado !== '' && $estado !== 'todos', fn ($q) => $q->where('estado', $estado))
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [EstadoPedidoWeb::ParaRevisar->value])
            ->orderBy('pagado_en')->orderBy('id')->paginate(25)->withQueryString();

        return view('facturacion.pedidos-web.index', compact('pedidos', 'estado'));
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
