<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Exceptions\Planta\RecepcionInvalidaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Ciclo de vida del borrador: crear, editar, anular.
 *
 * Un borrador es lo único editable del documento y lo único que NO toca
 * inventario. Estas pruebas fijan las dos mitades de esa frase: que se puede
 * cambiar libremente mientras es borrador, y que no escribe ni un movimiento
 * mientras lo sea.
 */
class PlantaRecepcionBorradorTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    // --- Creación ---

    public function test_crea_un_borrador_con_varias_lineas(): void
    {
        $ubicacion = $this->bodega();
        $conLotes = $this->insumoConLotes();
        $sinLotes = $this->insumoSinLotes();
        $proveedor = $this->proveedor();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($conLotes),
            $this->linea($sinLotes, ['cantidad_recibida' => '20', 'unidad_recibida' => 'paquete']),
        ], ['planta_proveedor_id' => $proveedor->id]), $this->admin());

        $this->assertSame(EstadoRecepcionPlanta::Borrador, $recepcion->estado);
        $this->assertCount(2, $recepcion->detalles);
        $this->assertSame($proveedor->id, $recepcion->planta_proveedor_id);
    }

    public function test_un_borrador_no_mueve_inventario(): void
    {
        $this->borrador();

        // Es la propiedad que separa un borrador de una confirmación.
        $this->assertSame(0, DB::table('planta_movimientos')->count());
        $this->assertSame(0, DB::table('planta_existencias')->count());
    }

    public function test_el_borrador_guarda_quien_lo_creo(): void
    {
        $usuario = $this->admin();

        $recepcion = $this->borrador($usuario);

        $this->assertSame($usuario->id, $recepcion->creado_por);
        $this->assertNull($recepcion->confirmado_por);
        $this->assertNull($recepcion->confirmado_en);
    }

    // --- Edición ---

    public function test_edita_la_cabecera(): void
    {
        $recepcion = $this->borrador();
        $otraBodega = $this->bodega();

        $actualizada = $this->servicioRecepcion()->actualizarBorrador($recepcion, [
            'fecha' => '2026-08-01',
            'planta_ubicacion_id' => $otraBodega->id,
            'documento_referencia' => 'REM-999',
            'observaciones' => 'llegó incompleto',
            'detalles' => $recepcion->detalles->map(fn ($d) => [
                'id' => $d->id,
                'planta_insumo_id' => $d->planta_insumo_id,
                'cantidad_recibida' => (string) $d->cantidad_recibida,
                'unidad_recibida' => $d->unidad_recibida,
                'contenido_por_unidad' => (string) $d->contenido_por_unidad,
                'factor_conversion' => (string) $d->factor_conversion,
                'estado_destino' => $d->estado_destino->value,
            ])->all(),
        ], $this->admin());

        $this->assertSame('2026-08-01', $actualizada->fecha->toDateString());
        $this->assertSame($otraBodega->id, $actualizada->planta_ubicacion_id);
        $this->assertSame('REM-999', $actualizada->documento_referencia);
    }

    public function test_agrega_modifica_y_elimina_lineas(): void
    {
        $ubicacion = $this->bodega();
        $uno = $this->insumoConLotes();
        $dos = $this->insumoConLotes();
        $tres = $this->insumoConLotes();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($uno), $this->linea($dos)]),
            $usuario,
        );

        $primera = $recepcion->detalles->first();

        // Se conserva la primera con otra cantidad, se quita la segunda y se añade
        // una tercera. La sincronización es por id, no un borrado y alta masivos.
        $actualizada = $this->servicioRecepcion()->actualizarBorrador($recepcion, $this->payload($ubicacion, [
            $this->linea($uno, ['id' => $primera->id, 'cantidad_recibida' => '9']),
            $this->linea($tres),
        ]), $usuario);

        $detalles = $actualizada->detalles()->orderBy('id')->get();

        $this->assertCount(2, $detalles);
        $this->assertSame($primera->id, $detalles[0]->id, 'La línea conservada mantiene su id.');
        $this->assertSame('9.0000', $detalles[0]->cantidad_recibida);
        $this->assertSame($tres->id, $detalles[1]->planta_insumo_id);
        $this->assertSame(0, $actualizada->detalles()->where('planta_insumo_id', $dos->id)->count());
    }

    public function test_la_cantidad_base_se_recalcula_al_editar(): void
    {
        $recepcion = $this->borrador();
        $ubicacion = $recepcion->ubicacion;
        $insumo = $recepcion->detalles->first()->insumo;

        $actualizada = $this->servicioRecepcion()->actualizarBorrador($recepcion, $this->payload($ubicacion, [
            $this->linea($insumo, ['cantidad_recibida' => '3', 'contenido_por_unidad' => '50', 'factor_conversion' => '2']),
        ]), $this->admin());

        // 3 × 50 × 2 = 300
        $this->assertSame('300.0000', $actualizada->detalles->first()->cantidad_base);
    }

    // --- Anulación ---

    public function test_anula_un_borrador(): void
    {
        $recepcion = $this->borrador();

        $anulada = $this->servicioRecepcion()->anular($recepcion);

        $this->assertSame(EstadoRecepcionPlanta::Anulada, $anulada->estado);
        $this->assertSame(0, DB::table('planta_movimientos')->count());
    }

    public function test_la_anulacion_queda_registrada(): void
    {
        $recepcion = $this->borrador();

        $this->servicioRecepcion()->anular($recepcion);

        $actividad = Activity::where('log_name', 'planta_recepcion')
            ->where('description', 'anuló el borrador de recepción')->latest('id')->first();

        $this->assertNotNull($actividad);
        $this->assertSame($recepcion->numero, $actividad->properties['numero']);
    }

    public function test_un_documento_anulado_no_se_confirma(): void
    {
        $recepcion = $this->borrador();
        $this->servicioRecepcion()->anular($recepcion);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion->refresh(), $this->admin());
    }

    public function test_un_documento_anulado_no_se_edita(): void
    {
        $recepcion = $this->borrador();
        $this->servicioRecepcion()->anular($recepcion);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->actualizarBorrador($recepcion->refresh(), $this->payload(
            $recepcion->ubicacion,
            [$this->linea($recepcion->detalles->first()->insumo)],
        ), $this->admin());
    }

    public function test_un_documento_anulado_no_se_reabre_ni_se_anula_dos_veces(): void
    {
        $recepcion = $this->borrador();
        $this->servicioRecepcion()->anular($recepcion);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->anular($recepcion->refresh());
    }

    // --- Inmutabilidad tras confirmar ---

    public function test_una_recepcion_confirmada_no_se_edita(): void
    {
        $recepcion = $this->borrador();
        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->actualizarBorrador($recepcion->refresh(), $this->payload(
            $recepcion->ubicacion,
            [$this->linea($recepcion->detalles->first()->insumo, ['cantidad_recibida' => '999'])],
        ), $this->admin());
    }

    public function test_una_recepcion_confirmada_no_se_anula(): void
    {
        $recepcion = $this->borrador();
        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        // Deshacer una confirmada es REVERSAR, que deja rastro. Anular la haría
        // desaparecer del inventario sin contrapartida.
        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->anular($recepcion->refresh());
    }

    public function test_una_recepcion_confirmada_conserva_sus_lineas(): void
    {
        $recepcion = $this->borrador();
        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $lineasAntes = $recepcion->refresh()->detalles->count();

        try {
            $this->servicioRecepcion()->actualizarBorrador($recepcion, $this->payload(
                $recepcion->ubicacion,
                [],
            ), $this->admin());
        } catch (RecepcionInvalidaException) {
            // esperado
        }

        $this->assertSame($lineasAntes, $recepcion->refresh()->detalles->count());
    }
}
