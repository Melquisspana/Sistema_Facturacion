<?php

namespace App\Http\Controllers\Ppq;

use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agrega/quita CCF o NC de un lote PPQ, con control de duplicados:
 *  - No permite el mismo CCF/NC dos veces en el lote (unique en BD + chequeo amable).
 *  - Avisa si el CCF/NC ya está usado en otro lote.
 *  - Avisa si el albarán ya fue vinculado antes.
 */
class PpqItemController extends Controller
{
    public function store(Request $request, PpqLote $lote): RedirectResponse
    {
        if (! $lote->esEditable()) {
            return back()->with('error', 'El lote está en estado '.$lote->estado->label().' y no admite cambios.');
        }

        // Documento que viene de Gmail (no está en la BD local): se snapshotea.
        if ($request->input('origen') === 'gmail') {
            return $this->agregarDesdeGmail($request, $lote);
        }

        $datos = $request->validate([
            'dte_id' => ['required', Rule::exists('dtes', 'id')->whereIn('tipo_dte', ['03', '05'])],
            'ppq_albaran_id' => ['nullable', Rule::exists('ppq_albaranes', 'id')],
            // Albarán capturado a mano (flujo NC): número/fecha/monto/observaciones.
            'numero_albaran' => ['nullable', 'string'],
            'fecha_albaran' => ['nullable', 'string'],
            'monto_albaran' => ['nullable', 'numeric'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        // Anti-duplicado dentro del lote.
        if ($lote->items()->where('dte_id', $datos['dte_id'])->exists()) {
            return back()->with('error', 'Ese CCF/NC ya está en este lote.');
        }

        // Aviso: ya usado en otro lote (no bloquea, informa).
        $otroLote = PpqItem::where('dte_id', $datos['dte_id'])
            ->where('ppq_lote_id', '!=', $lote->id)
            ->value('ppq_lote_id');

        $dte = Dte::findOrFail($datos['dte_id']);
        $esNc = $dte->tipo_dte?->value === '05';

        // "Agregar sin albarán": se incluye el CCF/NC dejando vacíos los datos del
        // albarán (notas de crédito / casos especiales). Marca explícita en el item.
        $sinAlbaran = $request->boolean('sin_albaran');

        $albaran = null;
        $avisoAlbaran = null;
        if (! $sinAlbaran && ! empty($datos['ppq_albaran_id'])) {
            $albaran = PpqAlbaran::find($datos['ppq_albaran_id']);
            if ($albaran && $albaran->yaVinculado()) {
                $avisoAlbaran = 'El albarán '.$albaran->numero_albaran.' ya estaba vinculado a otro item.';
            }
        } elseif (! $sinAlbaran && filled($datos['numero_albaran'] ?? null)) {
            // Albarán manual (NC): registra/reusa por número + OC del documento.
            $albaran = $this->registrarAlbaran($datos + ['numero_orden_compra' => $dte->numero_orden_compra], $esNc ? 'manual' : 'gmail');
        }

        $salaCodigo = \App\Support\OrdenCompra::salaDesde($dte->numero_orden_compra);
        $salaNombre = \App\Support\Sala::nombrePreferido($salaCodigo, $dte->clienteSucursal?->nombre);
        // Enriquecer el mapa auxiliar de PPQ (no fiscal) para futuros documentos de esta sala.
        \App\Models\PpqSala::recordar($salaCodigo, $salaNombre, 'local');

        $lote->items()->create([
            'dte_id' => $dte->id,
            'origen' => 'local',
            'numero_control' => $dte->numero_control,
            'codigo_generacion' => $dte->codigo_generacion,
            'sello_recepcion' => $dte->sello_recepcion,
            'tipo_dte' => $dte->tipo_dte?->value,
            'fecha_documento' => $dte->fecha_emision,
            'ppq_albaran_id' => $albaran?->id,
            'sin_albaran' => $sinAlbaran || $albaran === null,
            'numero_orden_compra' => $dte->numero_orden_compra,
            'sala_nombre' => $salaNombre,
            'monto_dte' => $dte->total_pagar,
            'monto_albaran' => $albaran?->monto_albaran,
            'observaciones' => $datos['observaciones'] ?? null,
        ]);

        $tipoTxt = $esNc ? 'NC (resta)' : 'CCF';
        $mensaje = $albaran === null ? $tipoTxt.' agregado al lote sin albarán.' : $tipoTxt.' agregado al lote.';
        if ($otroLote) {
            $mensaje .= ' Aviso: ya estaba usado en el lote #'.$otroLote.'.';
        }
        if ($avisoAlbaran) {
            $mensaje .= ' '.$avisoAlbaran;
        }

        return back()->with('status', $mensaje);
    }

    /** Agrega al lote un CCF/NC resuelto desde Gmail (snapshot, sin DTE local). */
    private function agregarDesdeGmail(Request $request, PpqLote $lote): RedirectResponse
    {
        $d = $request->validate([
            'numero_control' => ['required', 'string', 'max:40'],
            'codigo_generacion' => ['nullable', 'string', 'max:40'],
            'sello_recepcion' => ['nullable', 'string'],
            'tipo_dte' => ['nullable', 'string', 'max:2'],
            'fecha_documento' => ['nullable', 'date'],
            'numero_orden_compra' => ['nullable', 'string'],
            'monto_dte' => ['nullable', 'numeric'],
            'monto_albaran' => ['nullable', 'numeric'],
            'numero_albaran' => ['nullable', 'string'],
            'fecha_albaran' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'gmail_message_id' => ['nullable', 'string'],
            'sala_nombre' => ['nullable', 'string', 'max:255'],
        ]);

        if ($lote->items()->where('numero_control', $d['numero_control'])->exists()) {
            return back()->with('error', 'Ese CCF/NC ya está en este lote.');
        }
        $otroLote = PpqItem::where('numero_control', $d['numero_control'])
            ->where('ppq_lote_id', '!=', $lote->id)->value('ppq_lote_id');

        // "Agregar sin albarán": incluye el CCF/NC dejando vacíos los datos del
        // albarán, aunque haya uno encontrado o esté incompleto (NC/casos especiales).
        $sinAlbaran = $request->boolean('sin_albaran');
        // En la NC el albarán se captura a MANO (no llega por correo); se registra
        // con origen 'manual'. El CCF reusa el albarán parseado de Gmail.
        $esNc = ($d['tipo_dte'] ?? null) === '05';

        // Albarán: registra/reusa por número (si vino y no se pidió "sin albarán").
        $albaran = null;
        if (! $sinAlbaran && filled($d['numero_albaran'] ?? null)) {
            $albaran = $this->registrarAlbaran($d, $esNc ? 'manual' : 'gmail');
        }

        // Nombre de sala: el que ya resolvió la búsqueda (viene en el form) o, si no, se busca
        // el DTE local (el CCF lo emite este sistema) por código de generación, control u OC.
        $salaNombre = $d['sala_nombre'] ?? null;
        if (blank($salaNombre)) {
            $salaNombre = app(\App\Services\Ppq\SalaResolver::class)->nombre(
                $d['numero_orden_compra'] ?? null,
                $d['codigo_generacion'] ?? null,
                $d['numero_control'],
            );
        }
        // Enriquecer el mapa auxiliar de PPQ (no fiscal). Si el nombre vino del formulario
        // (revisado por quien concilia) se marca 'manual' para que no lo pise una fuente auto.
        \App\Models\PpqSala::recordar(
            \App\Support\OrdenCompra::salaDesde($d['numero_orden_compra'] ?? null),
            $salaNombre,
            filled($d['sala_nombre'] ?? null) ? 'manual' : 'gmail',
        );

        $lote->items()->create([
            'origen' => 'gmail',
            'numero_control' => $d['numero_control'],
            'codigo_generacion' => $d['codigo_generacion'] ?? null,
            'sello_recepcion' => $d['sello_recepcion'] ?? null,
            'tipo_dte' => $d['tipo_dte'] ?? null,
            'fecha_documento' => $d['fecha_documento'] ?? null,
            'gmail_message_id' => $d['gmail_message_id'] ?? null,
            'ppq_albaran_id' => $albaran?->id,
            'sin_albaran' => $sinAlbaran || $albaran === null,
            'numero_orden_compra' => $d['numero_orden_compra'] ?? null,
            'sala_nombre' => $salaNombre,
            'monto_dte' => $d['monto_dte'] ?? 0,
            'monto_albaran' => $albaran?->monto_albaran,
            'observaciones' => $d['observaciones'] ?? null,
        ]);

        $tipoTxt = $esNc ? 'NC (resta)' : 'CCF';
        $msg = $albaran === null
            ? $tipoTxt.' agregado al PPQ sin albarán.'
            : $tipoTxt.' agregado al PPQ con albarán '.$albaran->numero_albaran.'.';
        if ($otroLote) {
            $msg .= ' Aviso: ya estaba usado en el lote #'.$otroLote.'.';
        }

        return back()->with('status', $msg);
    }

    /**
     * Registra (o reusa) un albarán por número + OC. `$origen` = 'gmail' (parseado del
     * correo) o 'manual' (capturado a mano para una NC). La fecha se normaliza d/m/Y.
     */
    private function registrarAlbaran(array $d, string $origen): PpqAlbaran
    {
        $albaran = PpqAlbaran::firstOrCreate(
            [
                'numero_albaran' => \App\Support\Albaran::numeroLimpio($d['numero_albaran']),
                'numero_orden_compra' => $d['numero_orden_compra'] ?? null,
            ],
            [
                'monto_albaran' => $d['monto_albaran'] ?? null,
                'fecha_albaran' => \App\Support\Albaran::fecha($d['fecha_albaran'] ?? null),
                'origen' => $origen,
                'gmail_message_id' => $d['gmail_message_id'] ?? null,
            ],
        );

        // Autocorrección: el registro YA existía (mismo número+OC) pero quedó SIN
        // monto — típicamente porque una corrida anterior del parser no pudo
        // extraerlo (ej. un bug ya corregido). Si esta vez sí se resolvió un monto,
        // se completa. NUNCA pisa un monto ya guardado (evita sorprender con un
        // valor distinto si un reparseo posterior da otra cosa).
        if ($albaran->monto_albaran === null && filled($d['monto_albaran'] ?? null)) {
            $albaran->update(['monto_albaran' => $d['monto_albaran']]);
        }

        return $albaran;
    }

    public function destroy(PpqLote $lote, PpqItem $item): RedirectResponse
    {
        abort_unless($item->ppq_lote_id === $lote->id, 404);

        if (! $lote->esEditable()) {
            return back()->with('error', 'El lote está en estado '.$lote->estado->label().' y no admite cambios.');
        }

        $item->delete();

        return back()->with('status', 'Item quitado del lote.');
    }
}
