<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Services\Dte\ContingenciaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContingenciaController extends Controller
{
    use AuthorizesRequests;

    public function activar(Request $request, ContingenciaService $service): RedirectResponse
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        $this->authorize('contingencia', Dte::class);
        $datos = $request->validate([
            'tipo' => ['required', 'integer', 'between:1,5'],
            'motivo' => ['nullable', 'required_if:tipo,5', 'string', 'max:500'],
        ]);
        $service->activar((int) $datos['tipo'], $datos['motivo'] ?? null, 'manual', $request->user());

        return back()->with('status', 'Modo contingencia activado. Los documentos se firmarán sin transmitir al MH.');
    }

    public function terminar(Request $request, ContingenciaService $service): RedirectResponse
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        $this->authorize('contingencia', Dte::class);
        $service->terminar($request->user());

        return back()->with('status', 'Contingencia terminada. Los documentos transitorios siguen pendientes de regularización.');
    }
}
