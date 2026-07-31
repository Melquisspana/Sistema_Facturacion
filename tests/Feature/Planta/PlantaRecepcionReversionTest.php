<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\RecepcionInvalidaException;
use App\Exceptions\Planta\ReversionRecepcionImposibleException;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaRecepcion;
use App\Services\Planta\PlantaInventarioService;
use App\Services\Planta\ReconciliacionExistenciasService;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Reversión de una recepción confirmada.
 *
 * Reversar NO borra ni edita nada: crea un documento de compensación cuyos
 * movimientos son negativos y apuntan, uno a uno, al movimiento original. El
 * original queda `reversada` y se conserva entero.
 *
 * La condición para poder hacerlo es exigente a propósito: el saldo tiene que
 * seguir EXACTAMENTE en el bucket donde entró. Si se trasladó, se consumió o
 * cambió de disponibilidad, retirarlo de donde ya no está restaría de saldo que
 * pertenece a otra entrada. En ese caso la operación falla ENTERA y el mensaje
 * dice qué lote y qué bucket lo impiden.
 */
class PlantaRecepcionReversionTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    private function confirmada(?array $lineas = null): PlantaRecepcion
    {
        $recepcion = $this->borrador(lineas: $lineas);

        return $this->servicioRecepcion()->confirmar($recepcion, $this->admin());
    }

    // --- Camino feliz ---

    public function test_reversar_una_recepcion_intacta_funciona(): void
    {
        $original = $this->confirmada();
        $usuario = $this->admin();

        $reversion = $this->servicioRecepcion()->reversar($original, 'llegó mercancía equivocada', $usuario);

        $this->assertSame(EstadoRecepcionPlanta::Reversada, $original->refresh()->estado);
        $this->assertSame($reversion->id, $original->revertido_por_id);
        $this->assertSame($original->id, $reversion->reversion_de_id);
        $this->assertSame('llegó mercancía equivocada', $reversion->observaciones);
    }

    public function test_el_saldo_vuelve_a_cero(): void
    {
        $original = $this->confirmada();
        $bucket = $this->bucketDe($original);

        $this->assertSame('500.0000', $this->saldo($bucket));

        $this->servicioRecepcion()->reversar($original, 'devolución completa al proveedor', $this->admin());

        $this->assertSame('0.0000', $this->saldo($bucket));
    }

    public function test_los_movimientos_de_reversion_son_espejo_y_apuntan_al_original(): void
    {
        $original = $this->confirmada();

        $movimientoOriginal = PlantaMovimiento::firstOrFail();

        $this->servicioRecepcion()->reversar($original, 'devolución completa al proveedor', $this->admin());

        $espejo = PlantaMovimiento::where('tipo', TipoMovimientoPlanta::ReversionRecepcion->value)->firstOrFail();

        $this->assertSame('-500.0000', $espejo->cantidad);
        $this->assertSame('reversar', $espejo->transicion);
        $this->assertSame($movimientoOriginal->id, $espejo->movimiento_revertido_id);
        $this->assertTrue($espejo->tipo->esReversion());
        $this->assertTrue($espejo->bucket()->esIgualA($movimientoOriginal->bucket()));
    }

    public function test_el_movimiento_original_no_cambia(): void
    {
        $original = $this->confirmada();

        $antes = PlantaMovimiento::firstOrFail()->only(['id', 'cantidad', 'tipo', 'efecto_uid', 'metadata']);

        $this->servicioRecepcion()->reversar($original, 'devolución completa al proveedor', $this->admin());

        $despues = PlantaMovimiento::find($antes['id'])->only(['id', 'cantidad', 'tipo', 'efecto_uid', 'metadata']);

        // El mayor es append-only: reversar AÑADE, nunca reescribe.
        $this->assertSame($antes, $despues);
    }

    public function test_la_reversion_copia_las_lineas_del_original(): void
    {
        $ubicacion = $this->bodega();
        $usuario = $this->admin();

        $original = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($this->insumoConLotes()),
            $this->linea($this->insumoConLotes(), ['cantidad_recibida' => '2']),
        ]), $usuario);
        $this->servicioRecepcion()->confirmar($original, $usuario);

        $reversion = $this->servicioRecepcion()->reversar($original, 'error de captura en ambas lineas', $usuario);

        $this->assertCount(2, $reversion->detalles);
        $this->assertSame(
            $original->refresh()->detalles->pluck('planta_lote_id')->all(),
            $reversion->detalles->pluck('planta_lote_id')->all(),
            'La reversión retira del MISMO lote.'
        );
        $this->assertSame('0.0000', $this->saldo($this->bucketDe($original, 0)));
        $this->assertSame('0.0000', $this->saldo($this->bucketDe($original, 1)));
    }

    public function test_el_inventario_sigue_reconciliando_tras_la_reversion(): void
    {
        $original = $this->confirmada();

        $this->servicioRecepcion()->reversar($original, 'devolución completa al proveedor', $this->admin());

        $this->assertTrue(app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias());
    }

    public function test_la_reversion_queda_registrada_con_su_motivo(): void
    {
        $original = $this->confirmada();
        $usuario = $this->admin();

        $reversion = $this->servicioRecepcion()->reversar($original, 'el proveedor entregó de menos', $usuario);

        $actividad = Activity::where('log_name', 'planta_recepcion')
            ->where('description', 'reversó la recepción confirmada')->latest('id')->first();

        $this->assertNotNull($actividad);
        $this->assertSame('el proveedor entregó de menos', $actividad->properties['motivo']);
        $this->assertSame($original->numero, $actividad->properties['numero']);
        $this->assertSame($reversion->numero, $actividad->properties['reversion_numero']);
        $this->assertSame($usuario->id, $actividad->causer_id);
    }

    public function test_no_se_produce_saldo_negativo(): void
    {
        $original = $this->confirmada();

        $this->servicioRecepcion()->reversar($original, 'devolución completa al proveedor', $this->admin());

        $this->assertSame(0, DB::table('planta_existencias')->where('cantidad', '<', 0)->count());
    }

    // --- Rechazos ---

    public function test_falla_si_el_saldo_ya_se_movio(): void
    {
        $original = $this->confirmada();
        $bucket = $this->bucketDe($original);
        $usuario = $this->admin();

        // Alguien consumió parte del saldo: ya no está donde entró.
        DB::transaction(fn () => app(PlantaInventarioService::class)->aplicarMovimiento(
            $bucket,
            '-100.0000',
            ContextoMovimiento::para(
                tipo: TipoMovimientoPlanta::Ajuste,
                documentoType: 'Tests\\Consumo',
                documentoId: 1,
                transicion: 'confirmar',
                fechaEfectiva: '2026-07-31',
            ),
        ), 3);

        $this->expectException(ReversionRecepcionImposibleException::class);

        $this->servicioRecepcion()->reversar($original, 'intento de reversar lo ya consumido', $usuario);
    }

    public function test_el_mensaje_dice_que_lote_y_que_bucket_lo_impiden(): void
    {
        $original = $this->confirmada();
        $bucket = $this->bucketDe($original);
        $lote = $original->refresh()->detalles->first()->lote;

        DB::transaction(fn () => app(PlantaInventarioService::class)->aplicarMovimiento(
            $bucket,
            '-500.0000',
            ContextoMovimiento::para(
                tipo: TipoMovimientoPlanta::Ajuste,
                documentoType: 'Tests\\Consumo',
                documentoId: 1,
                transicion: 'confirmar',
                fechaEfectiva: '2026-07-31',
            ),
        ), 3);

        try {
            $this->servicioRecepcion()->reversar($original, 'intento de reversar lo ya consumido', $this->admin());
            $this->fail('Se esperaba ReversionRecepcionImposibleException.');
        } catch (ReversionRecepcionImposibleException $e) {
            $this->assertStringContainsString($lote->codigo_interno, $e->getMessage());
            $this->assertStringContainsString('500.0000', $e->getMessage());
            $this->assertStringContainsString('0.0000', $e->getMessage());
        }
    }

    public function test_un_fallo_de_reversion_no_deja_rastro(): void
    {
        $original = $this->confirmada();
        $bucket = $this->bucketDe($original);

        DB::transaction(fn () => app(PlantaInventarioService::class)->aplicarMovimiento(
            $bucket,
            '-500.0000',
            ContextoMovimiento::para(
                tipo: TipoMovimientoPlanta::Ajuste,
                documentoType: 'Tests\\Consumo',
                documentoId: 1,
                transicion: 'confirmar',
                fechaEfectiva: '2026-07-31',
            ),
        ), 3);

        $huella = $this->huellaMayor();
        $documentos = PlantaRecepcion::count();

        try {
            $this->servicioRecepcion()->reversar($original, 'intento de reversar lo ya consumido', $this->admin());
        } catch (ReversionRecepcionImposibleException) {
            // esperado
        }

        // Ni documento de compensación, ni movimientos, ni cambio de estado.
        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame($documentos, PlantaRecepcion::count());
        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $original->refresh()->estado);
        $this->assertNull($original->revertido_por_id);
    }

    public function test_falla_si_el_saldo_cambio_de_disponibilidad(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $usuario = $this->admin();

        $original = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($insumo)]),
            $usuario,
        );
        $this->servicioRecepcion()->confirmar($original, $usuario);

        $detalle = $original->refresh()->detalles->first();

        // Par compensado: sale de disponible y entra a retenido. El saldo sigue
        // existiendo, pero YA NO EN EL BUCKET DONDE ENTRÓ.
        DB::transaction(function () use ($detalle, $ubicacion) {
            $servicio = app(PlantaInventarioService::class);
            $contexto = ContextoMovimiento::para(
                tipo: TipoMovimientoPlanta::CambioDisponibilidad,
                documentoType: 'Tests\\Cambio',
                documentoId: 1,
                transicion: 'confirmar',
                fechaEfectiva: '2026-07-31',
            );

            $servicio->aplicarMovimiento(
                new BucketInventario($detalle->planta_insumo_id, $detalle->planta_lote_id, $ubicacion->id, EstadoDisponibilidad::Disponible),
                '-500.0000',
                $contexto->conSecuencia(0),
            );
            $servicio->aplicarMovimiento(
                new BucketInventario($detalle->planta_insumo_id, $detalle->planta_lote_id, $ubicacion->id, EstadoDisponibilidad::Retenido),
                '500.0000',
                $contexto->conSecuencia(1),
            );
        }, 3);

        // La reversión NO va a buscarlo al bucket retenido: falla y lo explica.
        $this->expectException(ReversionRecepcionImposibleException::class);

        $this->servicioRecepcion()->reversar($original, 'intento tras cambio de disponibilidad', $this->admin());
    }

    public function test_no_se_puede_reversar_dos_veces(): void
    {
        $original = $this->confirmada();
        $usuario = $this->admin();

        $this->servicioRecepcion()->reversar($original, 'devolución completa al proveedor', $usuario);

        $this->expectException(ReversionRecepcionImposibleException::class);

        $this->servicioRecepcion()->reversar($original->refresh(), 'segundo intento de reversar', $usuario);
    }

    public function test_no_se_reversa_un_borrador(): void
    {
        $borrador = $this->borrador();

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->reversar($borrador, 'un borrador no movió inventario', $this->admin());
    }

    public function test_no_se_reversa_una_reversion(): void
    {
        $original = $this->confirmada();
        $usuario = $this->admin();

        $reversion = $this->servicioRecepcion()->reversar($original, 'devolución completa al proveedor', $usuario);

        $this->expectException(ReversionRecepcionImposibleException::class);

        $this->servicioRecepcion()->reversar($reversion, 'reversar la reversión no tiene sentido', $usuario);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $original = $this->confirmada();

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->reversar($original, '   ', $this->admin());
    }

    public function test_una_reversion_parcial_no_existe(): void
    {
        $original = $this->confirmada();
        $bucket = $this->bucketDe($original);

        // Queda MENOS de lo que entró: no se reversa «lo que se pueda».
        DB::transaction(fn () => app(PlantaInventarioService::class)->aplicarMovimiento(
            $bucket,
            '-0.0001',
            ContextoMovimiento::para(
                tipo: TipoMovimientoPlanta::Ajuste,
                documentoType: 'Tests\\Consumo',
                documentoId: 1,
                transicion: 'confirmar',
                fechaEfectiva: '2026-07-31',
            ),
        ), 3);

        $this->expectException(ReversionRecepcionImposibleException::class);

        $this->servicioRecepcion()->reversar($original, 'falta una diezmilesima', $this->admin());
    }
}
