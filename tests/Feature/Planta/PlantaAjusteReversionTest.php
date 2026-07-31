<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\AjusteInvalidoException;
use App\Exceptions\Planta\ReversionAjusteImposibleException;
use App\Models\Planta\PlantaAjuste;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reversión de un ajuste confirmado.
 *
 * La regla del módulo entero: NO se edita ni se borra lo ya escrito en el mayor.
 * Reversar crea un documento NUEVO cuyos movimientos son el efecto contrario,
 * apuntando uno a uno al movimiento que compensan. El original se queda donde
 * está, marcado como reversado.
 *
 * Se compensa MOVIMIENTO a movimiento y no línea a línea, porque una corrección
 * de conteo cuya diferencia fue cero no generó movimiento: no hay nada que
 * deshacer en esa línea.
 */
class PlantaAjusteReversionTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    public function test_reversar_un_ajuste_positivo_devuelve_el_saldo_original(): void
    {
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        $this->assertSame('600.0000', $this->saldo($bucket));

        $this->servicioAjuste()->reversar($ajuste, 'Se contó dos veces la misma tarima', $this->admin());

        $this->assertSame('500.0000', $this->saldo($bucket));
    }

    public function test_reversar_una_merma_repone_lo_dado_de_baja(): void
    {
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Merma, '120');

        $this->assertSame('380.0000', $this->saldo($bucket));

        $this->servicioAjuste()->reversar($ajuste, 'La merma se registró en el lote equivocado', $this->admin());

        $this->assertSame('500.0000', $this->saldo($bucket));
    }

    public function test_reversar_una_correccion_de_conteo_deshace_la_diferencia(): void
    {
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);

        $ajuste = $this->borradorAjuste($e, TipoAjuste::CorreccionConteo, '0', extraLinea: ['cantidad_conteo' => '460']);
        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        $this->assertSame('460.0000', $this->saldo($bucket));

        $this->servicioAjuste()->reversar($ajuste, 'El conteo se hizo sobre la bodega equivocada', $this->admin());

        $this->assertSame('500.0000', $this->saldo($bucket));
    }

    public function test_la_reversion_no_borra_ni_edita_los_movimientos_originales(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');
        $original = $ajuste->movimientos()->sole();

        $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());

        $this->assertDatabaseHas('planta_movimientos', [
            'id' => $original->id,
            'cantidad' => '100.0000',
            'transicion' => 'confirmar',
        ]);
    }

    public function test_el_movimiento_compensador_apunta_al_que_deshace(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');
        $original = $ajuste->movimientos()->sole();

        $reversion = $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());
        $compensador = $reversion->movimientos()->sole();

        $this->assertSame($original->id, $compensador->movimiento_revertido_id);
        $this->assertSame('-100.0000', $compensador->cantidad);
        $this->assertSame(TipoMovimientoPlanta::ReversionAjuste, $compensador->tipo);
        $this->assertSame('reversar', $compensador->transicion);
    }

    public function test_los_dos_documentos_quedan_enlazados_en_ambos_sentidos(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        $reversion = $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());

        $this->assertSame($ajuste->id, $reversion->reversion_de_id);
        $this->assertSame($reversion->id, $ajuste->refresh()->revertido_por_id);
        $this->assertSame(EstadoAjustePlanta::Reversado, $ajuste->estado);
        $this->assertSame(EstadoAjustePlanta::Confirmado, $reversion->estado);
    }

    public function test_la_reversion_conserva_el_tipo_y_el_motivo_propio(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Dano, '50');

        $reversion = $this->servicioAjuste()->reversar($ajuste, 'El daño era de otro lote', $this->admin());

        $this->assertSame(TipoAjuste::Dano, $reversion->tipo);
        // El motivo de la reversión es SUYO: explica por qué se deshace, no por
        // qué se hizo.
        $this->assertSame('El daño era de otro lote', $reversion->motivo);
        $this->assertSame($ajuste->motivo, $ajuste->refresh()->motivo);
    }

    public function test_la_reversion_copia_las_lineas_que_movieron_saldo(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        $reversion = $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());
        $copia = $reversion->detalles()->sole();

        $this->assertSame($ajuste->detalles->first()->planta_insumo_id, $copia->planta_insumo_id);
        $this->assertSame($ajuste->detalles->first()->planta_lote_id, $copia->planta_lote_id);
        $this->assertSame($ajuste->detalles->first()->planta_ubicacion_id, $copia->planta_ubicacion_id);
        $this->assertNotSame($ajuste->detalles->first()->id, $copia->id);
    }

    public function test_no_se_reversa_un_ajuste_dos_veces(): void
    {
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');
        $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());

        $this->expectException(ReversionAjusteImposibleException::class);

        try {
            $this->servicioAjuste()->reversar($ajuste->refresh(), 'Otra vez', $this->admin());
        } finally {
            $this->assertSame('500.0000', $this->saldo($bucket));
        }
    }

    public function test_no_se_reversa_la_reversion(): void
    {
        // Deshacer lo deshecho se hace creando un ajuste nuevo, no encadenando
        // compensaciones: la cadena haría imposible leer el histórico.
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');
        $reversion = $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());

        $this->expectException(ReversionAjusteImposibleException::class);

        $this->servicioAjuste()->reversar($reversion, 'Volver atrás', $this->admin());
    }

    public function test_no_se_reversa_un_borrador(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '100');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->reversar($ajuste, 'Nunca se aplicó', $this->admin());
    }

    public function test_no_se_reversa_un_anulado(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '100');
        $this->servicioAjuste()->anular($ajuste);

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->reversar($ajuste->refresh(), 'Nunca se aplicó', $this->admin());
    }

    public function test_la_reversion_exige_motivo(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->reversar($ajuste, '   ', $this->admin());
    }

    public function test_no_se_reversa_si_el_saldo_ya_se_consumio(): void
    {
        // Reversar un ajuste positivo exige RETIRAR lo que sumó. Si ese saldo ya
        // salió, retirarlo dejaría el bucket en negativo, y el mayor no admite
        // saldos imposibles.
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');   // 600

        // Se consume casi todo por otra vía.
        $this->ajusteConfirmado($e, TipoAjuste::Negativo, '550');             // 50

        $this->expectException(ReversionAjusteImposibleException::class);

        try {
            $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());
        } finally {
            // Y el rechazo no dejó medio documento aplicado.
            $this->assertSame('50.0000', $this->saldo($bucket));
            $this->assertSame(EstadoAjustePlanta::Confirmado, $ajuste->refresh()->estado);
            $this->assertNull($ajuste->revertido_por_id);
        }
    }

    public function test_reversar_una_merma_no_exige_saldo_porque_repone(): void
    {
        // La simétrica de la anterior: deshacer una salida SUMA, y sumar nunca
        // puede quedarse corto.
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Merma, '400');      // 100
        $this->ajusteConfirmado($e, TipoAjuste::Negativo, '100');             // 0

        $this->servicioAjuste()->reversar($ajuste, 'La merma no era de este lote', $this->admin());

        $this->assertSame('400.0000', $this->saldo($bucket));
    }

    public function test_la_reversion_de_un_conteo_mixto_solo_compensa_lo_que_movio(): void
    {
        $cuadra = $this->escenarioConSaldo();      // 500, se contará 500
        $descuadra = $this->escenarioConSaldo();   // 500, se contará 450

        $ajuste = $this->servicioAjuste()->crearBorrador(array_merge(
            $this->payloadAjuste($cuadra, TipoAjuste::CorreccionConteo),
            ['detalles' => [
                [
                    'planta_insumo_id' => $cuadra['insumo']->id,
                    'planta_lote_id' => $cuadra['lote']->id,
                    'planta_ubicacion_id' => $cuadra['ubicacion']->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                    'cantidad_conteo' => '500',
                ],
                [
                    'planta_insumo_id' => $descuadra['insumo']->id,
                    'planta_lote_id' => $descuadra['lote']->id,
                    'planta_ubicacion_id' => $descuadra['ubicacion']->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                    'cantidad_conteo' => '450',
                ],
            ]],
        ), $this->admin());
        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        $reversion = $this->servicioAjuste()->reversar($ajuste, 'El conteo se anuló', $this->admin());

        // Dos líneas en el original, UNA en la reversión: la que cuadró no movió
        // nada y no hay nada que compensar.
        $this->assertCount(2, $ajuste->refresh()->detalles);
        $this->assertSame(1, $reversion->detalles()->count());
        $this->assertSame(1, $reversion->movimientos()->count());
        $this->assertSame('500.0000', $this->saldo($this->bucketDeAjuste($descuadra)));
    }

    public function test_la_reversion_queda_en_la_bitacora(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'planta_ajuste',
            'subject_type' => PlantaAjuste::class,
            'subject_id' => $ajuste->id,
            'description' => 'reversó el ajuste de inventario',
        ]);
    }

    public function test_reversar_una_carga_inicial_no_reabre_el_bucket(): void
    {
        // Tras reversar, el bucket vuelve a saldo cero pero YA tiene historial:
        // una segunda carga inicial sigue siendo imposible, y debe serlo, porque
        // el arranque ya ocurrió una vez.
        $e = $this->escenarioVirgen();
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::CargaInicial, '250');

        $this->servicioAjuste()->reversar($ajuste, 'El inventario de arranque estaba mal', $this->admin());

        $this->assertSame('0.0000', $this->saldo($bucket));

        $segunda = $this->borradorAjuste($e, TipoAjuste::CargaInicial, '300');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($segunda, $this->admin());
    }
}
