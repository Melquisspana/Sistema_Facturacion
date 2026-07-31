<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\ReversionTrasladoImposibleException;
use App\Exceptions\Planta\TrasladoInvalidoException;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaTraslado;
use App\Services\Planta\PlantaInventarioService;
use App\Services\Planta\ReconciliacionExistenciasService;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Reversión de un traslado, en sus DOS formas.
 *
 *   ENVIADO   la mercancía sigue en tránsito. Se deshace la salida:
 *             tránsito -X, origen +X. Tipo `reversion_traslado_envio`.
 *
 *   RECIBIDO  la mercancía ya llegó. Se compensa contablemente:
 *             destino -X, origen +X, SIN recrear tránsito. Tipo
 *             `reversion_traslado_recepcion`.
 *
 * El segundo caso se PARECE a un traslado normal en sentido inverso, y por eso
 * es tan importante que no lo sea: nadie condujo de vuelta. Los tipos explícitos
 * son lo que permite responder «cuánta mercancía viajó de verdad» sin
 * contaminarse con correcciones.
 */
class PlantaTrasladoReversionTest extends TestCase
{
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    /** Traslado enviado, con la mercancía en tránsito. */
    private function enviado(array $e, string $cantidad = '200'): PlantaTraslado
    {
        $traslado = $this->borradorTraslado($e, $cantidad);

        return $this->servicioTraslado()->enviar($traslado, $this->admin());
    }

    /** Traslado recibido, con la mercancía en el destino. */
    private function recibido(array $e, string $cantidad = '200'): PlantaTraslado
    {
        $traslado = $this->enviado($e, $cantidad);

        return $this->servicioTraslado()->recibir($traslado, $this->admin());
    }

    /** Consume saldo de un bucket saltándose el documento, como haría otro flujo. */
    private function consumir(BucketInventario $bucket, string $cantidad): void
    {
        DB::transaction(fn () => app(PlantaInventarioService::class)->aplicarMovimiento(
            $bucket,
            '-'.$cantidad,
            ContextoMovimiento::para(
                tipo: TipoMovimientoPlanta::Ajuste,
                documentoType: 'Tests\\Consumo',
                documentoId: 1,
                transicion: 'confirmar',
                fechaEfectiva: '2026-07-31',
            ),
        ), 3);
    }

    // --- Reversar un ENVIADO ---

    public function test_reversar_un_enviado_devuelve_el_saldo_al_origen(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->enviado($e, '200');

        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($e)));
        $this->assertSame('200.0000', $this->saldo($this->bucketTransito($e, $traslado)));

        $reversion = $this->servicioTraslado()->reversar($traslado, 'el camión no llegó a salir', $this->admin());

        $this->assertSame('500.0000', $this->saldo($this->bucketOrigen($e)));
        $this->assertSame('0.0000', $this->saldo($this->bucketTransito($e, $traslado)));
        $this->assertSame(EstadoTrasladoPlanta::Reversado, $traslado->refresh()->estado);
        $this->assertSame($reversion->id, $traslado->revertido_por_id);
    }

    public function test_reversar_un_enviado_usa_el_tipo_de_envio(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->enviado($e, '200');

        $reversion = $this->servicioTraslado()->reversar($traslado, 'el camión no llegó a salir', $this->admin());

        $tipos = $reversion->movimientos()->pluck('tipo')->unique()->values();

        $this->assertCount(1, $tipos);
        $this->assertSame(TipoMovimientoPlanta::ReversionTrasladoEnvio, $tipos->first());
        $this->assertTrue($tipos->first()->esReversion());
    }

    public function test_reversar_un_enviado_no_toca_el_destino(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->enviado($e, '200');

        $this->servicioTraslado()->reversar($traslado, 'el camión no llegó a salir', $this->admin());

        // Nunca llegó: el destino no tiene por qué enterarse.
        $this->assertNull($this->saldo($this->bucketDestino($e)));
    }

    // --- Reversar un RECIBIDO ---

    public function test_reversar_un_recibido_compensa_del_destino_al_origen(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->assertSame('200.0000', $this->saldo($this->bucketDestino($e)));

        $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        $this->assertSame('0.0000', $this->saldo($this->bucketDestino($e)));
        $this->assertSame('500.0000', $this->saldo($this->bucketOrigen($e)));
    }

    public function test_reversar_un_recibido_no_recrea_transito(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        // Nadie condujo de vuelta: fingir un segundo viaje dejaría en el mayor un
        // recorrido que no ocurrió.
        $this->assertSame('0.0000', $this->saldo($this->bucketTransito($e, $traslado)));
        $this->assertSame(0, DB::table('planta_existencias')
            ->where('planta_ubicacion_id', $e['transito']->id)
            ->where('cantidad', '>', 0)->count());
    }

    public function test_reversar_un_recibido_usa_el_tipo_de_recepcion(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $reversion = $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        $tipos = $reversion->movimientos()->pluck('tipo')->unique()->values();

        $this->assertCount(1, $tipos);
        $this->assertSame(TipoMovimientoPlanta::ReversionTrasladoRecepcion, $tipos->first());

        // Y NO cuenta como recorrido físico: es lo que permite preguntar «cuánto
        // viajó de verdad» sin contaminarse con correcciones.
        $this->assertFalse($tipos->first()->esTrasladoFisico());
    }

    // --- Propiedades comunes ---

    public function test_los_movimientos_espejo_apuntan_a_su_original(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $reversion = $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        foreach ($reversion->movimientos as $espejo) {
            $this->assertNotNull($espejo->movimiento_revertido_id, 'Toda compensación apunta a lo que compensa.');

            $original = PlantaMovimiento::findOrFail($espejo->movimiento_revertido_id);
            // Compensa el movimiento del MISMO bucket, con signo contrario.
            $this->assertTrue($original->bucket()->esIgualA($espejo->bucket()));
            $this->assertSame('0.0000', bcadd($original->cantidad, $espejo->cantidad, 4));
        }
    }

    public function test_los_movimientos_originales_no_cambian(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $antes = $traslado->movimientos()->orderBy('id')->get()
            ->map(fn ($m) => $m->only(['id', 'cantidad', 'tipo', 'efecto_uid', 'planta_traslado_id']))->all();

        $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        $despues = PlantaMovimiento::whereIn('id', array_column($antes, 'id'))->orderBy('id')->get()
            ->map(fn ($m) => $m->only(['id', 'cantidad', 'tipo', 'efecto_uid', 'planta_traslado_id']))->all();

        // El mayor es append-only: reversar AÑADE, nunca reescribe.
        $this->assertSame($antes, $despues);
    }

    public function test_la_reversion_invierte_origen_y_destino(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $reversion = $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        $this->assertSame($traslado->planta_ubicacion_destino_id, $reversion->planta_ubicacion_origen_id);
        $this->assertSame($traslado->planta_ubicacion_origen_id, $reversion->planta_ubicacion_destino_id);
        $this->assertSame($traslado->id, $reversion->reversion_de_id);
    }

    public function test_el_motivo_queda_en_el_original(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        // Es donde se consulta «por qué se deshizo esto».
        $this->assertSame('se trasladó el lote equivocado', $traslado->refresh()->motivo_reversion);
    }

    public function test_el_inventario_sigue_reconciliando(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        $this->assertTrue(app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias());
    }

    public function test_no_se_produce_saldo_negativo(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '500');

        $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $this->admin());

        $this->assertSame(0, DB::table('planta_existencias')->where('cantidad', '<', 0)->count());
        $this->assertSame('500.0000', $this->saldo($this->bucketOrigen($e)));
    }

    public function test_la_reversion_queda_registrada(): void
    {
        $e = $this->escenarioTraslado();
        $usuario = $this->admin();
        $traslado = $this->recibido($e, '200');

        $reversion = $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $usuario);

        $actividad = Activity::where('log_name', 'planta_traslado')
            ->where('description', 'reversó el traslado')->latest('id')->first();

        $this->assertNotNull($actividad);
        $this->assertSame('se trasladó el lote equivocado', $actividad->properties['motivo']);
        $this->assertSame('recibido', $actividad->properties['desde_estado']);
        $this->assertSame('reversion_traslado_recepcion', $actividad->properties['tipo_movimiento']);
        $this->assertSame($reversion->numero, $actividad->properties['reversion_numero']);
        $this->assertSame($usuario->id, $actividad->causer_id);
    }

    // --- Rechazos ---

    public function test_falla_si_el_saldo_del_destino_ya_se_consumio(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->consumir($this->bucketDestino($e), '200');

        $this->expectException(ReversionTrasladoImposibleException::class);

        $this->servicioTraslado()->reversar($traslado, 'intento de reversar lo ya consumido', $this->admin());
    }

    public function test_falla_aunque_solo_falte_una_diezmilesima(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->consumir($this->bucketDestino($e), '0.0001');

        // No existe la reversión parcial.
        $this->expectException(ReversionTrasladoImposibleException::class);

        $this->servicioTraslado()->reversar($traslado, 'falta una diezmilesima', $this->admin());
    }

    public function test_falla_si_el_transito_del_enviado_ya_se_consumio(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->enviado($e, '200');

        $this->consumir($this->bucketTransito($e, $traslado), '200');

        $this->expectException(ReversionTrasladoImposibleException::class);

        $this->servicioTraslado()->reversar($traslado, 'intento de reversar sin saldo en transito', $this->admin());
    }

    public function test_el_mensaje_dice_que_bucket_lo_impide(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->consumir($this->bucketDestino($e), '200');

        try {
            $this->servicioTraslado()->reversar($traslado, 'intento de reversar lo ya consumido', $this->admin());
            $this->fail('Se esperaba ReversionTrasladoImposibleException.');
        } catch (ReversionTrasladoImposibleException $ex) {
            $this->assertStringContainsString('200.0000', $ex->getMessage());
            $this->assertStringContainsString('0.0000', $ex->getMessage());
            $this->assertStringContainsString('destino', $ex->getMessage());
        }
    }

    public function test_un_fallo_de_reversion_no_deja_rastro(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->consumir($this->bucketDestino($e), '200');

        $huella = $this->huellaMayor();
        $documentos = PlantaTraslado::count();

        try {
            $this->servicioTraslado()->reversar($traslado, 'intento de reversar lo ya consumido', $this->admin());
        } catch (ReversionTrasladoImposibleException) {
            // esperado
        }

        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame($documentos, PlantaTraslado::count());
        $this->assertSame(EstadoTrasladoPlanta::Recibido, $traslado->refresh()->estado);
        $this->assertNull($traslado->revertido_por_id);
    }

    public function test_no_se_reversa_dos_veces(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');
        $usuario = $this->admin();

        $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $usuario);

        $this->expectException(ReversionTrasladoImposibleException::class);

        $this->servicioTraslado()->reversar($traslado->refresh(), 'segundo intento de reversar', $usuario);
    }

    public function test_no_se_reversa_una_reversion(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');
        $usuario = $this->admin();

        $reversion = $this->servicioTraslado()->reversar($traslado, 'se trasladó el lote equivocado', $usuario);

        $this->expectException(ReversionTrasladoImposibleException::class);

        $this->servicioTraslado()->reversar($reversion, 'reversar la reversión no tiene sentido', $usuario);
    }

    public function test_no_se_reversa_un_borrador_ni_un_cancelado(): void
    {
        $e = $this->escenarioTraslado();
        $borrador = $this->borradorTraslado($e, '100');

        try {
            $this->servicioTraslado()->reversar($borrador, 'un borrador no movió inventario', $this->admin());
            $this->fail('Se esperaba TrasladoInvalidoException.');
        } catch (TrasladoInvalidoException) {
            // esperado
        }

        $this->servicioTraslado()->cancelar($borrador->refresh());

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->reversar($borrador->refresh(), 'un cancelado tampoco', $this->admin());
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->recibido($e, '200');

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->reversar($traslado, '   ', $this->admin());
    }

    public function test_reversar_un_enviado_no_afecta_al_transito_de_otro_traslado(): void
    {
        $e = $this->escenarioTraslado();
        $usuario = $this->admin();

        $primero = $this->enviado($e, '100');
        $segundo = $this->enviado($e, '150');

        $this->servicioTraslado()->reversar($primero, 'el camión no llegó a salir', $usuario);

        // El tránsito del segundo sigue intacto: cada viaje tiene su saldo.
        $this->assertSame('0.0000', $this->saldo($this->bucketTransito($e, $primero)));
        $this->assertSame('150.0000', $this->saldo($this->bucketTransito($e, $segundo)));
        $this->assertSame(EstadoTrasladoPlanta::Enviado, $segundo->refresh()->estado);
    }
}
