<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\CambioDisponibilidadInvalidoException;
use App\Models\Dte;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Services\Planta\ReconciliacionExistenciasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Confirmación del cambio de disponibilidad: el par compensado.
 *
 * Lo que se comprueba una y otra vez es que el par SUMA CERO. Un cambio de
 * disponibilidad no crea ni destruye mercancía: mueve la misma cantidad de un
 * bucket a otro del mismo insumo, lote y ubicación. En cuanto los dos importes
 * dejaran de coincidir, esto habría dejado de ser un cambio de disponibilidad
 * para convertirse en un ajuste encubierto.
 */
class PlantaCambioDisponibilidadConfirmacionTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;

    // --- Borrador ---

    public function test_un_borrador_no_mueve_inventario(): void
    {
        $recepcion = $this->saldoRetenido();
        $huella = $this->huellaMayor();

        $this->servicioCambio()->crearBorrador($this->payloadCambio($recepcion, '100'), $this->admin());

        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame('500.0000', $this->saldo($this->bucketRetenido($recepcion)));
    }

    public function test_el_borrador_fija_el_origen_en_retenido_aunque_llegue_otra_cosa(): void
    {
        $recepcion = $this->saldoRetenido();

        $cambio = $this->servicioCambio()->crearBorrador(
            // Una petición manual intentando fijar el origen.
            $this->payloadCambio($recepcion, '100', extra: ['estado_origen' => 'disponible']),
            $this->admin(),
        );

        $this->assertSame(EstadoDisponibilidad::Retenido, $cambio->estado_origen);
    }

    public function test_anular_no_mueve_inventario(): void
    {
        $cambio = $this->borradorCambio();
        $huella = $this->huellaMayor();

        $anulado = $this->servicioCambio()->anular($cambio);

        $this->assertSame(EstadoCambioDisponibilidad::Anulado, $anulado->estado);
        $this->assertSame($huella, $this->huellaMayor());
    }

    public function test_un_anulado_no_se_confirma_ni_se_edita(): void
    {
        $cambio = $this->borradorCambio();
        $this->servicioCambio()->anular($cambio);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    // --- Liberación: retenido -> disponible ---

    public function test_liberar_genera_exactamente_dos_movimientos(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        $movimientos = $cambio->refresh()->movimientos()->orderBy('id')->get();

        $this->assertCount(2, $movimientos);
        $this->assertSame(
            [TipoMovimientoPlanta::CambioDisponibilidad, TipoMovimientoPlanta::CambioDisponibilidad],
            $movimientos->pluck('tipo')->all(),
        );
        $this->assertSame(['confirmar', 'confirmar'], $movimientos->pluck('transicion')->all());
    }

    public function test_liberar_mueve_el_saldo_de_retenido_a_disponible(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        $this->assertSame('400.0000', $this->saldo($this->bucketRetenido($recepcion)));
        $this->assertSame('100.0000', $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible)));
    }

    // --- Rechazo: retenido -> rechazado ---

    public function test_rechazar_genera_exactamente_dos_movimientos(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '50', EstadoDisponibilidad::Rechazado);

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        $this->assertCount(2, $cambio->refresh()->movimientos);
        $this->assertSame('450.0000', $this->saldo($this->bucketRetenido($recepcion)));
        $this->assertSame('50.0000', $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Rechazado)));
    }

    // --- Propiedades del par ---

    public function test_las_cantidades_son_iguales_y_de_signo_opuesto(): void
    {
        $cambio = $this->borradorCambio(cantidad: '123.4567');

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        $cantidades = $cambio->refresh()->movimientos()->orderBy('id')->pluck('cantidad')->all();

        $this->assertCount(2, $cantidades);
        // Suman cero: no se crea ni se destruye inventario.
        $this->assertSame('0.0000', bcadd($cantidades[0], $cantidades[1], 4));
        $this->assertSame('123.4567', bcadd(str_replace('-', '', $cantidades[0]), '0', 4));
    }

    public function test_ambos_movimientos_comparten_insumo_lote_y_ubicacion(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        $movimientos = $cambio->refresh()->movimientos;

        $this->assertCount(1, $movimientos->pluck('planta_insumo_id')->unique());
        $this->assertCount(1, $movimientos->pluck('planta_lote_id')->unique());
        $this->assertCount(1, $movimientos->pluck('planta_ubicacion_id')->unique());
        // Lo ÚNICO que difiere es el estado.
        $this->assertCount(2, $movimientos->pluck('estado')->unique());
        // Y nunca hay traslado: esto no es un viaje.
        $this->assertSame([0, 0], $movimientos->pluck('planta_traslado_id')->all());
    }

    public function test_ambos_movimientos_comparten_grupo_pero_no_efecto(): void
    {
        $cambio = $this->borradorCambio();

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        $movimientos = $cambio->refresh()->movimientos;

        $this->assertCount(1, $movimientos->pluck('grupo_uuid')->unique(), 'El grupo agrupa la operación.');
        $this->assertCount(2, $movimientos->pluck('efecto_uid')->unique(), 'Cada efecto es único.');
    }

    public function test_la_metadata_guarda_saldo_antes_y_despues_y_el_motivo(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        foreach ($cambio->refresh()->movimientos as $movimiento) {
            $this->assertNotNull($movimiento->saldoAntes());
            $this->assertNotNull($movimiento->saldoDespues());
            $this->assertSame($cambio->numero, $movimiento->metadata['cambio_numero']);
            $this->assertSame('liberacion', $movimiento->metadata['accion']);
            $this->assertSame($cambio->motivo, $movimiento->metadata['motivo']);
        }
    }

    public function test_el_documento_queda_confirmado_con_su_firma(): void
    {
        $usuario = $this->admin();
        $cambio = $this->borradorCambio();

        $confirmado = $this->servicioCambio()->confirmar($cambio, $usuario);

        $this->assertSame(EstadoCambioDisponibilidad::Confirmado, $confirmado->estado);
        $this->assertSame($usuario->id, $confirmado->confirmado_por);
        $this->assertNotNull($confirmado->confirmado_en);
    }

    // --- Rechazos ---

    public function test_saldo_retenido_insuficiente_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        // Hay 500 retenidos; se piden 500.0001.
        $cambio = $this->borradorCambio($recepcion, '500.0001');

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio, $this->admin());
    }

    public function test_un_fallo_por_saldo_no_deja_rastro(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '9999');
        $huella = $this->huellaMayor();

        try {
            $this->servicioCambio()->confirmar($cambio, $this->admin());
        } catch (CambioDisponibilidadInvalidoException) {
            // esperado
        }

        // Ni medio par aplicado: el primer movimiento tampoco se escribió.
        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame('500.0000', $this->saldo($this->bucketRetenido($recepcion)));
        $this->assertNull($this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible)));
        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $cambio->refresh()->estado);
    }

    public function test_cantidad_cero_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)->update(['cantidad' => '0']);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    public function test_cantidad_negativa_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)->update(['cantidad' => '-5']);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    public function test_una_ubicacion_de_transito_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)
            ->update(['planta_ubicacion_id' => $this->transito()->id]);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    public function test_una_ubicacion_inactiva_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        $cambio->ubicacion->update(['activo' => false]);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    public function test_un_origen_distinto_de_retenido_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        // Solo alcanzable escribiendo por fuera del servicio.
        DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)
            ->update(['estado_origen' => 'disponible']);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    public function test_un_destino_invalido_falla_al_crear(): void
    {
        $recepcion = $this->saldoRetenido();

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->crearBorrador(
            $this->payloadCambio($recepcion, '100', EstadoDisponibilidad::Retenido),
            $this->admin(),
        );
    }

    public function test_un_destino_invalido_escrito_a_mano_falla_al_confirmar(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)
            ->update(['estado_destino' => 'retenido']);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    public function test_un_lote_de_otro_insumo_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        $ajeno = $this->insumoConLotes();
        $loteAjeno = PlantaLote::factory()->create(['planta_insumo_id' => $ajeno->id]);

        DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)
            ->update(['planta_lote_id' => $loteAjeno->id]);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    public function test_el_motivo_vacio_falla(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)->update(['motivo' => '   ']);

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
    }

    // --- Idempotencia e inmutabilidad ---

    public function test_confirmar_dos_veces_no_duplica_movimientos(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');

        $this->servicioCambio()->confirmar($cambio, $this->admin());

        try {
            $this->servicioCambio()->confirmar($cambio->refresh(), $this->admin());
            $this->fail('Un documento confirmado no debe volver a confirmarse.');
        } catch (CambioDisponibilidadInvalidoException) {
            // esperado: el estado ya no es borrador
        }

        $this->assertSame(2, PlantaMovimiento::where('tipo', TipoMovimientoPlanta::CambioDisponibilidad->value)->count());
        $this->assertSame('400.0000', $this->saldo($this->bucketRetenido($recepcion)));
    }

    public function test_un_confirmado_no_se_edita_ni_se_anula(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $this->servicioCambio()->confirmar($cambio, $this->admin());

        try {
            $this->servicioCambio()->actualizarBorrador($cambio->refresh(), $this->payloadCambio($recepcion, '999'));
            $this->fail('Se esperaba CambioDisponibilidadInvalidoException.');
        } catch (CambioDisponibilidadInvalidoException) {
            // esperado
        }

        $this->expectException(CambioDisponibilidadInvalidoException::class);

        $this->servicioCambio()->anular($cambio->refresh());
    }

    public function test_dos_cambios_sobre_el_mismo_bucket_conviven(): void
    {
        $recepcion = $this->saldoRetenido();
        $usuario = $this->admin();

        $primero = $this->borradorCambio($recepcion, '100');
        $this->servicioCambio()->confirmar($primero, $usuario);

        $segundo = $this->servicioCambio()->crearBorrador($this->payloadCambio($recepcion, '150'), $usuario);
        $this->servicioCambio()->confirmar($segundo, $usuario);

        // Dos documentos distintos: sus efecto_uid difieren por documento_id.
        $this->assertSame(4, PlantaMovimiento::where('tipo', TipoMovimientoPlanta::CambioDisponibilidad->value)->count());
        $this->assertSame('250.0000', $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible)));
        $this->assertSame('250.0000', $this->saldo($this->bucketRetenido($recepcion)));
    }

    // --- Coherencia y auditoría ---

    public function test_el_inventario_reconcilia_despues_de_confirmar(): void
    {
        $recepcion = $this->saldoRetenido();
        $usuario = $this->admin();

        $this->servicioCambio()->confirmar($this->borradorCambio($recepcion, '100'), $usuario);
        $this->servicioCambio()->confirmar(
            $this->servicioCambio()->crearBorrador(
                $this->payloadCambio($recepcion, '50', EstadoDisponibilidad::Rechazado),
                $usuario,
            ),
            $usuario,
        );

        $this->assertTrue(app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias());
    }

    public function test_el_total_del_lote_no_cambia(): void
    {
        $recepcion = $this->saldoRetenido();
        $usuario = $this->admin();

        $this->servicioCambio()->confirmar($this->borradorCambio($recepcion, '100'), $usuario);
        $this->servicioCambio()->confirmar(
            $this->servicioCambio()->crearBorrador(
                $this->payloadCambio($recepcion, '50', EstadoDisponibilidad::Rechazado),
                $usuario,
            ),
            $usuario,
        );

        $total = (string) DB::table('planta_existencias')
            ->where('planta_lote_id', $this->bucketRetenido($recepcion)->loteId)
            ->sum('cantidad');

        // 350 retenido + 100 disponible + 50 rechazado = 500, lo mismo que entró.
        $this->assertSame('500.0000', bcadd($total, '0', 4));
    }

    public function test_la_confirmacion_queda_registrada_en_activitylog(): void
    {
        $usuario = $this->admin();
        $cambio = $this->borradorCambio(cantidad: '100');

        $this->servicioCambio()->confirmar($cambio, $usuario);

        $actividad = Activity::where('log_name', 'planta_cambio_disponibilidad')
            ->where('description', 'confirmó el cambio de disponibilidad')->latest('id')->first();

        $this->assertNotNull($actividad);
        $this->assertSame($cambio->numero, $actividad->properties['numero']);
        $this->assertSame('liberacion', $actividad->properties['accion']);
        $this->assertSame('100.0000', $actividad->properties['cantidad']);
        $this->assertSame($cambio->motivo, $actividad->properties['motivo']);
        $this->assertSame($usuario->id, $actividad->causer_id);
    }

    public function test_la_anulacion_queda_registrada(): void
    {
        $cambio = $this->borradorCambio();

        $this->servicioCambio()->anular($cambio);

        $this->assertSame(1, Activity::where('log_name', 'planta_cambio_disponibilidad')
            ->where('description', 'anuló el borrador de cambio de disponibilidad')->count());
    }

    // --- Aislamiento del dominio fiscal ---

    public function test_no_toca_facturacion_ni_dte(): void
    {
        $dtesAntes = Dte::count();

        $this->servicioCambio()->confirmar($this->borradorCambio(cantidad: '100'), $this->admin());

        $this->assertSame($dtesAntes, Dte::count());
        $this->assertSame(0, PlantaCambioDisponibilidad::whereNotNull('reversion_de_id')->count());
    }
}
