<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Services\Dte\ContingenciaEventoService;
use App\Services\Dte\ContingenciaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContingenciaController extends Controller
{
    use AuthorizesRequests;

    public function show(Contingencia $contingencia, ContingenciaEventoService $service): View
    {
        $this->authorize('contingencia', Dte::class);

        return view('facturacion.contingencia', [
            'contingencia' => $contingencia, 'partes' => $service->partesVigentes($contingencia),
            'eventos' => $contingencia->eventos()->orderBy('id')->get(), 'service' => $service,
        ]);
    }

    public function enviar(Contingencia $contingencia, ContingenciaEventoService $service): RedirectResponse
    {
        $this->authorize('contingencia', Dte::class);
        $resultados = $service->enviar($contingencia);

        return to_route('facturacion.contingencia.show', $contingencia)
            ->with('status', collect($resultados)->map(fn ($r) => $r['resultado'].': '.($r['mensaje'] ?? ''))->implode(' | '));
    }

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
