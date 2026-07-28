<?php

namespace Tests\Unit\Planta;

use App\Enums\Planta\TipoMovimientoPlanta;
use Tests\TestCase;

/**
 * El libro mayor debe permitir separar lo que pasó FÍSICAMENTE de lo que se
 * corrigió CONTABLEMENTE. Estas pruebas fijan esa separación.
 *
 * El caso crítico: reversar un traslado ya recibido se registra con el par
 * simplificado destino -> origen, que se parece a un traslado normal en sentido
 * inverso. Tiparlo como `traslado_*` haría que la pregunta «cuánto viajó de
 * verdad» devolviera una cifra inflada por las correcciones.
 */
class TipoMovimientoPlantaTest extends TestCase
{
    public function test_los_tipos_de_reversion_se_identifican_por_su_prefijo(): void
    {
        foreach (TipoMovimientoPlanta::cases() as $tipo) {
            $this->assertSame(
                str_starts_with($tipo->value, 'reversion_'),
                $tipo->esReversion(),
                "El tipo {$tipo->value} no clasifica su condición de reversión de forma coherente con su valor."
            );
        }
    }

    public function test_existen_las_cinco_reversiones_del_plan(): void
    {
        $this->assertEqualsCanonicalizing([
            'reversion_recepcion',
            'reversion_traslado_envio',
            'reversion_traslado_recepcion',
            'reversion_ajuste',
            'reversion_cambio_disponibilidad',
        ], array_map(fn (TipoMovimientoPlanta $t) => $t->value, TipoMovimientoPlanta::reversiones()));
    }

    public function test_la_reversion_de_un_traslado_recibido_no_es_un_traslado(): void
    {
        $reversion = TipoMovimientoPlanta::ReversionTrasladoRecepcion;

        $this->assertTrue($reversion->esReversion());

        // Aunque su par de movimientos sea destino -> origen, NO es recorrido
        // físico: es compensación contable y no debe contarse como transporte.
        $this->assertFalse($reversion->esTrasladoFisico());
        $this->assertNotContains($reversion, TipoMovimientoPlanta::trasladosFisicos());
    }

    public function test_solo_el_envio_y_la_recepcion_cuentan_como_traslado_fisico(): void
    {
        $this->assertEqualsCanonicalizing(
            ['traslado_envio', 'traslado_recepcion'],
            array_map(fn (TipoMovimientoPlanta $t) => $t->value, TipoMovimientoPlanta::trasladosFisicos())
        );
    }

    public function test_ningun_tipo_es_a_la_vez_traslado_fisico_y_reversion(): void
    {
        foreach (TipoMovimientoPlanta::cases() as $tipo) {
            $this->assertFalse(
                $tipo->esTrasladoFisico() && $tipo->esReversion(),
                "El tipo {$tipo->value} no puede ser recorrido físico y compensación a la vez."
            );
        }
    }

    public function test_los_tipos_operativos_no_se_clasifican_como_reversion(): void
    {
        foreach ([
            TipoMovimientoPlanta::Recepcion,
            TipoMovimientoPlanta::TrasladoEnvio,
            TipoMovimientoPlanta::TrasladoRecepcion,
            TipoMovimientoPlanta::Ajuste,
            TipoMovimientoPlanta::CargaInicial,
            TipoMovimientoPlanta::CambioDisponibilidad,
        ] as $tipo) {
            $this->assertFalse($tipo->esReversion(), "El tipo {$tipo->value} no es una reversión.");
        }
    }

    public function test_toda_reversion_tiene_su_contraparte_operativa(): void
    {
        // Cada compensación deshace algo concreto: si existe la reversión, debe
        // existir el tipo normal correspondiente.
        foreach (TipoMovimientoPlanta::reversiones() as $reversion) {
            $origen = substr($reversion->value, strlen('reversion_'));

            $this->assertNotNull(
                TipoMovimientoPlanta::tryFrom($origen),
                "La reversión {$reversion->value} no tiene tipo operativo `{$origen}` al que corresponder."
            );
        }
    }

    public function test_todas_las_reversiones_comparten_distintivo_visual(): void
    {
        foreach (TipoMovimientoPlanta::reversiones() as $reversion) {
            $this->assertSame('rose', $reversion->color(), "La reversión {$reversion->value} debería distinguirse visualmente.");
        }
    }
}
