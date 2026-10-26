<?php

namespace App\Services\Cobros;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Support\Dinero;

/**
 * Lo que ya no se cobra y por eso no se mezcla con lo «No pagado»: CCF invalidado y sin
 * cobro, CCF que su NC deja en saldo 0 (anulado con nota), CCF del ambiente de pruebas al
 * operar en producción, y las NC de esos CCF. Un CCF invalidado CON cobro no entra: hay que
 * mirarlo. Lo usan el estado de cobro y los pendientes del TXT. Solo lee.
 */
class NoAplicaCobro
{
    /** @return array<int, string> Motivos por DTE, incluidas las NC de esos CCF. */
    public function motivosPorDte(Cliente $cliente): array
    {
        $documentos = CobroDocumento::deCliente($cliente->id)->where('tipo_dte', '03')->whereNotNull('dte_id')
            ->get(['id', 'dte_id', 'monto_pagado'])->keyBy('dte_id');
        $saldados = CobroDocumento::idsSaldadosConNc($cliente->id);
        $motivos = [];
        $dtes = Dte::where('cliente_id', $cliente->id)->whereIn('tipo_dte', ['03', '05'])
            ->get(['id', 'tipo_dte', 'estado', 'ambiente', 'dte_relacionado_id']);
        foreach ($dtes->where('tipo_dte', '03') as $dte) {
            $doc = $documentos->get($dte->id);
            if ($dte->estado === EstadoDte::Invalidado && Dinero::comparar($doc?->monto_pagado ?? '0', '0') === 0) {
                $motivos[$dte->id] = 'Invalidado ante Hacienda';
            } elseif ($doc && in_array($doc->id, $saldados, true)) {
                $motivos[$dte->id] = 'Anulado con nota de crédito (saldo 0)';
            } elseif (config('dte.ambiente') === '01' && $dte->ambiente === AmbienteHacienda::Pruebas) {
                $motivos[$dte->id] = 'Documento del ambiente de pruebas';
            }
        }
        foreach ($dtes->where('tipo_dte', '05') as $nota) {
            if (isset($motivos[$nota->dte_relacionado_id])) {
                $motivos[$nota->id] = 'Nota de crédito de un CCF que no aplica ('.$motivos[$nota->dte_relacionado_id].')';
            }
        }

        return $motivos;
    }
}
