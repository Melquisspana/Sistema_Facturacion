<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\CambioDisponibilidadInvalidoException;
use App\Exceptions\Planta\ReversionCambioDisponibilidadImposibleException;
use App\Models\Planta\PlantaCambioDisponibilidad;
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
 * Reversión de un cambio de disponibilidad confirmado.
 *
 * Devuelve la cantidad del destino al origen con el par espejo. NO edita ni
 * borra los movimientos originales: crea un documento nuevo cuyos dos efectos
 * apuntan, cada uno, al movimiento que compensan.
 *
 * La condición es exigente a propósito: el saldo tiene que seguir EXACTAMENTE en
 * el bucket de destino. Si ya se trasladó, se consumió o volvió a cambiar de
 * disponibilidad, retirarlo de donde ya no está restaría de saldo que llegó por
 * otra vía.
 */
class PlantaCambioDisponibilidadReversionTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;

    /** Cambio ya confirmado sobre saldo retenido real. */
    private function confirmado(
        ?PlantaRecepcion $recepcion = null,
        string $cantidad = '100',
        EstadoDisponibilidad $destino = EstadoDisponibilidad::Disponible,
    ): PlantaCambioDisponibilidad {
        $cambio = $this->borradorCambio($recepcion, $cantidad, $destino);

        return $this->servicioCambio()->confirmar($cambio, $this->admin());
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

    // --- Camino feliz: liberación ---

    public function test_reversar_una_liberacion_devuelve_el_saldo_a_retenido(): void
    {
        $recepcion = $this->saldoRetenido();
        $original = $this->confirmado($recepcion, '100');

        $this->assertSame('400.0000', $this->saldo($this->bucketRetenido($recepcion)));
        $this->assertSame('100.0000', $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible)));

        $reversion = $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $this->admin());

        $this->assertSame('500.0000', $this->saldo($this->bucketRetenido($recepcion)));
        $this->assertSame('0.0000', $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible)));
        $this->assertSame(EstadoCambioDisponibilidad::Reversado, $original->refresh()->estado);
        $this->assertSame($reversion->id, $original->revertido_por_id);
        $this->assertSame($original->id, $reversion->reversion_de_id);
    }

    // --- Camino feliz: rechazo ---

    public function test_reversar_un_rechazo_devuelve_el_saldo_a_retenido(): void
    {
        $recepcion = $this->saldoRetenido();
        $original = $this->confirmado($recepcion, '50', EstadoDisponibilidad::Rechazado);

        $this->assertSame('50.0000', $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Rechazado)));

        $this->servicioCambio()->reversar($original, 'el rechazo se hizo sobre el lote equivocado', $this->admin());

        $this->assertSame('500.0000', $this->saldo($this->bucketRetenido($recepcion)));
        $this->assertSame('0.0000', $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Rechazado)));
    }

    // --- Propiedades de los movimientos espejo ---

    public function test_la_reversion_genera_dos_movimientos_espejo(): void
    {
        $original = $this->confirmado(cantidad: '100');

        $reversion = $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $this->admin());

        $espejo = $reversion->movimientos()->orderBy('id')->get();

        $this->assertCount(2, $espejo);
        $this->assertSame(
            [TipoMovimientoPlanta::ReversionCambioDisponibilidad, TipoMovimientoPlanta::ReversionCambioDisponibilidad],
            $espejo->pluck('tipo')->all(),
        );
        $this->assertSame(['reversar', 'reversar'], $espejo->pluck('transicion')->all());
        // Suman cero, igual que el par original.
        $this->assertSame('0.0000', bcadd($espejo[0]->cantidad, $espejo[1]->cantidad, 4));
    }

    public function test_ambos_movimientos_espejo_apuntan_a_su_original(): void
    {
        $original = $this->confirmado(cantidad: '100');
        $originales = $original->movimientos()->get()->keyBy(fn ($m) => $m->bucket()->claveCanonica());

        $reversion = $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $this->admin());

        foreach ($reversion->movimientos as $espejo) {
            $this->assertNotNull($espejo->movimiento_revertido_id, 'Toda compensación apunta a lo que compensa.');

            // Cada espejo compensa el movimiento del MISMO bucket.
            $esperado = $originales[$espejo->bucket()->claveCanonica()] ?? null;
            $this->assertNotNull($esperado);
            $this->assertSame($esperado->id, $espejo->movimiento_revertido_id);
            // Y con el signo contrario.
            $this->assertSame('0.0000', bcadd($esperado->cantidad, $espejo->cantidad, 4));
        }
    }

    public function test_los_movimientos_originales_no_cambian(): void
    {
        $original = $this->confirmado(cantidad: '100');

        $antes = $original->movimientos()->orderBy('id')->get()
            ->map(fn ($m) => $m->only(['id', 'cantidad', 'tipo', 'efecto_uid', 'estado']))->all();

        $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $this->admin());

        $despues = PlantaMovimiento::whereIn('id', array_column($antes, 'id'))->orderBy('id')->get()
            ->map(fn ($m) => $m->only(['id', 'cantidad', 'tipo', 'efecto_uid', 'estado']))->all();

        // El mayor es append-only: reversar AÑADE, nunca reescribe.
        $this->assertSame($antes, $despues);
    }

    public function test_la_reversion_intercambia_origen_y_destino(): void
    {
        $original = $this->confirmado(cantidad: '100');

        $reversion = $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $this->admin());

        $this->assertSame($original->estado_destino, $reversion->estado_origen);
        $this->assertSame($original->estado_origen, $reversion->estado_destino);
        $this->assertSame((string) $original->cantidad, (string) $reversion->cantidad);
        $this->assertSame(EstadoCambioDisponibilidad::Confirmado, $reversion->estado);
    }

    public function test_el_inventario_sigue_reconciliando_tras_la_reversion(): void
    {
        $original = $this->confirmado(cantidad: '100');

        $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $this->admin());

        $this->assertTrue(app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias());
    }

    public function test_no_se_produce_saldo_negativo(): void
    {
        $original = $this->confirmado(cantidad: '100');

        $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $this->admin());

        $this->assertSame(0, DB::table('planta_existencias')->where('cantidad', '<', 0)->count());
    }

    public function test_la_reversion_queda_registrada_con_su_motivo(): void
    {
        $usuario = $this->admin();
        $original = $this->confirmado(cantidad: '100');

        $reversion = $this->servicioCambio()->reversar($original, 'se liberó antes de tener el análisis', $usuario);

        $actividad = Activity::where('log_name', 'planta_cambio_disponibilidad')
            ->where('description', 'reversó el cambio de disponibilidad')->latest('id')->first();

        $this->assertNotNull($actividad);
        $this->assertSame('se liberó antes de tener el análisis', $actividad->properties['motivo']);
        $this->assertSame($original->numero, $actividad->properties['numero']);
        $this->assertSame($reversion->numero, $actividad->properties['reversion_numero']);
        $this->assertSame($usuario->id, $actividad->causer_id);
    }

    // --- Rechazos ---

    public function test_falla_si_el_saldo_destino_ya_fue_consumido(): void
    {
        $recepcion = $this->saldoRetenido();
        $original = $this->confirmado($recepcion, '100');

        // Alguien usó lo que se había liberado.
        $this->consumir($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible), '100');

        $this->expectException(ReversionCambioDisponibilidadImposibleException::class);

        $this->servicioCambio()->reversar($original, 'intento de reversar lo ya consumido', $this->admin());
    }

    public function test_falla_aunque_solo_falte_una_diezmilesima(): void
    {
        $recepcion = $this->saldoRetenido();
        $original = $this->confirmado($recepcion, '100');

        $this->consumir($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible), '0.0001');

        // No existe la reversión parcial: se devuelve todo o no se devuelve nada.
        $this->expectException(ReversionCambioDisponibilidadImposibleException::class);

        $this->servicioCambio()->reversar($original, 'falta una diezmilesima del saldo', $this->admin());
    }

    public function test_el_mensaje_dice_que_bucket_lo_impide(): void
    {
        $recepcion = $this->saldoRetenido();
        $original = $this->confirmado($recepcion, '100');
        $destino = $this->bucketEn($recepcion, EstadoDisponibilidad::Disponible);

        $this->consumir($destino, '100');

        try {
            $this->servicioCambio()->reversar($original, 'intento de reversar lo ya consumido', $this->admin());
            $this->fail('Se esperaba ReversionCambioDisponibilidadImposibleException.');
        } catch (ReversionCambioDisponibilidadImposibleException $e) {
            $this->assertStringContainsString('disponible', $e->getMessage());
            $this->assertStringContainsString('100.0000', $e->getMessage());
            $this->assertStringContainsString('0.0000', $e->getMessage());
        }
    }

    public function test_un_fallo_de_reversion_no_deja_rastro(): void
    {
        $recepcion = $this->saldoRetenido();
        $original = $this->confirmado($recepcion, '100');

        $this->consumir($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible), '100');

        $huella = $this->huellaMayor();
        $documentos = PlantaCambioDisponibilidad::count();

        try {
            $this->servicioCambio()->reversar($original, 'intento de reversar lo ya consumido', $this->admin());
        } catch (ReversionCambioDisponibilidadImposibleException) {
            // esperado
        }

        // Ni documento de compensación, ni movimientos, ni cambio de estado.
        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame($documentos, PlantaCambioDisponibilidad::count());
        $this->assertSame(EstadoCambioDisponibilidad::Confirmado, $original->refresh()->estado);
        $this->assertNull($original->revertido_por_id);
    }

    public function test_falla_si_el_saldo_destino_volvio_a_cambiar_de_disponibilidad(): void
    {
        $recepcion = $this->saldoRetenido();
        $usuario = $this->admin();

        // Se retiene 100, se libera, y luego se rechaza desde retenido otra vez:
        // el saldo liberado sigue ahí, pero se consume por otra vía.
        $original = $this->confirmado($recepcion, '100');

        $this->consumir($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible), '60');

        $this->expectException(ReversionCambioDisponibilidadImposibleException::class);

        $this->servicioCambio()->reversar($original, 'parte del saldo liberado ya se usó', $usuario);
    }

    public function test_no_se_reversa_dos_veces(): void
    {
        $original = $this->confirmado(cantidad: '100');
        $usuario = $this->admin();

        $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $usuario);

        $this->expectException(ReversionCambioDisponibilidadImposibleException::class);

        $this->servicioCambio()->reversar($original->refresh(), 'segundo intento de reversar', $usuario);
    }

    public function test_no_se_reversa_una_reversion(): void
    {
        $original = $this->confirmado(cantidad: '100');
        $usuario = $this->admin();

        $reversion = $this->servicioCambio()->reversar($original, 'la liberación fue un error de criterio', $usuario);

        $this->expectException(ReversionCambioDisponibilidadImposibleException::class);

        $this->servicioCambio()->reversar($reversion, 'reversar la reversión no tiene sentido', $usuario);
    }

    public function test_no_se_reversa_un_borrador(): void
    {
        $borrador = $this->borradorCambio();

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->reversar($borrador, 'un borrador no movió inventario', $this->admin());
    }

    public function test_no_se_reversa_un_anulado(): void
    {
        $borrador = $this->borradorCambio();
        $this->servicioCambio()->anular($borrador);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->reversar($borrador->refresh(), 'un anulado no movió inventario', $this->admin());
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $original = $this->confirmado(cantidad: '100');

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->reversar($original, '   ', $this->admin());
    }
}
