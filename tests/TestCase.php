<?php

namespace Tests;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Dte\DteStateMachine;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Deja un CCF ACEPTADO REALMENTE por Hacienda (numeración oficial, sello real y
     * fecha_procesamiento_mh), como exige la regla de negocio para crear notas de crédito
     * (Dte::aceptadoRealmentePorMh()). NO usa sello mock: representa una aceptación real del MH.
     */
    protected function aceptarCcf(Dte $ccf): Dte
    {
        if ($ccf->estado === EstadoDte::Borrador) {
            app(DteStateMachine::class)->transicionar($ccf, EstadoDte::Generado);
        }

        $ccf->numero_control = $ccf->numero_control ?: ('DTE-03-M001P001-'.str_pad((string) $ccf->id, 15, '0', STR_PAD_LEFT));
        $ccf->codigo_generacion = $ccf->codigo_generacion ?: strtoupper((string) Str::uuid());
        $ccf->sello_recepcion = '2026'.strtoupper(Str::random(36)); // sello realista (no mock)
        $ccf->fecha_procesamiento_mh = now();
        $ccf->estado = EstadoDte::Aceptado;
        $ccf->save();

        return $ccf->refresh();
    }
}
