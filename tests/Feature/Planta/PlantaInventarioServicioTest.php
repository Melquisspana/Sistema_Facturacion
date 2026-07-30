<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Enums\Planta\UnidadBase;
use App\Exceptions\Planta\BucketInvalidoException;
use App\Exceptions\Planta\EfectoDuplicadoException;
use App\Exceptions\Planta\MovimientoInvalidoException;
use App\Exceptions\Planta\SaldoInsuficienteException;
use App\Models\Planta\PlantaMovimiento;
use App\Services\Planta\LoteService;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * PlantaInventarioService: el único escritor lógico del inventario.
 *
 * Estas pruebas cubren las garantías del SERVICIO, que son las que el motor no
 * puede dar por sí solo: transacción obligatoria, invariantes de bucket que
 * dependen del tipo de la ubicación, aritmética decimal exacta, saldo nunca
 * negativo, idempotencia por efecto y sincronía entre el mayor y su proyección.
 *
 * LO QUE ESTAS PRUEBAS NO DEMUESTRAN: que `lockForUpdate` serialice de verdad.
 * Corren en SQLite, donde ese bloqueo es un no-op y la base se serializa entera
 * durante cualquier escritura, así que pasarían igual aunque el servicio no
 * tomara ningún candado. Ver {@see PlantaConcurrenciaTest}.
 */
class PlantaInventarioServicioTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    // --- Transacción obligatoria ---
    //
    // La exigencia de transacción NO se prueba aquí: `RefreshDatabase` envuelve
    // cada prueba en una transacción, así que dentro de esta clase el nivel
    // nunca es 0 y la guarda no puede observarse. Vive en
    // {@see PlantaInventarioTransaccionTest}, que cierra ese envoltorio a
    // propósito para trabajar con el nivel realmente en 0.

    // --- Creación del bucket ---

    public function test_crea_la_fila_de_existencia_cuando_el_bucket_es_nuevo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->assertNull($this->saldoProyectado($bucket));

        $this->aplicar($bucket, '25.5000');

        $this->assertSame('25.5000', $this->saldoProyectado($bucket));
        $this->assertSame(1, DB::table('planta_existencias')->where($bucket->aColumnas())->count());
    }

    public function test_no_crea_una_segunda_fila_al_volver_a_mover_el_mismo_bucket(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');
        $this->aplicar($bucket, '5.0000');
        $this->aplicar($bucket, '1.0000');

        $this->assertSame(1, DB::table('planta_existencias')->where($bucket->aColumnas())->count());
        $this->assertSame('16.0000', $this->saldoProyectado($bucket));
    }

    // --- Aritmética ---

    public function test_suma_las_entradas_con_decimales_exactos(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        // 0.1 + 0.2 en coma flotante NO es 0.3. Con bcmath sobre cadenas, sí.
        $this->aplicar($bucket, '0.1000');
        $this->aplicar($bucket, '0.2000');

        $this->assertSame('0.3000', $this->saldoProyectado($bucket));
    }

    public function test_resta_las_salidas(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '100.0000');
        $this->aplicar($bucket, '-30.2500');

        $this->assertSame('69.7500', $this->saldoProyectado($bucket));
    }

    public function test_admite_vaciar_el_bucket_hasta_cero_exacto(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '7.3333');
        $this->aplicar($bucket, '-7.3333');

        $this->assertSame('0.0000', $this->saldoProyectado($bucket));
    }

    public function test_rechaza_el_saldo_negativo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');

        $this->expectException(SaldoInsuficienteException::class);

        $this->aplicar($bucket, '-10.0001');
    }

    public function test_el_intento_de_dejar_negativo_no_deja_rastro(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');
        $movimientosAntes = DB::table('planta_movimientos')->count();

        try {
            $this->aplicar($bucket, '-99.0000');
        } catch (SaldoInsuficienteException) {
            // esperado
        }

        $this->assertSame('10.0000', $this->saldoProyectado($bucket));
        $this->assertSame($movimientosAntes, DB::table('planta_movimientos')->count());
    }

    public function test_rechaza_una_cantidad_de_cero(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->expectException(MovimientoInvalidoException::class);

        $this->aplicar($bucket, '0.0000');
    }

    public function test_rechaza_una_cantidad_que_no_es_decimal(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->expectException(MovimientoInvalidoException::class);

        $this->aplicar($bucket, '10,50');
    }

    public function test_rechaza_mas_decimales_de_los_que_almacena_el_inventario(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        // Redondear en silencio a 4 decimales falsearía el saldo; se rechaza.
        $this->expectException(MovimientoInvalidoException::class);

        $this->aplicar($bucket, '1.00005');
    }

    // --- Fracción y unidad ---

    public function test_un_insumo_sin_fraccion_rechaza_cantidades_fraccionarias(): void
    {
        $bolsa = $this->insumoBolsa();
        $generico = app(LoteService::class)->resolverGenerico($bolsa, '2026-07-30');
        $bucket = $this->bucket($bolsa, $generico, $this->ubicacion());

        $this->expectException(MovimientoInvalidoException::class);

        $this->aplicar($bucket, '2.5000');
    }

    public function test_un_insumo_sin_fraccion_admite_cantidades_enteras(): void
    {
        $bolsa = $this->insumoBolsa();
        $generico = app(LoteService::class)->resolverGenerico($bolsa, '2026-07-30');
        $bucket = $this->bucket($bolsa, $generico, $this->ubicacion());

        $this->aplicar($bucket, '2000.0000');

        $this->assertSame('2000.0000', $this->saldoProyectado($bucket));
    }

    // --- Unidad base: la pone el insumo, nunca el llamador ---

    public function test_la_unidad_base_del_movimiento_se_copia_del_insumo(): void
    {
        $enLibras = $this->insumo(['unidad_base' => UnidadBase::Libra->value]);
        $enUnidades = $this->insumo([
            'unidad_base' => UnidadBase::Unidad->value,
            'permite_fraccion' => false,
        ]);

        $movimientoLibras = $this->aplicar(
            $this->bucket($enLibras, $this->lote($enLibras), $this->ubicacion()),
            '5.5000'
        );
        $movimientoUnidades = $this->aplicar(
            $this->bucket($enUnidades, $this->lote($enUnidades), $this->ubicacion()),
            '5.0000'
        );

        // Cada movimiento hereda la unidad de SU insumo, sin que nadie la indique.
        $this->assertSame(UnidadBase::Libra, $movimientoLibras->fresh()->unidad_base);
        $this->assertSame(UnidadBase::Unidad, $movimientoUnidades->fresh()->unidad_base);
    }

    public function test_el_servicio_no_admite_ninguna_unidad_como_parametro(): void
    {
        $parametros = (new \ReflectionMethod($this->servicio(), 'aplicarMovimiento'))->getParameters();

        // La comprobación es estructural a propósito: mientras no exista el
        // parámetro, no hay nada que probar sobre «qué pasa si me pasan otra
        // unidad». Añadirlo rompería esta prueba, que es justo lo que debe pasar.
        $this->assertCount(3, $parametros);
        $this->assertSame(['bucket', 'cantidadFirmada', 'contexto'], array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            $parametros,
        ));
    }

    public function test_el_contexto_del_movimiento_no_transporta_unidad(): void
    {
        $propiedades = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(ContextoMovimiento::class))->getProperties(),
        );

        // El contexto es el otro canal por el que el llamador podría colar una
        // unidad. No la lleva, y esta prueba lo mantiene así.
        $this->assertNotContains('unidadBase', $propiedades);
        $this->assertNotContains('unidad', $propiedades);
    }

    public function test_la_unidad_no_puede_inyectarse_por_metadata(): void
    {
        $insumo = $this->insumo(['unidad_base' => UnidadBase::Libra->value]);
        $bucket = $this->bucket($insumo, $this->lote($insumo), $this->ubicacion());

        $movimiento = $this->aplicar($bucket, '5.0000', $this->contexto(metadata: [
            'unidad_base' => UnidadBase::Unidad->value,
            'unidad' => 'saco',
        ]))->fresh();

        // La metadata se conserva como contexto informativo del documento, pero la
        // COLUMNA sigue siendo la del insumo: sumar libras con unidades no es un
        // error que se detecte después, es uno que no puede llegar a ocurrir.
        $this->assertSame(UnidadBase::Libra, $movimiento->unidad_base);
        $this->assertSame('saco', $movimiento->metadata['unidad']);
    }

    public function test_cambiar_la_unidad_del_insumo_no_reescribe_el_historico(): void
    {
        $insumo = $this->insumo(['unidad_base' => UnidadBase::Libra->value]);
        $bucket = $this->bucket($insumo, $this->lote($insumo), $this->ubicacion());

        $movimiento = $this->aplicar($bucket, '5.0000');

        $insumo->unidad_base = UnidadBase::Unidad->value;
        $insumo->save();

        // La unidad del movimiento es una INSTANTÁNEA congelada al escribirlo, no
        // una referencia viva al catálogo.
        $this->assertSame(UnidadBase::Libra, $movimiento->fresh()->unidad_base);
    }

    // --- Invariantes de bucket ---

    public function test_rechaza_un_traslado_negativo(): void
    {
        ['insumo' => $insumo, 'lote' => $lote] = $this->escenarioBasico();

        $this->expectException(BucketInvalidoException::class);

        $this->bucket($insumo, $lote, $this->transito(), EstadoDisponibilidad::Disponible, -1);
    }

    public function test_rechaza_una_ubicacion_fisica_con_traslado(): void
    {
        ['insumo' => $insumo, 'lote' => $lote, 'ubicacion' => $ubicacion] = $this->escenarioBasico();

        $bucket = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Disponible, 7);

        $this->expectException(BucketInvalidoException::class);

        $this->aplicar($bucket, '5.0000');
    }

    public function test_rechaza_transito_sin_traslado(): void
    {
        ['insumo' => $insumo, 'lote' => $lote] = $this->escenarioBasico();

        $bucket = $this->bucket($insumo, $lote, $this->transito(), EstadoDisponibilidad::Disponible, 0);

        $this->expectException(BucketInvalidoException::class);

        $this->aplicar($bucket, '5.0000');
    }

    public function test_admite_transito_con_traslado(): void
    {
        ['insumo' => $insumo, 'lote' => $lote] = $this->escenarioBasico();

        $bucket = $this->bucket($insumo, $lote, $this->transito(), EstadoDisponibilidad::Disponible, 42);

        $this->aplicar($bucket, '5.0000');

        $this->assertSame('5.0000', $this->saldoProyectado($bucket));
    }

    public function test_rechaza_un_lote_de_otro_insumo(): void
    {
        $insumo = $this->insumo();
        $ajeno = $this->insumo();
        $loteAjeno = $this->lote($ajeno);

        $bucket = new BucketInventario(
            insumoId: $insumo->id,
            loteId: $loteAjeno->id,
            ubicacionId: $this->ubicacion()->id,
            estado: EstadoDisponibilidad::Disponible,
        );

        $this->expectException(BucketInvalidoException::class);

        $this->aplicar($bucket, '5.0000');
    }

    public function test_un_insumo_que_controla_lotes_no_puede_usar_el_generico(): void
    {
        // El genérico se fabrica a mano: LoteService se niega a crearlo para un
        // insumo trazable, así que la única vía es escribirlo saltándose el dominio.
        $insumo = $this->insumo(['controla_lotes' => true]);
        $generico = $this->lote($insumo, ['es_generico' => true, 'codigo_interno' => 'GEN-'.$insumo->id]);

        $bucket = $this->bucket($insumo, $generico, $this->ubicacion());

        $this->expectException(BucketInvalidoException::class);

        $this->aplicar($bucket, '5.0000');
    }

    public function test_un_insumo_sin_control_de_lotes_no_puede_usar_un_lote_real(): void
    {
        $bolsa = $this->insumoBolsa();
        $real = $this->lote($bolsa, ['es_generico' => false]);

        $bucket = $this->bucket($bolsa, $real, $this->ubicacion());

        $this->expectException(BucketInvalidoException::class);

        $this->aplicar($bucket, '5.0000');
    }

    // --- Idempotencia por efecto ---

    public function test_el_mismo_efecto_exacto_no_puede_aplicarse_dos_veces(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $contexto = $this->contexto(documentoId: 500, detalleId: 9);

        $this->aplicar($bucket, '10.0000', $contexto);

        $this->expectException(EfectoDuplicadoException::class);

        $this->aplicar($bucket, '10.0000', $contexto);
    }

    public function test_el_duplicado_rechazado_no_altera_el_saldo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $contexto = $this->contexto(documentoId: 500, detalleId: 9);
        $this->aplicar($bucket, '10.0000', $contexto);

        try {
            $this->aplicar($bucket, '10.0000', $contexto);
        } catch (EfectoDuplicadoException) {
            // esperado
        }

        $this->assertSame('10.0000', $this->saldoProyectado($bucket));
        $this->assertSame(1, DB::table('planta_movimientos')->count());
    }

    public function test_dos_efectos_legitimos_conviven_en_el_mismo_bucket_con_secuencia_distinta(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        // Mismo documento, mismo detalle, misma transición, mismo bucket: solo el
        // lado lógico los distingue. Es el caso que obliga a que la secuencia entre
        // en el hash.
        $base = $this->contexto(documentoId: 700, detalleId: 3);

        $this->aplicar($bucket, '10.0000', $base->conSecuencia(0));
        $this->aplicar($bucket, '4.0000', $base->conSecuencia(1));

        $this->assertSame(2, DB::table('planta_movimientos')->count());
        $this->assertSame('14.0000', $this->saldoProyectado($bucket));
    }

    public function test_dos_detalles_distintos_del_mismo_documento_conviven(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000', $this->contexto(documentoId: 800, detalleId: 1));
        $this->aplicar($bucket, '10.0000', $this->contexto(documentoId: 800, detalleId: 2));

        $this->assertSame(2, DB::table('planta_movimientos')->count());
        $this->assertSame('20.0000', $this->saldoProyectado($bucket));
    }

    // --- Atomicidad ---

    public function test_un_fallo_posterior_deshace_el_movimiento_y_el_saldo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '50.0000');
        $huellaAntes = $this->huellaMayor();

        try {
            DB::transaction(function () use ($bucket) {
                $this->servicio()->aplicarMovimiento($bucket, '25.0000', $this->contexto());

                // Cualquier cosa que falle DESPUÉS del movimiento: una validación de
                // negocio, otra escritura, un evento. El inventario no puede quedarse
                // con medio efecto aplicado.
                throw new RuntimeException('fallo posterior al movimiento');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame('50.0000', $this->saldoProyectado($bucket));
        $this->assertSame($huellaAntes, $this->huellaMayor());
    }

    public function test_el_mayor_y_la_proyeccion_quedan_siempre_sincronizados(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        foreach (['12.5000', '-3.2500', '40.0000', '-49.2500', '0.7500'] as $cantidad) {
            $this->aplicar($bucket, $cantidad);
        }

        $this->assertSame($this->sumaMayor($bucket), $this->saldoProyectado($bucket));
        $this->assertSame('0.7500', $this->saldoProyectado($bucket));
    }

    public function test_cada_bucket_lleva_su_propio_saldo(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $ubicacion = $this->ubicacion();

        $disponible = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Disponible);
        $retenido = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Retenido);

        $this->aplicar($disponible, '30.0000');
        $this->aplicar($retenido, '7.0000');

        $this->assertSame('30.0000', $this->saldoProyectado($disponible));
        $this->assertSame('7.0000', $this->saldoProyectado($retenido));
    }

    // --- Metadata ---

    public function test_la_metadata_guarda_el_saldo_antes_y_despues(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $primero = $this->aplicar($bucket, '10.0000');
        $segundo = $this->aplicar($bucket, '5.5000');

        $this->assertSame('0.0000', $primero->saldoAntes());
        $this->assertSame('10.0000', $primero->saldoDespues());
        $this->assertSame('10.0000', $segundo->saldoAntes());
        $this->assertSame('15.5000', $segundo->saldoDespues());
    }

    public function test_la_metadata_del_llamador_se_conserva_pero_no_pisa_el_saldo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $contexto = $this->contexto(metadata: [
            'motivo' => 'conteo inicial',
            // Un llamador malicioso o descuidado no puede falsear el saldo histórico.
            'saldo_antes' => '999.0000',
        ]);

        $movimiento = $this->aplicar($bucket, '10.0000', $contexto);

        $this->assertSame('conteo inicial', $movimiento->metadata['motivo']);
        $this->assertSame('0.0000', $movimiento->saldoAntes());
    }

    // --- Contexto del movimiento ---

    public function test_el_movimiento_conserva_documento_transicion_y_fecha_operativa(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $contexto = $this->contexto(
            tipo: TipoMovimientoPlanta::Recepcion,
            documentoId: 321,
            detalleId: 12,
            transicion: 'confirmar',
        );

        $movimiento = $this->aplicar($bucket, '10.0000', $contexto)->fresh();

        $this->assertSame(TipoMovimientoPlanta::Recepcion, $movimiento->tipo);
        $this->assertSame('Tests\\Documento', $movimiento->documento_type);
        $this->assertSame(321, $movimiento->documento_id);
        $this->assertSame(12, $movimiento->documento_detalle_id);
        $this->assertSame('confirmar', $movimiento->transicion);
        $this->assertSame('2026-07-30', $movimiento->fecha_efectiva->toDateString());
        $this->assertSame(64, strlen($movimiento->efecto_uid));
    }

    public function test_una_compensacion_exige_apuntar_al_movimiento_original(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->expectException(MovimientoInvalidoException::class);

        $this->aplicar($bucket, '-1.0000', $this->contexto(
            tipo: TipoMovimientoPlanta::ReversionRecepcion,
            revertidoId: null,
        ));
    }

    public function test_un_movimiento_normal_no_puede_apuntar_a_una_reversion(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $original = $this->aplicar($bucket, '10.0000');

        $this->expectException(MovimientoInvalidoException::class);

        $this->aplicar($bucket, '5.0000', $this->contexto(
            tipo: TipoMovimientoPlanta::Recepcion,
            revertidoId: $original->id,
        ));
    }

    public function test_una_compensacion_valida_se_registra_apuntando_al_original(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $original = $this->aplicar($bucket, '10.0000', $this->contexto(tipo: TipoMovimientoPlanta::Recepcion));

        $reversion = $this->aplicar($bucket, '-10.0000', $this->contexto(
            tipo: TipoMovimientoPlanta::ReversionRecepcion,
            transicion: 'reversar',
            revertidoId: $original->id,
        ));

        $this->assertSame($original->id, $reversion->movimiento_revertido_id);
        $this->assertSame('0.0000', $this->saldoProyectado($bucket));
        $this->assertTrue($reversion->tipo->esReversion());
    }

    public function test_el_movimiento_expone_su_bucket_completo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $movimiento = $this->aplicar($bucket, '10.0000');

        $this->assertTrue($movimiento->bucket()->esIgualA($bucket));
        $this->assertTrue($movimiento->esEntrada());
        $this->assertFalse($movimiento->esSalida());
    }

    public function test_el_scope_del_bucket_filtra_por_las_cinco_dimensiones(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $ubicacion = $this->ubicacion();

        $disponible = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Disponible);
        $retenido = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Retenido);

        $this->aplicar($disponible, '10.0000');
        $this->aplicar($retenido, '3.0000');

        $this->assertSame(1, PlantaMovimiento::delBucket($disponible)->count());
        $this->assertSame(1, PlantaMovimiento::delBucket($retenido)->count());
    }

    // --- Lectura ---

    public function test_el_saldo_de_un_bucket_inexistente_es_cero_y_no_crea_fila(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->assertSame('0.0000', $this->servicio()->saldo($bucket));
        $this->assertSame(0, DB::table('planta_existencias')->count());
    }
}
