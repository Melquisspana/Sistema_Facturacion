<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\UnidadBase;
use App\Exceptions\Planta\AjusteInvalidoException;
use App\Models\Planta\PlantaAjuste;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Borrador de ajuste: crear, editar y anular.
 *
 * La regla que gobierna todo el archivo: NADA de esto toca inventario. El
 * borrador es una intención, y la intención no mueve saldo. Cada prueba que
 * escribe compara la huella del mayor antes y después.
 */
class PlantaAjusteBorradorTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    public function test_crear_un_borrador_no_escribe_en_el_libro_mayor(): void
    {
        $e = $this->escenarioConSaldo();
        $antes = $this->huellaMayor();

        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '100');

        $this->assertSame(EstadoAjustePlanta::Borrador, $ajuste->estado);
        $this->assertSame($antes, $this->huellaMayor());
    }

    public function test_el_borrador_guarda_cabecera_lineas_y_autor(): void
    {
        $e = $this->escenarioConSaldo();
        $usuario = $this->admin();

        $ajuste = $this->servicioAjuste()->crearBorrador(
            $this->payloadAjuste($e, TipoAjuste::Merma, '25'),
            $usuario,
        );

        $this->assertSame(TipoAjuste::Merma, $ajuste->tipo);
        $this->assertSame('2026-07-30', $ajuste->fecha->toDateString());
        $this->assertSame('Diferencia constatada en revisión de bodega', $ajuste->motivo);
        $this->assertSame('Quien constató', $ajuste->responsable_nombre);
        $this->assertSame($usuario->id, $ajuste->creado_por);
        $this->assertCount(1, $ajuste->detalles);
        $this->assertSame('25.0000', $ajuste->detalles->first()->cantidad);
    }

    public function test_la_linea_guarda_el_bucket_completo(): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->borradorAjuste($e, TipoAjuste::Negativo, '10', EstadoDisponibilidad::Disponible);
        $detalle = $ajuste->detalles->first();

        $this->assertSame($e['insumo']->id, $detalle->planta_insumo_id);
        $this->assertSame($e['lote']->id, $detalle->planta_lote_id);
        $this->assertSame($e['ubicacion']->id, $detalle->planta_ubicacion_id);
        $this->assertSame(EstadoDisponibilidad::Disponible, $detalle->estado_disponibilidad);
    }

    public function test_la_unidad_base_se_copia_del_insumo_y_no_del_formulario(): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10', extraLinea: [
            'unidad_base' => UnidadBase::Unidad->value,
        ]);

        // El insumo del escenario es por libra: lo que diga el formulario sobra.
        $this->assertSame(UnidadBase::Libra, $ajuste->detalles->first()->unidad_base);
    }

    public function test_un_insumo_sin_control_de_lotes_va_al_generico_aunque_se_pida_otro(): void
    {
        $e = $this->escenarioConSaldo();
        $bolsa = $this->insumoSinLotes();

        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10', extraLinea: [
            'planta_insumo_id' => $bolsa->id,
            // Un lote real, de otro insumo: no debe ganar.
            'planta_lote_id' => $e['lote']->id,
        ]);

        $lote = $ajuste->detalles->first()->lote;

        $this->assertTrue($lote->es_generico);
        $this->assertSame($bolsa->id, $lote->planta_insumo_id);
    }

    public function test_el_motivo_vacio_se_rechaza_al_crear(): void
    {
        $e = $this->escenarioConSaldo();

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->crearBorrador(
            $this->payloadAjuste($e, TipoAjuste::Positivo, '10', extraCabecera: ['motivo' => '   ']),
            $this->admin(),
        );
    }

    public function test_editar_reemplaza_las_lineas_por_bucket(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');

        $this->servicioAjuste()->actualizarBorrador(
            $ajuste,
            $this->payloadAjuste($e, TipoAjuste::Positivo, '77'),
        );

        // Mismo bucket: se actualiza la línea, no se añade otra.
        $this->assertCount(1, $ajuste->refresh()->detalles);
        $this->assertSame('77.0000', $ajuste->detalles->first()->cantidad);
    }

    public function test_editar_elimina_las_lineas_que_ya_no_vienen(): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->servicioAjuste()->crearBorrador(array_merge(
            $this->payloadAjuste($e, TipoAjuste::Positivo, '10'),
            ['detalles' => [
                [
                    'planta_insumo_id' => $e['insumo']->id,
                    'planta_lote_id' => $e['lote']->id,
                    'planta_ubicacion_id' => $e['ubicacion']->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                    'cantidad' => '10',
                ],
                [
                    'planta_insumo_id' => $e['insumo']->id,
                    'planta_lote_id' => $e['lote']->id,
                    'planta_ubicacion_id' => $e['ubicacion']->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Retenido->value,
                    'cantidad' => '20',
                ],
            ]],
        ), $this->admin());

        $this->assertCount(2, $ajuste->detalles);

        $this->servicioAjuste()->actualizarBorrador(
            $ajuste,
            $this->payloadAjuste($e, TipoAjuste::Positivo, '10'),
        );

        $this->assertCount(1, $ajuste->refresh()->detalles);
        $this->assertSame(
            EstadoDisponibilidad::Disponible,
            $ajuste->detalles->first()->estado_disponibilidad
        );
    }

    public function test_editar_no_toca_el_numero_ni_el_estado(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $numero = $ajuste->numero;

        $this->servicioAjuste()->actualizarBorrador(
            $ajuste,
            $this->payloadAjuste($e, TipoAjuste::Merma, '5'),
        );

        $this->assertSame($numero, $ajuste->refresh()->numero);
        $this->assertSame(EstadoAjustePlanta::Borrador, $ajuste->estado);
        $this->assertSame(TipoAjuste::Merma, $ajuste->tipo);
    }

    public function test_no_se_edita_un_ajuste_ya_confirmado(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '10');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->actualizarBorrador(
            $ajuste,
            $this->payloadAjuste($e, TipoAjuste::Positivo, '999'),
        );
    }

    public function test_anular_deja_el_borrador_terminal_sin_tocar_inventario(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $antes = $this->huellaMayor();

        $this->servicioAjuste()->anular($ajuste);

        $this->assertSame(EstadoAjustePlanta::Anulado, $ajuste->refresh()->estado);
        $this->assertSame($antes, $this->huellaMayor());
    }

    public function test_no_se_anula_dos_veces_ni_se_edita_lo_anulado(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $this->servicioAjuste()->anular($ajuste);

        try {
            $this->servicioAjuste()->anular($ajuste);
            $this->fail('Anular dos veces debería rechazarse.');
        } catch (AjusteInvalidoException) {
            $this->assertTrue(true);
        }

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->actualizarBorrador(
            $ajuste,
            $this->payloadAjuste($e, TipoAjuste::Positivo, '1'),
        );
    }

    public function test_un_borrador_anulado_no_se_puede_confirmar(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $this->servicioAjuste()->anular($ajuste);

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_el_borrador_queda_en_la_bitacora(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'planta_ajuste',
            'subject_type' => PlantaAjuste::class,
            'subject_id' => $ajuste->id,
            'description' => 'creó el borrador de ajuste',
        ]);
    }

    public function test_anular_queda_en_la_bitacora(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');

        $this->servicioAjuste()->anular($ajuste);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'planta_ajuste',
            'subject_id' => $ajuste->id,
            'description' => 'anuló el borrador de ajuste',
        ]);
    }
}
