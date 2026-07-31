<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\AjusteInvalidoException;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmación de un ajuste: los tipos de SIGNO FIJO.
 *
 * Aquí vive la regla central del paso: el signo lo pone el TIPO, nunca el
 * formulario. `cantidad` entra siempre como magnitud positiva y sale del
 * servicio con el signo que le corresponde. La corrección de conteo, que es la
 * única excepción, tiene su propio archivo
 * ({@see PlantaAjusteConteoTest}).
 */
class PlantaAjusteConfirmacionTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    // --- Tipos que suman ---

    public function test_un_ajuste_positivo_suma_al_saldo(): void
    {
        $e = $this->escenarioConSaldo();       // 500 disponibles
        $bucket = $this->bucketDeAjuste($e);

        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        $this->assertSame('600.0000', $this->saldo($bucket));
    }

    public function test_la_carga_inicial_arranca_un_bucket_sin_historial(): void
    {
        $e = $this->escenarioVirgen();
        $bucket = $this->bucketDeAjuste($e);

        $this->assertNull($this->saldo($bucket));

        $this->ajusteConfirmado($e, TipoAjuste::CargaInicial, '250');

        $this->assertSame('250.0000', $this->saldo($bucket));
    }

    public function test_la_carga_inicial_emite_su_propio_tipo_de_movimiento(): void
    {
        // El mayor distingue el arranque del inventario de un ajuste corriente:
        // son preguntas distintas al analizar el histórico.
        $e = $this->escenarioVirgen();

        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::CargaInicial, '250');

        $this->assertSame(
            TipoMovimientoPlanta::CargaInicial,
            $ajuste->movimientos()->sole()->tipo
        );
    }

    public function test_los_demas_tipos_emiten_el_movimiento_generico_de_ajuste(): void
    {
        $e = $this->escenarioConSaldo();

        foreach ([TipoAjuste::Positivo, TipoAjuste::Negativo, TipoAjuste::Merma] as $tipo) {
            $ajuste = $this->ajusteConfirmado($e, $tipo, '10');

            $this->assertSame(
                TipoMovimientoPlanta::Ajuste,
                $ajuste->movimientos()->sole()->tipo,
                "El tipo {$tipo->value} debería emitir un movimiento de ajuste."
            );
        }
    }

    public function test_el_tipo_concreto_queda_en_la_metadata_del_movimiento(): void
    {
        // El mayor solo distingue `carga_inicial` de `ajuste`; «cuánto se mermó»
        // se responde con la metadata.
        $e = $this->escenarioConSaldo();

        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Merma, '10');
        $metadata = $ajuste->movimientos()->sole()->metadata;

        $this->assertSame('merma', $metadata['tipo_ajuste']);
        $this->assertSame($ajuste->numero, $metadata['ajuste_numero']);
        $this->assertSame($ajuste->motivo, $metadata['motivo']);
    }

    // --- Tipos que restan ---

    public function test_los_tipos_negativos_restan_del_saldo(): void
    {
        // El cuarto —`vencimiento`— resta igual, pero exige además que el lote
        // haya caducado, y por eso tiene sus propias pruebas más abajo.
        foreach ([
            TipoAjuste::Negativo,
            TipoAjuste::Merma,
            TipoAjuste::Dano,
        ] as $tipo) {
            $e = $this->escenarioConSaldo();   // 500 disponibles, escenario nuevo
            $bucket = $this->bucketDeAjuste($e);

            $this->ajusteConfirmado($e, $tipo, '30');

            $this->assertSame('470.0000', $this->saldo($bucket), "Falló el tipo {$tipo->value}.");
        }
    }

    public function test_el_movimiento_negativo_se_escribe_con_signo_menos(): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Dano, '30');

        $this->assertSame('-30.0000', $ajuste->movimientos()->sole()->cantidad);
    }

    public function test_un_tipo_negativo_no_puede_dejar_el_saldo_bajo_cero(): void
    {
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Merma, '501');

        $this->expectException(AjusteInvalidoException::class);

        try {
            $this->servicioAjuste()->confirmar($ajuste, $this->admin());
        } finally {
            // Y el rechazo no deja el documento a medio aplicar.
            $this->assertSame('500.0000', $this->saldo($bucket));
            $this->assertSame(EstadoAjustePlanta::Borrador, $ajuste->refresh()->estado);
        }
    }

    public function test_restar_exactamente_todo_el_saldo_si_se_permite(): void
    {
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);

        $this->ajusteConfirmado($e, TipoAjuste::Negativo, '500');

        $this->assertSame('0.0000', $this->saldo($bucket));
    }

    // --- Reglas propias de la carga inicial ---

    public function test_la_carga_inicial_se_rechaza_si_el_bucket_ya_tiene_historial(): void
    {
        $e = $this->escenarioConSaldo();       // ya recibió mercancía
        $ajuste = $this->borradorAjuste($e, TipoAjuste::CargaInicial, '10');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_la_carga_inicial_se_rechaza_aunque_el_saldo_de_hoy_sea_cero(): void
    {
        // Es la variante que importa: un bucket vaciado NO es un bucket virgen.
        // Basta un movimiento histórico para que el arranque ya haya ocurrido.
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $this->ajusteConfirmado($e, TipoAjuste::Negativo, '500');

        $this->assertSame('0.0000', $this->saldo($bucket));

        $ajuste = $this->borradorAjuste($e, TipoAjuste::CargaInicial, '10');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_una_segunda_carga_inicial_sobre_el_mismo_bucket_se_rechaza(): void
    {
        $e = $this->escenarioVirgen();
        $this->ajusteConfirmado($e, TipoAjuste::CargaInicial, '100');

        $segunda = $this->borradorAjuste($e, TipoAjuste::CargaInicial, '50');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($segunda, $this->admin());
    }

    // --- Reglas propias del vencimiento ---

    public function test_el_vencimiento_da_de_baja_un_lote_ya_vencido(): void
    {
        $e = $this->escenarioConSaldo();
        $e['lote']->update(['fecha_vencimiento' => '2026-07-01']);   // antes de la fecha del ajuste
        $bucket = $this->bucketDeAjuste($e);

        $this->ajusteConfirmado($e, TipoAjuste::Vencimiento, '500');

        $this->assertSame('0.0000', $this->saldo($bucket));
    }

    public function test_el_vencimiento_se_admite_el_mismo_dia_en_que_vence(): void
    {
        $e = $this->escenarioConSaldo();
        $e['lote']->update(['fecha_vencimiento' => '2026-07-30']);   // la fecha del ajuste

        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Vencimiento, '100');

        $this->assertSame(EstadoAjustePlanta::Confirmado, $ajuste->estado);
    }

    public function test_no_se_da_de_baja_por_vencimiento_un_lote_que_aun_no_vence(): void
    {
        // Baja ANTICIPADA: no se admite. Si se aceptara, el histórico de mermas
        // por caducidad dejaría de significar «caducó».
        $e = $this->escenarioConSaldo();
        $e['lote']->update(['fecha_vencimiento' => '2027-01-01']);
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Vencimiento, '10');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_no_se_da_de_baja_por_vencimiento_un_lote_sin_fecha(): void
    {
        $e = $this->escenarioConSaldo();

        $this->assertNull($e['lote']->fecha_vencimiento);

        $ajuste = $this->borradorAjuste($e, TipoAjuste::Vencimiento, '10');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    // --- Contexto vigente al confirmar ---

    public function test_no_se_confirma_contra_una_ubicacion_desactivada_despues_del_borrador(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');

        $e['ubicacion']->update(['activo' => false]);

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_no_se_confirma_contra_transito(): void
    {
        // El saldo en viaje pertenece a un traslado: ajustarlo por fuera dejaría
        // el envío y la recepción descuadrados sin que nadie pueda verlo.
        $e = $this->escenarioConSaldo();
        $e['ubicacion'] = $this->transito();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_no_se_confirma_contra_un_insumo_desactivado(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');

        $e['insumo']->update(['activo' => false]);

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_un_insumo_trazable_no_ajusta_contra_el_lote_generico(): void
    {
        $e = $this->escenarioConSaldo();
        $generico = PlantaLote::factory()->generico($e['insumo'])->create();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $ajuste->detalles()->update(['planta_lote_id' => $generico->id]);

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste->refresh(), $this->admin());
    }

    public function test_no_se_confirma_un_ajuste_sin_lineas(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $ajuste->detalles()->delete();

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste->refresh(), $this->admin());
    }

    // --- Idempotencia y firma ---

    public function test_confirmar_dos_veces_no_duplica_el_efecto(): void
    {
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        try {
            $this->servicioAjuste()->confirmar($ajuste, $this->admin());
            $this->fail('La segunda confirmación debería rechazarse.');
        } catch (AjusteInvalidoException) {
            $this->assertTrue(true);
        }

        $this->assertSame('600.0000', $this->saldo($bucket));
        $this->assertSame(1, $ajuste->movimientos()->count());
    }

    public function test_la_confirmacion_firma_el_documento(): void
    {
        $e = $this->escenarioConSaldo();
        $usuario = $this->admin();
        $ajuste = $this->servicioAjuste()->confirmar(
            $this->borradorAjuste($e, TipoAjuste::Positivo, '10'),
            $usuario,
        );

        $this->assertSame(EstadoAjustePlanta::Confirmado, $ajuste->estado);
        $this->assertSame($usuario->id, $ajuste->confirmado_por);
        $this->assertNotNull($ajuste->confirmado_en);
    }

    public function test_todos_los_movimientos_del_documento_comparten_grupo(): void
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

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        $grupos = $ajuste->movimientos()->pluck('grupo_uuid')->unique();

        $this->assertCount(2, $ajuste->movimientos()->get());
        $this->assertCount(1, $grupos);
    }

    public function test_un_ajuste_puede_tocar_dos_ubicaciones_a_la_vez(): void
    {
        // Es lo que distingue esta tabla de la del traslado: el bucket vive en la
        // línea, así que una sola firma corrige Casa y Fábrica.
        $casa = $this->escenarioConSaldo();
        $fabrica = $this->bodega();

        $ajuste = $this->servicioAjuste()->crearBorrador(array_merge(
            $this->payloadAjuste($casa, TipoAjuste::Positivo, '10'),
            ['detalles' => [
                [
                    'planta_insumo_id' => $casa['insumo']->id,
                    'planta_lote_id' => $casa['lote']->id,
                    'planta_ubicacion_id' => $casa['ubicacion']->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                    'cantidad' => '10',
                ],
                [
                    'planta_insumo_id' => $casa['insumo']->id,
                    'planta_lote_id' => $casa['lote']->id,
                    'planta_ubicacion_id' => $fabrica->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                    'cantidad' => '40',
                ],
            ]],
        ), $this->admin());

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        $this->assertSame('510.0000', $this->saldo($this->bucketDeAjuste($casa)));
        $this->assertSame('40.0000', $this->saldo($this->bucketDeAjuste(
            ['insumo' => $casa['insumo'], 'lote' => $casa['lote'], 'ubicacion' => $fabrica]
        )));
    }

    public function test_el_ajuste_puede_tocar_saldo_retenido_y_rechazado(): void
    {
        $e = $this->escenarioConSaldo('5', EstadoDisponibilidad::Retenido);
        $bucket = $this->bucketDeAjuste($e, EstadoDisponibilidad::Retenido);

        $this->ajusteConfirmado($e, TipoAjuste::Merma, '100', EstadoDisponibilidad::Retenido);

        $this->assertSame('400.0000', $this->saldo($bucket));
    }

    public function test_la_confirmacion_queda_en_la_bitacora(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Merma, '10');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'planta_ajuste',
            'subject_type' => PlantaAjuste::class,
            'subject_id' => $ajuste->id,
            'description' => 'confirmó el ajuste de inventario',
        ]);
    }

    public function test_el_movimiento_apunta_al_documento_y_a_su_linea(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '10');

        $movimiento = PlantaMovimiento::query()
            ->delDocumento(PlantaAjuste::class, $ajuste->id)
            ->sole();

        $this->assertSame($ajuste->detalles->first()->id, $movimiento->documento_detalle_id);
        $this->assertSame('confirmar', $movimiento->transicion);
        $this->assertSame('2026-07-30', $movimiento->fecha_efectiva->toDateString());
    }
}
