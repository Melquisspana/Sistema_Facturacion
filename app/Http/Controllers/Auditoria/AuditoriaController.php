<?php

namespace App\Http\Controllers\Auditoria;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class AuditoriaController extends Controller
{
    public function index(Request $request): View
    {
        // Solo administrador y contador pueden ver la auditoría.
        abort_unless($request->user()->hasAnyRole(['administrador', 'contador']), 403);

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
}
