<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\RecepcionInvalidaException;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaRecepcion;
use App\Services\Planta\ReconciliacionExistenciasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Confirmación: el momento en que una recepción se convierte en inventario.
 *
 * Todo lo que puede rechazar la operación se comprueba ANTES de escribir el
 * primer movimiento y con la fila bloqueada, de modo que un rechazo no deje
 * medio documento aplicado. Las pruebas de rechazo verifican siempre las DOS
 * cosas: que falla, y que no dejó rastro.
 */
class PlantaRecepcionConfirmacionTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    // --- Camino feliz ---

    public function test_confirmar_hacia_disponible_genera_movimiento_y_saldo(): void
    {
        $recepcion = $this->borrador();

        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $confirmada->estado);
        $this->assertSame('500.0000', $this->saldo($this->bucketDe($confirmada)));

        $movimiento = PlantaMovimiento::firstOrFail();
        $this->assertSame(TipoMovimientoPlanta::Recepcion, $movimiento->tipo);
        $this->assertSame('confirmar', $movimiento->transicion);
        $this->assertSame(PlantaRecepcion::class, $movimiento->documento_type);
        $this->assertSame($confirmada->id, $movimiento->documento_id);
        $this->assertSame('500.0000', $movimiento->cantidad);
    }

    public function test_la_confirmacion_guarda_quien_y_cuando(): void
    {
        $usuario = $this->admin();
        $recepcion = $this->borrador();

        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        $this->assertSame($usuario->id, $confirmada->confirmado_por);
        $this->assertNotNull($confirmada->confirmado_en);
    }

    public function test_confirmar_hacia_retenido_genera_el_bucket_retenido(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
        ]), $usuario);

        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        $bucket = $this->bucketDe($confirmada);

        $this->assertSame(EstadoDisponibilidad::Retenido, $bucket->estado);
        $this->assertSame('500.0000', $this->saldo($bucket));
    }

    public function test_varias_lineas_generan_un_movimiento_cada_una(): void
    {
        $ubicacion = $this->bodega();
        $uno = $this->insumoConLotes();
        $dos = $this->insumoConLotes();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($uno), $this->linea($dos, ['cantidad_recibida' => '2'])]),
            $usuario,
        );

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        $this->assertSame(2, PlantaMovimiento::count());
        $this->assertSame('500.0000', $this->saldo($this->bucketDe($recepcion->refresh(), 0)));
        $this->assertSame('200.0000', $this->saldo($this->bucketDe($recepcion, 1)));
    }

    public function test_todos_los_movimientos_comparten_grupo(): void
    {
        $ubicacion = $this->bodega();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($this->insumoConLotes()),
            $this->linea($this->insumoConLotes()),
        ]), $usuario);

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        // El grupo agrupa la operación; la idempotencia la da efecto_uid.
        $this->assertCount(1, PlantaMovimiento::pluck('grupo_uuid')->unique());
    }

    // --- Idempotencia ---

    public function test_confirmar_dos_veces_no_duplica_movimientos(): void
    {
        $recepcion = $this->borrador();
        $usuario = $this->admin();

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        try {
            // Doble clic, reintento del navegador, petición repetida.
            $this->servicioRecepcion()->confirmar($recepcion->refresh(), $usuario);
            $this->fail('Una recepción confirmada no debe volver a confirmarse.');
        } catch (RecepcionInvalidaException) {
            // esperado: el estado ya no es borrador
        }

        $this->assertSame(1, PlantaMovimiento::count());
        $this->assertSame('500.0000', $this->saldo($this->bucketDe($recepcion->refresh())));
    }

    public function test_cada_linea_produce_un_efecto_uid_distinto(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $usuario = $this->admin();

        // Dos líneas del MISMO insumo: acabarán en buckets distintos porque cada
        // una crea su propio lote interno, pero aunque coincidieran el uid las
        // distinguiría por documento_detalle_id.
        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($insumo), $this->linea($insumo)]),
            $usuario,
        );

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        $uids = PlantaMovimiento::pluck('efecto_uid');

        $this->assertCount(2, $uids->unique());
        $this->assertCount(2, PlantaMovimiento::pluck('documento_detalle_id')->unique());
    }

    // --- Rechazos ---

    public function test_no_confirma_sin_lineas(): void
    {
        $recepcion = $this->borrador();
        $recepcion->detalles()->delete();

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion->refresh(), $this->admin());
    }

    public function test_rechaza_una_ubicacion_de_transito(): void
    {
        $recepcion = $this->borrador();
        $recepcion->planta_ubicacion_id = $this->transito()->id;
        $recepcion->save();

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion->refresh(), $this->admin());
    }

    public function test_rechaza_una_ubicacion_inactiva(): void
    {
        $recepcion = $this->borrador();
        $recepcion->ubicacion->update(['activo' => false]);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion->refresh(), $this->admin());
    }

    public function test_rechaza_un_proveedor_inactivo(): void
    {
        $ubicacion = $this->bodega();
        $proveedor = $this->proveedor();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload(
            $ubicacion,
            [$this->linea($this->insumoConLotes())],
            ['planta_proveedor_id' => $proveedor->id],
        ), $usuario);

        // El borrador se escribió con el proveedor activo; se desactiva después.
        $proveedor->update(['activo' => false]);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);
    }

    public function test_rechaza_un_insumo_inactivo(): void
    {
        $recepcion = $this->borrador();
        $recepcion->detalles->first()->insumo->update(['activo' => false]);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion->refresh(), $this->admin());
    }

    public function test_un_rechazo_no_deja_ni_movimientos_ni_lotes(): void
    {
        $ubicacion = $this->bodega();
        $bueno = $this->insumoConLotes();
        $malo = $this->insumoConLotes();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($bueno), $this->linea($malo)]),
            $usuario,
        );

        // La SEGUNDA línea es la que falla: la primera ya habría creado su lote y
        // su movimiento si la transacción no cubriera el documento entero.
        $malo->update(['activo' => false]);

        try {
            $this->servicioRecepcion()->confirmar($recepcion, $usuario);
            $this->fail('Se esperaba RecepcionInvalidaException.');
        } catch (RecepcionInvalidaException) {
            // esperado
        }

        $this->assertSame(0, PlantaMovimiento::count());
        $this->assertSame(0, DB::table('planta_existencias')->count());
        $this->assertSame(0, DB::table('planta_lotes')->count());
        $this->assertSame(EstadoRecepcionPlanta::Borrador, $recepcion->refresh()->estado);
    }

    // --- Permiso de calidad ---

    public function test_un_usuario_sin_calidad_no_confirma_hacia_retenido(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $admin = $this->admin();

        // El borrador lo deja el administrador, que sí puede marcar retenido.
        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
        ]), $admin);

        $produccion = $this->usuarioConRol('produccion');

        $this->assertFalse($produccion->can('planta.calidad.gestionar'));

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion, $produccion);
    }

    public function test_un_usuario_sin_calidad_si_confirma_hacia_disponible(): void
    {
        $recepcion = $this->borrador();
        $produccion = $this->usuarioConRol('produccion');

        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $produccion);

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $confirmada->estado);
        $this->assertSame('500.0000', $this->saldo($this->bucketDe($confirmada)));
    }

    public function test_un_usuario_sin_calidad_no_guarda_un_borrador_retenido(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $produccion = $this->usuarioConRol('produccion');

        // El candado está en el SERVICIO, no en el formulario: una petición
        // construida a mano llegaría igual hasta aquí.
        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
        ]), $produccion);
    }

    public function test_rechazado_no_es_un_destino_de_recepcion(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Rechazado->value]),
        ]), $this->admin());
    }

    // --- Auditoría y coherencia ---

    public function test_la_confirmacion_queda_registrada_en_activitylog(): void
    {
        $usuario = $this->admin();
        $recepcion = $this->borrador();

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        $actividad = Activity::where('log_name', 'planta_recepcion')
            ->where('description', 'confirmó la recepción de insumos')->latest('id')->first();

        $this->assertNotNull($actividad);
        $this->assertSame($recepcion->numero, $actividad->properties['numero']);
        $this->assertSame(1, $actividad->properties['lineas']);
        $this->assertSame($usuario->id, $actividad->causer_id);
        $this->assertSame(['disponible' => 1], $actividad->properties['destinos']);
    }

    public function test_el_inventario_reconcilia_despues_de_confirmar(): void
    {
        $ubicacion = $this->bodega();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($this->insumoConLotes()),
            $this->linea($this->insumoSinLotes(), ['cantidad_recibida' => '20', 'unidad_recibida' => 'paquete']),
        ]), $usuario);

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        // El mayor y su proyección coinciden: la recepción no dejó descuadre.
        $this->assertTrue(app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias());
    }

    public function test_el_movimiento_conserva_el_contexto_de_la_recepcion(): void
    {
        $recepcion = $this->borrador();

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $movimiento = PlantaMovimiento::firstOrFail();

        $this->assertSame($recepcion->numero, $movimiento->metadata['recepcion_numero']);
        $this->assertSame('saco', $movimiento->metadata['unidad_recibida']);
        $this->assertSame('0.0000', $movimiento->saldoAntes());
        $this->assertSame('500.0000', $movimiento->saldoDespues());
        $this->assertSame('2026-07-30', $movimiento->fecha_efectiva->toDateString());
    }
}
