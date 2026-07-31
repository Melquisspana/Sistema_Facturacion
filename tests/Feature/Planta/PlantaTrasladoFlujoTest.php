<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\TrasladoInvalidoException;
use App\Models\Planta\PlantaUbicacion;
use App\Services\Planta\ReconciliacionExistenciasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * El recorrido completo de un traslado: borrador → enviado → recibido.
 *
 * Lo que se comprueba una y otra vez es que la mercancía está en UN sitio en
 * cada momento, y que el sitio intermedio —el tránsito— está atado a ESTE viaje.
 * Esa quinta dimensión del bucket es lo que impide que dos envíos del mismo
 * insumo y lote se mezclen, y tiene su prueba propia.
 */
class PlantaTrasladoFlujoTest extends TestCase
{
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    // --- Borrador ---

    public function test_crea_un_borrador_con_varias_lineas(): void
    {
        $e = $this->escenarioTraslado();
        $otraRecepcion = $this->saldoDisponibleEn($e['origen'], '3');
        $otroDetalle = $otraRecepcion->refresh()->detalles->first();

        $traslado = $this->servicioTraslado()->crearBorrador($this->payloadTraslado($e, '100', [
            'detalles' => [
                ['planta_insumo_id' => $e['insumo_id'], 'planta_lote_id' => $e['lote_id'], 'cantidad' => '100'],
                ['planta_insumo_id' => $otroDetalle->planta_insumo_id, 'planta_lote_id' => $otroDetalle->planta_lote_id, 'cantidad' => '50'],
            ],
        ]), $this->admin());

        $this->assertSame(EstadoTrasladoPlanta::Borrador, $traslado->estado);
        $this->assertCount(2, $traslado->detalles);
    }

    public function test_un_borrador_no_mueve_inventario(): void
    {
        $e = $this->escenarioTraslado();
        $huella = $this->huellaMayor();

        $this->borradorTraslado($e);

        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame('500.0000', $this->saldo($this->bucketOrigen($e)));
    }

    public function test_las_lineas_duplicadas_se_fusionan(): void
    {
        $e = $this->escenarioTraslado();

        $traslado = $this->servicioTraslado()->crearBorrador($this->payloadTraslado($e, '0', [
            'detalles' => [
                ['planta_insumo_id' => $e['insumo_id'], 'planta_lote_id' => $e['lote_id'], 'cantidad' => '80'],
                ['planta_insumo_id' => $e['insumo_id'], 'planta_lote_id' => $e['lote_id'], 'cantidad' => '20'],
            ],
        ]), $this->admin());

        // Dos veces el mismo lote es la misma cantidad escrita dos veces.
        $this->assertCount(1, $traslado->detalles);
        $this->assertSame('100.0000', $traslado->detalles->first()->cantidad);
    }

    public function test_edita_el_borrador(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');

        $actualizado = $this->servicioTraslado()->actualizarBorrador(
            $traslado,
            $this->payloadTraslado($e, '250', ['observaciones' => 'va en el pick-up']),
        );

        $this->assertSame('250.0000', $actualizado->detalles->first()->cantidad);
        $this->assertSame('va en el pick-up', $actualizado->observaciones);
    }

    public function test_cancelar_no_mueve_inventario(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e);
        $huella = $this->huellaMayor();

        $cancelado = $this->servicioTraslado()->cancelar($traslado);

        $this->assertSame(EstadoTrasladoPlanta::Cancelado, $cancelado->estado);
        $this->assertSame($huella, $this->huellaMayor());
    }

    public function test_un_cancelado_no_se_envia_ni_se_edita(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e);
        $this->servicioTraslado()->cancelar($traslado);

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->enviar($traslado->refresh(), $this->admin());
    }

    // --- Enviar ---

    public function test_enviar_saca_del_origen_y_deja_en_transito(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');

        $enviado = $this->servicioTraslado()->enviar($traslado, $this->admin());

        $this->assertSame(EstadoTrasladoPlanta::Enviado, $enviado->estado);
        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($e)));
        $this->assertSame('200.0000', $this->saldo($this->bucketTransito($e, $traslado)));
    }

    public function test_al_enviar_el_destino_no_cambia(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');

        $this->servicioTraslado()->enviar($traslado, $this->admin());

        // Todavía no ha llegado: no está disponible en el destino.
        $this->assertNull($this->saldo($this->bucketDestino($e)));
    }

    public function test_enviar_genera_dos_movimientos_por_linea(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');

        $this->servicioTraslado()->enviar($traslado, $this->admin());

        $movimientos = $traslado->refresh()->movimientos()->orderBy('id')->get();

        $this->assertCount(2, $movimientos);
        $this->assertSame(
            [TipoMovimientoPlanta::TrasladoEnvio, TipoMovimientoPlanta::TrasladoEnvio],
            $movimientos->pluck('tipo')->all(),
        );
        $this->assertSame(['enviar', 'enviar'], $movimientos->pluck('transicion')->all());
        // Suman cero: la mercancía cambia de sitio, no de cantidad.
        $this->assertSame('0.0000', bcadd($movimientos[0]->cantidad, $movimientos[1]->cantidad, 4));
        $this->assertCount(1, $movimientos->pluck('grupo_uuid')->unique());
        $this->assertCount(2, $movimientos->pluck('efecto_uid')->unique());
    }

    public function test_el_movimiento_de_transito_lleva_el_id_del_traslado(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');

        $this->servicioTraslado()->enviar($traslado, $this->admin());

        $enTransito = $traslado->refresh()->movimientos()
            ->where('planta_ubicacion_id', $e['transito']->id)->firstOrFail();

        // La quinta dimensión: sin ella, dos viajes se mezclarían.
        $this->assertSame($traslado->id, $enTransito->planta_traslado_id);

        $enOrigen = $traslado->movimientos()->where('planta_ubicacion_id', $e['origen']->id)->firstOrFail();
        $this->assertSame(0, $enOrigen->planta_traslado_id);
    }

    public function test_la_firma_del_envio_queda_guardada(): void
    {
        $e = $this->escenarioTraslado();
        $usuario = $this->admin();
        $traslado = $this->borradorTraslado($e, '200');

        $enviado = $this->servicioTraslado()->enviar($traslado, $usuario);

        $this->assertSame($usuario->id, $enviado->enviado_por);
        $this->assertNotNull($enviado->enviado_en);
        $this->assertNull($enviado->recibido_por);
    }

    // --- Recibir ---

    public function test_recibir_saca_del_transito_y_deja_en_el_destino(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $usuario = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $usuario);
        $recibido = $this->servicioTraslado()->recibir($traslado, $usuario);

        $this->assertSame(EstadoTrasladoPlanta::Recibido, $recibido->estado);
        $this->assertSame('0.0000', $this->saldo($this->bucketTransito($e, $traslado)));
        $this->assertSame('200.0000', $this->saldo($this->bucketDestino($e)));
    }

    public function test_al_recibir_el_origen_no_cambia(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $usuario = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($e)));

        $this->servicioTraslado()->recibir($traslado, $usuario);

        // El origen ya se descontó al enviar: recibir no vuelve a tocarlo.
        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($e)));
    }

    public function test_recibir_genera_dos_movimientos_mas(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $usuario = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->servicioTraslado()->recibir($traslado, $usuario);

        $recepcion = $traslado->refresh()->movimientos()
            ->where('tipo', TipoMovimientoPlanta::TrasladoRecepcion->value)->get();

        $this->assertCount(2, $recepcion);
        $this->assertSame(['recibir', 'recibir'], $recepcion->pluck('transicion')->all());
        $this->assertSame(4, $traslado->movimientos()->count());
    }

    public function test_el_total_del_lote_no_cambia_en_todo_el_recorrido(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $usuario = $this->admin();

        $total = fn () => bcadd((string) DB::table('planta_existencias')
            ->where('planta_lote_id', $e['lote_id'])->sum('cantidad'), '0', 4);

        $this->assertSame('500.0000', $total());
        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->assertSame('500.0000', $total());
        $this->servicioTraslado()->recibir($traslado, $usuario);
        $this->assertSame('500.0000', $total());
    }

    // --- La separación por traslado ---

    public function test_dos_traslados_del_mismo_lote_no_mezclan_su_transito(): void
    {
        $e = $this->escenarioTraslado();
        $usuario = $this->admin();

        $primero = $this->borradorTraslado($e, '100');
        $segundo = $this->borradorTraslado($e, '150');

        $this->servicioTraslado()->enviar($primero, $usuario);
        $this->servicioTraslado()->enviar($segundo, $usuario);

        // Mismo insumo, mismo lote, misma ubicación de tránsito: DOS saldos.
        $this->assertSame('100.0000', $this->saldo($this->bucketTransito($e, $primero)));
        $this->assertSame('150.0000', $this->saldo($this->bucketTransito($e, $segundo)));
        $this->assertSame('250.0000', $this->saldo($this->bucketOrigen($e)));
    }

    public function test_recibir_uno_no_consume_el_transito_del_otro(): void
    {
        $e = $this->escenarioTraslado();
        $usuario = $this->admin();

        $primero = $this->borradorTraslado($e, '100');
        $segundo = $this->borradorTraslado($e, '150');

        $this->servicioTraslado()->enviar($primero, $usuario);
        $this->servicioTraslado()->enviar($segundo, $usuario);
        $this->servicioTraslado()->recibir($primero, $usuario);

        // El primero vació SU tránsito; el del segundo sigue intacto.
        $this->assertSame('0.0000', $this->saldo($this->bucketTransito($e, $primero)));
        $this->assertSame('150.0000', $this->saldo($this->bucketTransito($e, $segundo)));
        $this->assertSame('100.0000', $this->saldo($this->bucketDestino($e)));
        $this->assertSame(EstadoTrasladoPlanta::Enviado, $segundo->refresh()->estado);
    }

    // --- Rechazos ---

    public function test_saldo_insuficiente_en_origen_falla(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '500.0001');

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->enviar($traslado, $this->admin());
    }

    public function test_un_envio_fallido_no_deja_rastro(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '9999');
        $huella = $this->huellaMayor();

        try {
            $this->servicioTraslado()->enviar($traslado, $this->admin());
        } catch (TrasladoInvalidoException) {
            // esperado
        }

        // Ni medio par aplicado.
        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame('500.0000', $this->saldo($this->bucketOrigen($e)));
        $this->assertNull($this->saldo($this->bucketTransito($e, $traslado)));
        $this->assertSame(EstadoTrasladoPlanta::Borrador, $traslado->refresh()->estado);
    }

    public function test_una_linea_que_falla_revierte_las_anteriores(): void
    {
        $e = $this->escenarioTraslado();
        $otraRecepcion = $this->saldoDisponibleEn($e['origen'], '1');
        $otroDetalle = $otraRecepcion->refresh()->detalles->first();

        // La primera línea alcanza; la segunda no.
        $traslado = $this->servicioTraslado()->crearBorrador($this->payloadTraslado($e, '0', [
            'detalles' => [
                ['planta_insumo_id' => $e['insumo_id'], 'planta_lote_id' => $e['lote_id'], 'cantidad' => '100'],
                ['planta_insumo_id' => $otroDetalle->planta_insumo_id, 'planta_lote_id' => $otroDetalle->planta_lote_id, 'cantidad' => '9999'],
            ],
        ]), $this->admin());

        $huella = $this->huellaMayor();

        try {
            $this->servicioTraslado()->enviar($traslado, $this->admin());
            $this->fail('Se esperaba TrasladoInvalidoException.');
        } catch (TrasladoInvalidoException) {
            // esperado
        }

        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame('500.0000', $this->saldo($this->bucketOrigen($e)));
    }

    public function test_no_traslada_saldo_retenido(): void
    {
        $origen = $this->bodega();
        $destino = $this->bodega();
        $this->transitoDelSistema();
        $admin = $this->admin();

        // Todo el saldo entra RETENIDO: no hay nada disponible que trasladar.
        $insumo = $this->insumoConLotes();
        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($origen, [
            $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
        ]), $admin);
        $this->servicioRecepcion()->confirmar($recepcion, $admin);

        $detalle = $recepcion->refresh()->detalles->first();

        $traslado = $this->servicioTraslado()->crearBorrador([
            'fecha' => '2026-07-30',
            'planta_ubicacion_origen_id' => $origen->id,
            'planta_ubicacion_destino_id' => $destino->id,
            'detalles' => [['planta_insumo_id' => $insumo->id, 'planta_lote_id' => $detalle->planta_lote_id, 'cantidad' => '100']],
        ], $admin);

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->enviar($traslado, $admin);
    }

    public function test_origen_igual_a_destino_falla(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');

        DB::table('planta_traslados')->where('id', $traslado->id)
            ->update(['planta_ubicacion_destino_id' => $e['origen']->id]);

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->enviar($traslado->refresh(), $this->admin());
    }

    public function test_una_ubicacion_de_transito_como_destino_falla(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');

        DB::table('planta_traslados')->where('id', $traslado->id)
            ->update(['planta_ubicacion_destino_id' => $e['transito']->id]);

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->enviar($traslado->refresh(), $this->admin());
    }

    public function test_una_ubicacion_inactiva_falla(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');

        $e['destino']->update(['activo' => false]);

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->enviar($traslado->refresh(), $this->admin());
    }

    public function test_sin_ubicacion_de_transito_el_envio_falla_con_mensaje_claro(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');

        // Se retira la de tránsito: el servicio NO debe crearla sobre la marcha.
        PlantaUbicacion::whereKey($e['transito']->id)->update(['activo' => false]);

        // La huella se toma DESPUÉS de la recepción del escenario: lo que se
        // comprueba es que el envío fallido no añade nada, no que el mayor esté
        // vacío.
        $huella = $this->huellaMayor();

        try {
            $this->servicioTraslado()->enviar($traslado, $this->admin());
            $this->fail('Se esperaba TrasladoInvalidoException.');
        } catch (TrasladoInvalidoException $ex) {
            $this->assertStringContainsString('TRÁNSITO', $ex->getMessage());
            $this->assertStringContainsString('No se crea automáticamente', $ex->getMessage());
        }

        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame(EstadoTrasladoPlanta::Borrador, $traslado->refresh()->estado);
    }

    public function test_dos_ubicaciones_de_transito_son_ambiguas(): void
    {
        $e = $this->escenarioTraslado();

        // Una segunda que cumple los mismos requisitos. El código es distinto
        // porque es único; lo ambiguo no es el nombre, es que haya dos sitios
        // donde podría estar lo que viaja.
        PlantaUbicacion::factory()->transito()->create([
            'codigo' => 'TRANSITO2', 'es_sistema' => true,
            'activo' => true, 'permite_operacion_manual' => false,
        ]);

        $traslado = $this->borradorTraslado($e, '100');

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->enviar($traslado, $this->admin());
    }

    // --- Idempotencia e inmutabilidad ---

    public function test_doble_envio_no_duplica_movimientos(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $usuario = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $usuario);

        try {
            $this->servicioTraslado()->enviar($traslado->refresh(), $usuario);
            $this->fail('Un traslado enviado no debe volver a enviarse.');
        } catch (TrasladoInvalidoException) {
            // esperado
        }

        $this->assertSame(2, $traslado->refresh()->movimientos()->count());
        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($e)));
    }

    public function test_doble_recepcion_falla(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $usuario = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->servicioTraslado()->recibir($traslado, $usuario);

        try {
            $this->servicioTraslado()->recibir($traslado->refresh(), $usuario);
            $this->fail('Un traslado recibido no debe volver a recibirse.');
        } catch (TrasladoInvalidoException) {
            // esperado
        }

        $this->assertSame(4, $traslado->refresh()->movimientos()->count());
        $this->assertSame('200.0000', $this->saldo($this->bucketDestino($e)));
    }

    public function test_no_se_recibe_un_borrador(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->recibir($traslado, $this->admin());
    }

    public function test_un_enviado_no_se_edita_ni_se_cancela(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');
        $this->servicioTraslado()->enviar($traslado, $this->admin());

        try {
            $this->servicioTraslado()->actualizarBorrador($traslado->refresh(), $this->payloadTraslado($e, '999'));
            $this->fail('Se esperaba TrasladoInvalidoException.');
        } catch (TrasladoInvalidoException) {
            // esperado
        }

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->cancelar($traslado->refresh());
    }

    public function test_un_recibido_es_inmutable(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '100');
        $usuario = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->servicioTraslado()->recibir($traslado, $usuario);

        $this->expectException(TrasladoInvalidoException::class);

        $this->servicioTraslado()->actualizarBorrador($traslado->refresh(), $this->payloadTraslado($e, '999'));
    }

    // --- Coherencia y auditoría ---

    public function test_el_inventario_reconcilia_en_cada_etapa(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $usuario = $this->admin();
        $reconciliar = fn () => app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias();

        $this->assertTrue($reconciliar());
        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->assertTrue($reconciliar());
        $this->servicioTraslado()->recibir($traslado, $usuario);
        $this->assertTrue($reconciliar());
    }

    public function test_el_envio_y_la_recepcion_quedan_registrados(): void
    {
        $e = $this->escenarioTraslado();
        $usuario = $this->admin();
        $traslado = $this->borradorTraslado($e, '200');

        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->servicioTraslado()->recibir($traslado, $usuario);

        $envio = Activity::where('log_name', 'planta_traslado')->where('description', 'envió el traslado')->latest('id')->first();
        $recepcion = Activity::where('log_name', 'planta_traslado')->where('description', 'recibió el traslado')->latest('id')->first();

        $this->assertNotNull($envio);
        $this->assertSame($traslado->numero, $envio->properties['numero']);
        $this->assertSame($e['origen']->codigo, $envio->properties['origen']);
        $this->assertSame($usuario->id, $envio->causer_id);

        $this->assertNotNull($recepcion);
        $this->assertSame($e['destino']->codigo, $recepcion->properties['destino']);
    }

    public function test_ningun_saldo_queda_negativo(): void
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '500');
        $usuario = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $usuario);
        $this->servicioTraslado()->recibir($traslado, $usuario);

        $this->assertSame(0, DB::table('planta_existencias')->where('cantidad', '<', 0)->count());
        $this->assertSame('0.0000', $this->saldo($this->bucketOrigen($e)));
        $this->assertSame('500.0000', $this->saldo($this->bucketDestino($e)));
    }

    public function test_los_lotes_disponibles_solo_muestran_saldo_disponible(): void
    {
        $e = $this->escenarioTraslado();

        $disponibles = $this->servicioTraslado()->lotesDisponiblesEn($e['origen']->id);

        $this->assertCount(1, $disponibles);
        $this->assertSame($e['lote_id'], (int) $disponibles->first()->planta_lote_id);

        // Tras enviarlo todo, el origen deja de ofrecerlo.
        $traslado = $this->borradorTraslado($e, '500');
        $this->servicioTraslado()->enviar($traslado, $this->admin());

        $this->assertCount(0, $this->servicioTraslado()->lotesDisponiblesEn($e['origen']->id));
        // Y el tránsito nunca se ofrece como origen operable.
        $this->assertCount(0, $this->servicioTraslado()->lotesDisponiblesEn($e['transito']->id));
    }
}
