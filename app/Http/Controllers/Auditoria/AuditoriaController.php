<?php

namespace App\Http\Controllers\Auditoria;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\User;
use App\Support\Dte\ReglaOrdenCompra;
use App\Support\OrdenCompra;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class AuditoriaController extends Controller
{
    public function index(Request $request): View
    {
        // Solo quien tenga el permiso auditoria.ver (administrador y contabilidad).
        abort_unless($request->user()->can('auditoria.ver'), 403);

        $causerId = $request->input('causer_id');
        $logName = $request->input('log_name');
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');

        $actividades = Activity::query()
            ->with('causer')
            ->when($causerId, fn ($q) => $q->where('causer_id', $causerId))
            ->when($logName, fn ($q) => $q->where('log_name', $logName))
            ->when($desde, fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('auditoria.index', [
            'actividades' => $actividades,
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
            'logNames' => Activity::query()->select('log_name')->distinct()->pluck('log_name')->filter()->values(),
            'filtros' => compact('causerId', 'logName', 'desde', 'hasta'),
        ]);
    }

    /**
     * Listado ESCONDIDO de documentos de PRUEBA / piloto / simulación (ambiente 00).
     * No aparece en el listado principal de facturación (que solo muestra producción):
     * su acceso vive aquí, en Auditoría, y solo para administrador/contador. Solo lectura.
     */
    public function documentosPrueba(Request $request): View
    {
        abort_unless($request->user()->can('auditoria.ver'), 403);

        $filtros = [
            'q' => trim((string) $request->input('q', '')),
            'tipo_dte' => $request->input('tipo_dte'),
            'estado' => $request->input('estado'),
        ];

        $dtes = Dte::query()
            ->select('dtes.*')
            ->pruebas() // ambiente 00 (pruebas/piloto/simulación) — nunca producción
            ->addSelect(['ultimo_envio_estado' => DteEnvio::select('estado')
                ->whereColumn('dte_id', 'dtes.id')
                ->latest('id')
                ->limit(1)])
            ->with(['cliente', 'clienteSucursal', 'dteRelacionado.cliente', 'dteRelacionado.clienteSucursal'])
            ->when($filtros['tipo_dte'], fn ($qb, $v) => $qb->where('tipo_dte', $v))
            ->when($filtros['estado'], fn ($qb, $v) => $qb->where('estado', $v))
            ->when($filtros['q'] !== '', function ($qb) use ($filtros) {
                $t = $filtros['q'];
                $qb->where(fn ($w) => $w->where('numero_interno', 'like', "%{$t}%")
                    ->orWhere('numero_control', 'like', "%{$t}%")
                    ->orWhere('numero_orden_compra', 'like', "%{$t}%"));
            })
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('auditoria.documentos-prueba', compact('dtes', 'filtros'));
    }

    /**
     * CCF emitidos a un cliente o sala que exige orden de compra cuya orden no tiene la
     * forma esperada o trae el código de otra sala ({@see ReglaOrdenCompra::problemasConSala()}).
     * Solo lectura: es para revisarlos; la corrección se decide aparte. Se evalúa en PHP con
     * la misma regla que avisa al generar, para que la lista y el aviso nunca discrepen.
     */
    public function ordenesCompra(Request $request): View
    {
        abort_unless($request->user()->can('auditoria.ver'), 403);

        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        // Una sola lectura de los ajustes para toda la lista, no una por CCF.
        $posicionSala = OrdenCompra::posicionSala();

        $revisar = Dte::query()
            ->ambienteOperativoActual()
            ->where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->whereNotIn('estado', [EstadoDte::Borrador->value, EstadoDte::Invalidado->value])
            ->where(fn ($q) => $q->whereHas('cliente', fn ($c) => $c->where('requiere_orden_compra', true))
                ->orWhereHas('clienteSucursal', fn ($s) => $s->where('requiere_orden_compra', true)))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha_emision', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha_emision', '<=', $v))
            ->with(['cliente:id,nombre', 'clienteSucursal:id,nombre,codigo'])
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->lazy(500)
            ->map(fn (Dte $dte) => [
                'dte' => $dte,
                'problemas' => ReglaOrdenCompra::problemasConSala(
                    $dte->numero_orden_compra,
                    $dte->fecha_emision ?? $dte->created_at,
                    $dte->clienteSucursal,
                    $posicionSala,
                ),
            ])
            ->filter(fn (array $fila) => $fila['problemas'] !== [])
            ->values()
            ->all();

        return view('auditoria.ordenes-compra', [
            'revisar' => $revisar,
            'filtros' => ['desde' => $filtros['desde'] ?? null, 'hasta' => $filtros['hasta'] ?? null],
        ]);
    }
}
