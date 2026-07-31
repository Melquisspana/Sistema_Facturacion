<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Exceptions\Planta\AjusteInvalidoException;
use App\Models\Planta\PlantaAjuste;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corrección de conteo: el único tipo cuyo SIGNO no lo decide el tipo.
 *
 * Su regla vertebral es CUÁNDO se lee `cantidad_sistema`. No al escribir el
 * borrador —entre eso y la confirmación pueden pasar horas y el saldo puede
 * moverse— sino al confirmar, con la fila del bucket bloqueada. Si se hiciera de
 * otro modo, la corrección escribiría una diferencia que ya no existe y
 * descuadraría justo lo que venía a cuadrar.
 *
 * Es también el único tipo que puede terminar sin escribir nada: una línea cuyo
 * conteo coincide con el sistema no es un hecho que registrar.
 */
class PlantaAjusteConteoTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    /** Borrador de corrección con el conteo indicado. */
    private function borradorConteo(array $e, string $contado, EstadoDisponibilidad $estado = EstadoDisponibilidad::Disponible)
    {
        return $this->borradorAjuste($e, TipoAjuste::CorreccionConteo, '0', $estado, [
            'cantidad_conteo' => $contado,
        ]);
    }

    // --- El signo sale de la diferencia ---

    public function test_contar_de_mas_suma_la_diferencia(): void
    {
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);

        $ajuste = $this->borradorConteo($e, '520');
        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        $this->assertSame('520.0000', $this->saldo($bucket));
        $this->assertSame('20.0000', $ajuste->movimientos()->sole()->cantidad);
    }

    public function test_contar_de_menos_resta_la_diferencia(): void
    {
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);

        $ajuste = $this->borradorConteo($e, '480');
        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        $this->assertSame('480.0000', $this->saldo($bucket));
        $this->assertSame('-20.0000', $ajuste->movimientos()->sole()->cantidad);
    }

    public function test_el_saldo_final_es_exactamente_lo_contado(): void
    {
        // Es la definición de la corrección: después de confirmarla, el sistema
        // dice lo que dijo la persona que contó.
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);

        $this->servicioAjuste()->confirmar($this->borradorConteo($e, '333.5'), $this->admin());

        $this->assertSame('333.5000', $this->saldo($bucket));
    }

    public function test_contar_cero_vacia_el_bucket(): void
    {
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);

        $this->servicioAjuste()->confirmar($this->borradorConteo($e, '0'), $this->admin());

        $this->assertSame('0.0000', $this->saldo($bucket));
    }

    // --- La lectura bajo bloqueo ---

    public function test_la_cantidad_del_sistema_se_lee_al_confirmar_no_al_escribir(): void
    {
        // El caso que justifica todo el diseño: el saldo cambia ENTRE el borrador
        // y la confirmación. La diferencia debe calcularse con el saldo nuevo.
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);

        $ajuste = $this->borradorConteo($e, '520');

        // Alguien más mueve el bucket mientras tanto: entra otro ajuste de +100.
        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');
        $this->assertSame('600.0000', $this->saldo($bucket));

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        // Si hubiera usado el saldo viejo, habría sumado 20 y dejado 620.
        $this->assertSame('520.0000', $this->saldo($bucket));
        $this->assertSame('-80.0000', $ajuste->movimientos()->sole()->cantidad);
    }

    public function test_lo_que_el_formulario_diga_del_sistema_se_descarta(): void
    {
        $e = $this->escenarioConSaldo();       // 500
        $bucket = $this->bucketDeAjuste($e);

        $ajuste = $this->borradorConteo($e, '520');
        // Un valor inventado, escrito directo en la línea.
        $ajuste->detalles()->update(['cantidad_sistema' => '1', 'diferencia' => '519']);

        $this->servicioAjuste()->confirmar($ajuste->refresh(), $this->admin());

        $this->assertSame('500.0000', $ajuste->detalles->first()->cantidad_sistema);
        $this->assertSame('520.0000', $this->saldo($bucket));
    }

    public function test_el_borrador_no_precalcula_la_diferencia(): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->borradorConteo($e, '520');
        $detalle = $ajuste->detalles->first();

        $this->assertSame('520.0000', $detalle->cantidad_conteo);
        $this->assertNull($detalle->cantidad_sistema);
        $this->assertNull($detalle->diferencia);
        $this->assertSame('0.0000', $detalle->cantidad);
    }

    public function test_la_confirmacion_persiste_sistema_diferencia_y_magnitud(): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->borradorConteo($e, '480');
        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
        $detalle = $ajuste->refresh()->detalles->first();

        $this->assertSame('500.0000', $detalle->cantidad_sistema);
        $this->assertSame('-20.0000', $detalle->diferencia);
        // `cantidad` es siempre la MAGNITUD, sin signo.
        $this->assertSame('20.0000', $detalle->cantidad);
    }

    public function test_la_metadata_del_movimiento_guarda_las_tres_cifras(): void
    {
        $e = $this->escenarioConSaldo();

        $ajuste = $this->borradorConteo($e, '480');
        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
        $metadata = $ajuste->movimientos()->sole()->metadata;

        $this->assertSame('500.0000', $metadata['cantidad_sistema']);
        $this->assertSame('480.0000', $metadata['cantidad_conteo']);
        $this->assertSame('-20.0000', $metadata['diferencia']);
        $this->assertSame('correccion_conteo', $metadata['tipo_ajuste']);
    }

    // --- Diferencia cero ---

    /**
     * Corrección de dos buckets: el primero cuadra, el segundo no.
     *
     * @return array{0: PlantaAjuste, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function conteoMixto(): array
    {
        $cuadra = $this->escenarioConSaldo();      // 500
        $descuadra = $this->escenarioConSaldo();   // otro bucket, 500

        $ajuste = $this->servicioAjuste()->crearBorrador(array_merge(
            $this->payloadAjuste($cuadra, TipoAjuste::CorreccionConteo),
            ['detalles' => [
                [
                    'planta_insumo_id' => $cuadra['insumo']->id,
                    'planta_lote_id' => $cuadra['lote']->id,
                    'planta_ubicacion_id' => $cuadra['ubicacion']->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                    'cantidad_conteo' => '500',       // cuadra
                ],
                [
                    'planta_insumo_id' => $descuadra['insumo']->id,
                    'planta_lote_id' => $descuadra['lote']->id,
                    'planta_ubicacion_id' => $descuadra['ubicacion']->id,
                    'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                    'cantidad_conteo' => '450',       // no cuadra
                ],
            ]],
        ), $this->admin());

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());

        return [$ajuste->refresh(), $cuadra, $descuadra];
    }

    public function test_una_linea_sin_diferencia_no_escribe_movimiento(): void
    {
        // Contar y que cuadre no es un hecho de inventario: no hay nada que
        // registrar en el mayor.
        [$ajuste, $cuadra, $descuadra] = $this->conteoMixto();

        // Dos líneas, UN movimiento.
        $this->assertCount(2, $ajuste->detalles);
        $this->assertSame(1, $ajuste->movimientos()->count());
        $this->assertSame('500.0000', $this->saldo($this->bucketDeAjuste($cuadra)));
        $this->assertSame('450.0000', $this->saldo($this->bucketDeAjuste($descuadra)));
    }

    public function test_la_linea_que_cuadra_igual_deja_constancia_de_lo_contado(): void
    {
        // No mueve saldo, pero sí registra que ese bucket se contó y cuadró: es
        // la mitad del valor de un inventario físico. Hace falta que el documento
        // se acepte —aquí, por la otra línea—: si se rechazara entero, la
        // transacción se revierte y no queda constancia de nada.
        [$ajuste] = $this->conteoMixto();

        $detalle = $ajuste->detalles->sortBy('id')->first();

        $this->assertSame('500.0000', $detalle->cantidad_sistema);
        $this->assertSame('0.0000', $detalle->diferencia);
        $this->assertSame('0.0000', $detalle->cantidad);
        $this->assertTrue($detalle->diferenciaEsCero());
    }

    public function test_una_correccion_entera_sin_diferencias_se_rechaza(): void
    {
        // Un documento que no mueve nada no es un ajuste: es ruido en el
        // histórico y una firma sin consecuencia.
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorConteo($e, '500');
        $antes = $this->huellaMayor();

        $this->expectException(AjusteInvalidoException::class);

        try {
            $this->servicioAjuste()->confirmar($ajuste, $this->admin());
        } finally {
            $this->assertSame($antes, $this->huellaMayor());
            $this->assertSame(EstadoAjustePlanta::Borrador, $ajuste->refresh()->estado);
        }
    }

    // --- Validaciones ---

    public function test_una_linea_de_conteo_sin_cantidad_contada_se_rechaza(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::CorreccionConteo);

        $this->assertNull($ajuste->detalles->first()->cantidad_conteo);

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }

    public function test_un_conteo_negativo_se_rechaza(): void
    {
        // No se puede contar menos de nada.
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorConteo($e, '10');
        $ajuste->detalles()->update(['cantidad_conteo' => '-5']);

        $this->expectException(AjusteInvalidoException::class);

        $this->servicioAjuste()->confirmar($ajuste->refresh(), $this->admin());
    }

    public function test_contar_un_bucket_que_nunca_tuvo_saldo_lo_crea(): void
    {
        // El sistema dice 0 aunque la fila no exista; contar 40 es una diferencia
        // de +40 igual de válida que cualquier otra.
        $e = $this->escenarioVirgen();
        $bucket = $this->bucketDeAjuste($e);

        $this->assertNull($this->saldo($bucket));

        $this->servicioAjuste()->confirmar($this->borradorConteo($e, '40'), $this->admin());

        $this->assertSame('40.0000', $this->saldo($bucket));
    }

    public function test_la_correccion_no_altera_otros_estados_del_mismo_lote(): void
    {
        $e = $this->escenarioConSaldo();                                  // 500 disponibles
        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '80', EstadoDisponibilidad::Retenido);

        $this->servicioAjuste()->confirmar($this->borradorConteo($e, '300'), $this->admin());

        $this->assertSame('300.0000', $this->saldo($this->bucketDeAjuste($e)));
        $this->assertSame('80.0000', $this->saldo($this->bucketDeAjuste($e, EstadoDisponibilidad::Retenido)));
    }
}
