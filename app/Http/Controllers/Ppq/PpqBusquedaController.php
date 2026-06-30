<?php

namespace App\Http\Controllers\Ppq;

use App\Enums\EstadoPpq;
use App\Http\Controllers\Controller;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Services\Ppq\PpqBusquedaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Búsqueda rápida de CCF/NC para PPQ: por últimos 4 dígitos, orden de compra,
 * albarán, sala, fecha o monto. Solo consulta; desde aquí se agregan a un lote.
 */
class PpqBusquedaController extends Controller
{
    public function index(Request $request, PpqBusquedaService $busqueda, \App\Services\Ppq\PpqGmailService $gmail, \App\Services\Ppq\SalaResolver $salaResolver): View
    {
        $filtros = $request->only(['q', 'oc', 'albaran', 'sala', 'fecha_desde', 'fecha_hasta', 'monto', 'tipo']);
        $q = trim((string) ($filtros['q'] ?? ''));

        // Búsqueda POR TIPO de documento (no se mezclan): por defecto CCF (03). El usuario
        // primero agrega todos los CCF y luego cambia a Nota de crédito (05) para agregar las NC.
        $tipo = in_array($filtros['tipo'] ?? null, ['03', '05'], true) ? $filtros['tipo'] : '03';
        $filtros['tipo'] = $tipo;
        $hayFiltros = collect($filtros)->except('tipo')->filter(fn ($v) => filled($v))->isNotEmpty();

        // Fuente PRINCIPAL: Gmail (correos enviados). Si no está conectado, se cae a
        // la BD local como respaldo (puede no tener todos los documentos).
        $gmailDisponible = $gmail->disponible();
        $resolucion = ($gmailDisponible && $q !== '') ? $gmail->resolverCcf($q) : null;
        $fichasGmail = $resolucion['fichas'] ?? null;
        $gmailDebug = $resolucion['debug'] ?? null;

        // Solo el tipo elegido: aunque un CCF y una NC compartan correlativo, no se mezclan.
        if (is_array($fichasGmail)) {
            $fichasGmail = array_values(array_filter(
                $fichasGmail,
                fn ($f) => (($f['ccf']['tipoDte'] ?? '03') === $tipo),
            ));
        }

        // Para las NC: sugerir el CCF original que comparte la misma orden de compra.
        if (is_array($fichasGmail)) {
            foreach ($fichasGmail as $i => $f) {
                if (($f['ccf']['tipoDte'] ?? null) === '05') {
                    $fichasGmail[$i]['ccfRelacionado'] = $this->ccfRelacionadoPorOc($f['ccf']['ordenCompra'] ?? null);
                }
            }
            // Nombre comercial de la sala: viene en el propio DTE (receptor.nombreComercial).
            // Si por alguna razón no estuviera, se cae al DTE local por código/control/OC.
            foreach ($fichasGmail as $i => $f) {
                if (blank($f['ccf']['salaNombre'] ?? null)) {
                    $fichasGmail[$i]['ccf']['salaNombre'] = $salaResolver->nombre(
                        $f['ccf']['ordenCompra'] ?? null,
                        $f['ccf']['codigoGeneracion'] ?? null,
                        $f['ccf']['numeroControl'] ?? null,
                    );
                }
            }
        }

        $resultados = (! $gmailDisponible && $hayFiltros) ? $busqueda->buscar($filtros) : null;

        // Para avisar duplicados: qué DTE de los resultados ya está en algún lote.
        $yaUsados = $resultados
            ? $busqueda->dtesYaUsados($resultados->pluck('id')->all())
            : [];

        // Albaranes vinculados a los resultados (por dte_id directo o por OC).
        [$albaranesPorDte, $albaranesPorOc] = $this->albaranesDe($resultados);

        // ¿Hay filtros avanzados aplicados? (para abrir el panel avanzado si sí).
        $hayAvanzados = collect($filtros)->except('q')->filter(fn ($v) => filled($v))->isNotEmpty();

        // Lotes editables a los que se puede agregar (borrador/listo).
        $lotesAbiertos = $this->lotesAbiertos();

        // Lote ACTIVO: al llegar desde un lote (?lote=ID) se agrega DIRECTO a él (sin elegir
        // de la lista). Debe existir y ser editable; si no, se ignora (cae al flujo normal).
        $loteActivo = null;
        if ($request->filled('lote')) {
            $candidato = PpqLote::find($request->integer('lote'));
            $loteActivo = ($candidato && $candidato->esEditable()) ? $candidato : null;
        }

        return view('ppq.busqueda', [
            'filtros' => $filtros,
            'tipo' => $tipo,
            'resultados' => $resultados,
            'fichasGmail' => $fichasGmail,
            'gmailDebug' => $gmailDebug,
            'gmailDisponible' => $gmailDisponible,
            'gmailConfigurado' => app(\App\Services\Ppq\GmailClient::class)->configurado(),
            'yaUsados' => $yaUsados,
            'albaranesPorDte' => $albaranesPorDte,
            'albaranesPorOc' => $albaranesPorOc,
            'hayAvanzados' => $hayAvanzados,
            'lotesAbiertos' => $lotesAbiertos,
            'loteActivo' => $loteActivo,
        ]);
    }

    /**
     * Búsqueda MANUAL de albarán por fecha (cuando no se encontró por OC): lista los
     * albaranes del label Calleja_Albaranes recibidos ese día para que el usuario
     * elija el correcto y lo vincule al documento. Conserva el contexto del CCF/NC.
     */
    public function albaranesPorFecha(Request $request, \App\Services\Ppq\PpqGmailService $gmail): View
    {
        // Contexto del documento que se está conciliando (se reenvía a "agregar").
        $doc = $request->only([
            'origen', 'dte_id', 'numero_control', 'codigo_generacion', 'sello_recepcion',
            'tipo_dte', 'fecha_documento', 'numero_orden_compra', 'monto_dte', 'gmail_message_id', 'q',
        ]);

        $fecha = $request->input('fecha') ?: ($doc['fecha_documento'] ?? null);
        $fechaValida = $fecha ? rescue(fn () => \Illuminate\Support\Carbon::parse($fecha)->toDateString(), null, false) : null;

        $gmailDisponible = $gmail->disponible();
        $candidatos = ($gmailDisponible && $fechaValida) ? $gmail->albaranesDeFecha($fechaValida) : null;

        return view('ppq.albaran-por-fecha', [
            'doc' => $doc,
            'fecha' => $fechaValida,
            'candidatos' => $candidatos,
            'gmailDisponible' => $gmailDisponible,
            'lotesAbiertos' => $this->lotesAbiertos(),
        ]);
    }

    /** Lotes editables (borrador/listo) a los que se puede agregar documentos. */
    private function lotesAbiertos()
    {
        return PpqLote::whereIn('estado', [EstadoPpq::Borrador->value, EstadoPpq::Listo->value])
            ->latest()
            ->get(['id', 'referencia', 'fecha', 'estado']);
    }

    /**
     * Número de control del CCF (tipo 03) que comparte la orden de compra de una NC,
     * para mostrar la relación sugerida. Null si no hay uno emitido localmente.
     */
    private function ccfRelacionadoPorOc(?string $oc): ?string
    {
        if (blank($oc)) {
            return null;
        }

        return \App\Models\Dte::where('tipo_dte', '03')
            ->where('numero_orden_compra', $oc)
            ->orderByDesc('id')
            ->value('numero_control');
    }

    /**
     * @return array{0: array<int, PpqAlbaran>, 1: array<string, PpqAlbaran>}
     */
    private function albaranesDe($resultados): array
    {
        $porDte = [];
        $porOc = [];
        if (! $resultados || $resultados->isEmpty()) {
            return [$porDte, $porOc];
        }
        $ids = $resultados->pluck('id')->all();
        $ocs = $resultados->pluck('numero_orden_compra')->filter()->all();

        $albaranes = PpqAlbaran::where(function (Builder $q) use ($ids, $ocs) {
            $q->whereIn('dte_id', $ids);
            if ($ocs !== []) {
                $q->orWhereIn('numero_orden_compra', $ocs);
            }
        })->get();

        foreach ($albaranes as $alb) {
            if ($alb->dte_id) {
                $porDte[$alb->dte_id] = $alb;
            }
            if ($alb->numero_orden_compra && ! isset($porOc[$alb->numero_orden_compra])) {
                $porOc[$alb->numero_orden_compra] = $alb;
            }
        }

        return [$porDte, $porOc];
    }
}
