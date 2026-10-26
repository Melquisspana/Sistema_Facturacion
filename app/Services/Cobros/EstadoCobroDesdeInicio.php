<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PpqItem;
use App\Support\Dinero;
use App\Support\EjercicioControl;
use Illuminate\Support\Carbon;

/**
 * Estado de cobro de cada CCF y NC del cliente desde el inicio del seguimiento
 * (`cobros.inicio_seguimiento`): pagado, con diferencia, no pagado o en revisión, y si
 * cada NC se descontó. Solo lee. Lo usan la puesta al día y su Excel.
 */
class EstadoCobroDesdeInicio
{
    /** @return array{inicio: ?Carbon, ccf: array<int, array<string, mixed>>, nc: array<int, array<string, mixed>>, no_aplican: array<int, array<string, mixed>>, totales: array<string, mixed>} */
    public function generar(Cliente $cliente): array
    {
        $inicio = config('cobros.inicio_seguimiento') ? Carbon::parse(config('cobros.inicio_seguimiento'))->startOfDay() : null;
        $documentos = CobroDocumento::deCliente($cliente->id)->with('eventos')->get();
        $ccfs = CobroDocumento::deCliente($cliente->id)->where('tipo_dte', '03')
            ->when($inicio, fn ($q) => $q->whereDate('fecha_emision', '>=', $inicio))->with('eventos')->porAntiguedad()->get();
        $notas = Dte::where('cliente_id', $cliente->id)->where('tipo_dte', '05')->where('estado', 'aceptado')
            ->where(fn ($q) => $q->whereNull('sello_invalidacion')->orWhere('sello_invalidacion', '')->orWhereRaw('UPPER(sello_invalidacion) LIKE ?', ['MOCK%']))
            ->when($inicio, fn ($q) => $q->whereDate('fecha_emision', '>=', $inicio))->with('dteRelacionado')->orderBy('fecha_emision')->orderBy('id')->get();
        $items = PpqItem::whereHas('lote', fn ($q) => $q->where('cliente_id', $cliente->id))->with('lote')->get();
        $porDte = $items->whereNotNull('dte_id')->groupBy('dte_id');
        $porNumero = $items->groupBy(fn ($i) => EjercicioControl::llave($i->numeroNormalizado(), $i->ejercicio_control));
        $itemsDe = fn ($modelo) => $porDte->get($modelo instanceof Dte ? $modelo->id : $modelo->dte_id, collect())
            ->merge($porNumero->get(EjercicioControl::llave($modelo->numero_control, EjercicioControl::de($modelo->fecha_emision)), collect()))->unique('id');
        $txtDe = fn ($doc) => $doc ? $doc->eventos->filter(fn ($e) => $e->tipo === TipoEventoCobro::Pago && $e->estado === EstadoEventoCobro::Aplicado)
            ->pluck('evidencia_nombre')->filter()->unique()->values()->all() : [];
        $motivos = app(NoAplicaCobro::class)->motivosPorDte($cliente);
        $no_aplican = [];
        $ccf = [];
        foreach ($ccfs as $doc) {
            if (isset($motivos[$doc->dte_id])) {
                $no_aplican[] = ['tipo' => 'CCF', 'numero' => $doc->numero_control, 'fecha' => $doc->fecha_emision, 'monto' => $doc->monto, 'motivo' => $motivos[$doc->dte_id]];

                continue;
            }
            // La misma regla que la ficha y el estado de pago: el CCF menos sus NC aceptadas.
            $efectivo = $doc->facturadoEfectivo();
            $estado = $doc->eventos->contains(fn ($e) => $e->estado === EstadoEventoCobro::EnRevision) ? 'en_revision' : match ($doc->pago_estado) {
                EstadoPagoCobro::Pagado => 'pagado',
                EstadoPagoCobro::Parcial, EstadoPagoCobro::Diferencia => 'diferencia',
                default => 'no_pagado',
            };
            $ccf[] = ['documento' => $doc, 'estado' => $estado, 'a_cobrar' => $efectivo, 'cobrado' => $doc->monto_pagado,
                'diferencia' => $doc->saldo(), 'txt' => $txtDe($doc), 'ppq' => $itemsDe($doc)->pluck('lote.referencia')->unique()->values()->all()];
        }
        $nc = [];
        foreach ($notas as $nota) {
            if (isset($motivos[$nota->id])) {
                $no_aplican[] = ['tipo' => 'NC', 'numero' => $nota->numero_control, 'fecha' => $nota->fecha_emision, 'monto' => $nota->total_pagar, 'motivo' => $motivos[$nota->id]];

                continue;
            }
            $doc = $documentos->firstWhere('dte_id', $nota->id);
            $deNota = $itemsDe($nota);
            $estado = $nota->tipo_nota_credito === TipoNotaCredito::ProntoPago ? 'pronto_pago'
                : (($deNota->contains('conciliacion_estado', 'aplicada') || ($doc && Dinero::comparar($doc->monto_pagado ?? '0', '0') > 0)) ? 'descontada' : 'no_descontada');
            $nc[] = ['nc' => $nota, 'ccf' => $nota->dteRelacionado?->numero_control, 'estado' => $estado, 'txt' => $txtDe($doc), 'ppq' => $deNota->pluck('lote.referencia')->unique()->values()->all()];
        }
        $totales = ['ccf' => array_replace(array_fill_keys(['pagado', 'diferencia', 'no_pagado', 'en_revision'], 0), collect($ccf)->countBy('estado')->all()),
            'nc' => array_replace(array_fill_keys(['pronto_pago', 'descontada', 'no_descontada'], 0), collect($nc)->countBy('estado')->all())];

        $totales['no_aplican'] = count($no_aplican);

        return compact('inicio', 'ccf', 'nc', 'no_aplican', 'totales');
    }
}
