<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Http\Controllers\Controller;
use App\Models\Contingencia;
use App\Models\ContingenciaEvento;
use App\Models\Dte;
use App\Models\LoteDte;
use App\Services\Dte\ContingenciaEventoService;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\LoteDteService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContingenciaController extends Controller
{
    use AuthorizesRequests;

    public function pendientes(LoteDteService $service, ContingenciaEventoService $eventos): View
    {
        $this->authorize('contingencia', Dte::class);

        return view('facturacion.contingencia-pendientes', [
            'paraRevision' => app(ContingenciaService::class)->documentosParaRevision()
                ->with('cliente')->orderBy('fecha_emision')->orderBy('hora_emision')->paginate(50, ['*'], 'revision'),
            'documentos' => Schema::hasTable('lotes_dte')
                ? Dte::whereNotNull('contingencia_id')->with(['cliente', 'contingencia', 'contingenciaEvento', 'loteDte'])->orderByDesc('id')->paginate(50)
                : collect(),
            // Los rechazos siguen visibles aunque sus documentos ya hayan sido liberados.
            'lotesConError' => Schema::hasTable('lotes_dte')
                ? LoteDte::where('estado', 'error')->with('contingenciaEvento')->orderByDesc('id')->paginate(10, ['*'], 'lotes_error')
                : collect(),
            'service' => $service, 'eventos' => $eventos,
        ]);
    }

    public function enviarLotes(ContingenciaEvento $evento, LoteDteService $service): RedirectResponse
    {
        $this->authorize('contingencia', Dte::class);
        $resultados = $this->conBloqueosVisibles(fn () => $service->enviar($evento));

        return back()->with('status', collect($resultados)->pluck('resultado')->implode(' | '));
    }

    public function consultarLote(Request $request, LoteDte $lote, LoteDteService $service): RedirectResponse
    {
        $this->authorize('contingencia', Dte::class);

        // «Volver a consultar en Hacienda»: un lote ya cerrado que dejó rechazados.
        return back()->with('status', $this->conBloqueosVisibles(fn () => $service->consultar($lote, $request->boolean('reconciliar')))['resultado']);
    }

    /**
     * La integración apagada o un candado de seguridad no son un error del servidor: la
     * persona ve su mensaje, igual que los demás motivos por los que no se envió nada.
     */
    private function conBloqueosVisibles(callable $accion): mixed
    {
        try {
            return $accion();
        } catch (DteTransmisionDeshabilitadaException|DteFirmaDeshabilitadaException $e) {
            throw ValidationException::withMessages(['contingencia' => $e->getMessage()]);
        }
    }

    public function emitirCorregido(Request $request, Dte $dte, DteBorradorService $borradores): RedirectResponse
    {
        $this->authorize('contingencia', Dte::class);
        $this->authorize('create', Dte::class);
        abort_unless($dte->contingencia_id && $dte->estado === EstadoDte::Rechazado && $dte->tipo_dte === TipoDte::CreditoFiscal, 422);
        $nuevo = $borradores->duplicarCcf($dte, $request->user());

        return to_route('facturacion.edit', $nuevo);
    }

    public function show(Contingencia $contingencia, ContingenciaEventoService $service): View
    {
        $this->authorize('contingencia', Dte::class);

        return view('facturacion.contingencia', [
            'contingencia' => $contingencia, 'partes' => $service->partesVigentes($contingencia),
            'eventos' => $contingencia->eventos()->orderBy('id')->get(), 'service' => $service,
        ]);
    }

    public function enviar(Request $request, Contingencia $contingencia, ContingenciaEventoService $service): RedirectResponse
    {
        $this->authorize('contingencia', Dte::class);
        // La casilla «Ya verifiqué en Hacienda»: reemplazar una parte que quedó en duda.
        $resultados = $this->conBloqueosVisibles(fn () => $service->enviar($contingencia, $request->boolean('reemplazar_incierto')));

        return to_route('facturacion.contingencia.show', $contingencia)
            ->with('status', collect($resultados)->map(fn ($r) => $r['resultado'].': '.($r['mensaje'] ?? ''))->implode(' | '));
    }

    public function actualizar(Request $request, Contingencia $contingencia, ContingenciaService $service): RedirectResponse
    {
        $this->authorize('contingencia', Dte::class);
        $service->corregir($contingencia, $request->only(['tipo', 'motivo', 'cese']), $request->user());

        return back()->with('status', 'Datos de contingencia corregidos.');
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
