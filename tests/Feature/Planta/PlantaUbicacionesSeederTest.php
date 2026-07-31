<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\TipoUbicacion;
use App\Exceptions\Planta\UbicacionSistemaProtegidaException;
use App\Models\Planta\PlantaUbicacion;
use Database\Seeders\PlantaUbicacionesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * El seeder de las tres ubicaciones con las que opera Planta, y el candado que
 * protege a la de sistema.
 *
 * Lo que más importa aquí no es que cree tres filas: es que NO reescriba en
 * silencio una ubicación ajena que ya tenga historial. `updateOrCreate` por
 * código es cómodo y peligroso a la vez, y cambiarle el tipo a una ubicación con
 * saldo movería inventario real de categoría sin que nadie lo pidiera.
 */
class PlantaUbicacionesSeederTest extends TestCase
{
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    private function sembrar(): void
    {
        $this->seed(PlantaUbicacionesSeeder::class);
    }

    // --- Lo que crea ---

    public function test_crea_exactamente_las_tres_ubicaciones(): void
    {
        $this->sembrar();

        $this->assertSame(3, PlantaUbicacion::count());
        $this->assertEqualsCanonicalizing(
            ['CASA', 'FABRICA', 'TRANSITO'],
            PlantaUbicacion::pluck('codigo')->all(),
        );
    }

    public function test_casa_y_fabrica_son_fisicas_y_manuales(): void
    {
        $this->sembrar();

        foreach (['CASA' => 'Casa', 'FABRICA' => 'Fábrica'] as $codigo => $nombre) {
            $ubicacion = PlantaUbicacion::where('codigo', $codigo)->firstOrFail();

            $this->assertSame($nombre, $ubicacion->nombre);
            $this->assertSame(TipoUbicacion::Fisica, $ubicacion->tipo);
            $this->assertFalse($ubicacion->es_sistema);
            $this->assertTrue($ubicacion->permite_operacion_manual);
            $this->assertTrue($ubicacion->activo);
        }

        $this->assertSame(10, PlantaUbicacion::where('codigo', 'CASA')->value('orden'));
        $this->assertSame(20, PlantaUbicacion::where('codigo', 'FABRICA')->value('orden'));
    }

    public function test_transito_es_de_sistema_y_no_admite_operacion_manual(): void
    {
        $this->sembrar();

        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();

        $this->assertSame('En tránsito', $transito->nombre);
        $this->assertSame(TipoUbicacion::Transito, $transito->tipo);
        $this->assertTrue($transito->es_sistema);
        // Lo que la define: por ahí se pasa, no se opera.
        $this->assertFalse($transito->permite_operacion_manual);
        $this->assertTrue($transito->activo);
        $this->assertSame(99, $transito->orden);
    }

    // --- Idempotencia ---

    public function test_ejecutarlo_dos_veces_no_duplica(): void
    {
        $this->sembrar();
        $idsPrimera = PlantaUbicacion::orderBy('codigo')->pluck('id')->all();

        $this->sembrar();

        $this->assertSame(3, PlantaUbicacion::count());
        // Las MISMAS filas: no se borran y se recrean.
        $this->assertSame($idsPrimera, PlantaUbicacion::orderBy('codigo')->pluck('id')->all());
    }

    public function test_la_segunda_ejecucion_refresca_nombre_y_orden(): void
    {
        $this->sembrar();

        // Alguien renombró CASA a mano; la segunda pasada la devuelve al canon.
        PlantaUbicacion::where('codigo', 'CASA')->update(['nombre' => 'Bodega vieja', 'orden' => 5]);

        $this->sembrar();

        $casa = PlantaUbicacion::where('codigo', 'CASA')->firstOrFail();
        $this->assertSame('Casa', $casa->nombre);
        $this->assertSame(10, $casa->orden);
    }

    public function test_los_codigos_son_unicos(): void
    {
        $this->sembrar();

        $codigos = PlantaUbicacion::pluck('codigo');

        $this->assertSame($codigos->count(), $codigos->unique()->count());

        $this->expectException(QueryException::class);

        PlantaUbicacion::factory()->create(['codigo' => 'TRANSITO']);
    }

    // --- No toca lo ajeno ---

    public function test_no_modifica_ubicaciones_ajenas(): void
    {
        $ajena = PlantaUbicacion::factory()->create([
            'codigo' => 'BODEGA-X', 'nombre' => 'Bodega del vecino', 'orden' => 7,
        ]);

        $this->sembrar();

        $ajena->refresh();

        $this->assertSame('Bodega del vecino', $ajena->nombre);
        $this->assertSame(7, $ajena->orden);
        $this->assertSame(4, PlantaUbicacion::count());
    }

    public function test_no_siembra_ningun_otro_dato(): void
    {
        $this->sembrar();

        // Infraestructura del módulo, no datos de la operación.
        $this->assertSame(0, DB::table('planta_proveedores')->count());
        $this->assertSame(0, DB::table('planta_insumos')->count());
        $this->assertSame(0, DB::table('planta_lotes')->count());
        $this->assertSame(0, DB::table('planta_movimientos')->count());
    }

    // --- Conflicto con historial ---

    public function test_corrige_una_fila_incompatible_que_esta_vacia(): void
    {
        // Alguien creó TRANSITO como bodega normal, pero nunca la usó.
        PlantaUbicacion::factory()->create([
            'codigo' => 'TRANSITO', 'tipo' => TipoUbicacion::Fisica->value,
            'es_sistema' => false, 'permite_operacion_manual' => true,
        ]);

        $this->sembrar();

        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();

        // Sin historial no hay riesgo: arreglarla es justo lo que hace falta.
        $this->assertSame(TipoUbicacion::Transito, $transito->tipo);
        $this->assertTrue($transito->es_sistema);
    }

    public function test_aborta_si_la_fila_incompatible_ya_tiene_historial(): void
    {
        // TRANSITO existe como bodega FÍSICA y además ya recibió mercancía.
        $falsoTransito = PlantaUbicacion::factory()->create([
            'codigo' => 'TRANSITO', 'tipo' => TipoUbicacion::Fisica->value,
            'es_sistema' => false, 'permite_operacion_manual' => true,
        ]);

        $this->saldoDisponibleEn($falsoTransito, '5');

        try {
            $this->sembrar();
            $this->fail('Se esperaba que el seeder abortara.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TRANSITO', $e->getMessage());
            $this->assertStringContainsString('historial', $e->getMessage());
            $this->assertStringContainsString('movería inventario real', $e->getMessage());
        }

        // Y no la reescribió: sigue siendo la bodega física que era.
        $falsoTransito->refresh();
        $this->assertSame(TipoUbicacion::Fisica, $falsoTransito->tipo);
        $this->assertFalse($falsoTransito->es_sistema);
    }

    public function test_un_conflicto_no_deja_el_seeder_a_medias_en_lo_incompatible(): void
    {
        $falso = PlantaUbicacion::factory()->create([
            'codigo' => 'TRANSITO', 'tipo' => TipoUbicacion::Fisica->value, 'es_sistema' => false,
        ]);
        $this->saldoDisponibleEn($falso, '5');

        try {
            $this->sembrar();
        } catch (RuntimeException) {
            // esperado
        }

        // CASA y FABRICA sí se crearon (van antes y no tenían conflicto); lo que
        // NO ocurrió es la reescritura peligrosa.
        $this->assertSame(TipoUbicacion::Fisica, $falso->refresh()->tipo);
    }

    // --- Candado de la ubicación de sistema ---

    public function test_no_se_puede_desactivar_transito(): void
    {
        $this->sembrar();
        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();

        $transito->activo = false;

        $this->expectException(UbicacionSistemaProtegidaException::class);

        $transito->save();
    }

    public function test_no_se_puede_cambiar_el_codigo_de_transito(): void
    {
        $this->sembrar();
        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();

        $transito->codigo = 'OTRO';

        $this->expectException(UbicacionSistemaProtegidaException::class);

        $transito->save();
    }

    public function test_no_se_puede_cambiar_el_tipo_ni_la_condicion_de_sistema(): void
    {
        $this->sembrar();

        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();
        $transito->tipo = TipoUbicacion::Fisica->value;

        try {
            $transito->save();
            $this->fail('Se esperaba UbicacionSistemaProtegidaException.');
        } catch (UbicacionSistemaProtegidaException) {
            // esperado
        }

        $otra = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();
        $otra->es_sistema = false;

        $this->expectException(UbicacionSistemaProtegidaException::class);

        $otra->save();
    }

    public function test_no_se_puede_habilitar_la_operacion_manual_en_transito(): void
    {
        $this->sembrar();
        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();

        // Permitirla dejaría recibir o ajustar mercancía directamente en tránsito,
        // que es exactamente lo que esa ubicación existe para impedir.
        $transito->permite_operacion_manual = true;

        $this->expectException(UbicacionSistemaProtegidaException::class);

        $transito->save();
    }

    public function test_no_se_puede_eliminar_transito(): void
    {
        $this->sembrar();
        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();

        $this->expectException(UbicacionSistemaProtegidaException::class);

        $transito->delete();
    }

    public function test_el_candado_no_alcanza_a_las_bodegas_normales(): void
    {
        $this->sembrar();
        $casa = PlantaUbicacion::where('codigo', 'CASA')->firstOrFail();

        $casa->nombre = 'Casa matriz';
        $casa->activo = false;
        $casa->save();

        $this->assertSame('Casa matriz', $casa->refresh()->nombre);
        $this->assertFalse($casa->activo);
    }

    public function test_si_se_puede_renombrar_transito(): void
    {
        $this->sembrar();
        $transito = PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail();

        // El nombre es etiqueta, no identidad: cambiarlo no rompe nada.
        $transito->nombre = 'En camino';
        $transito->save();

        $this->assertSame('En camino', $transito->refresh()->nombre);
    }

    // --- Integración con los traslados ---

    public function test_el_servicio_de_traslados_resuelve_el_transito_sembrado(): void
    {
        $this->sembrar();

        $transito = $this->servicioTraslado()->resolverTransito();

        $this->assertSame('TRANSITO', $transito->codigo);
        $this->assertTrue($transito->es_sistema);
        $this->assertSame(TipoUbicacion::Transito, $transito->tipo);
    }

    public function test_un_traslado_completo_funciona_con_las_ubicaciones_sembradas(): void
    {
        $this->sembrar();

        $casa = PlantaUbicacion::where('codigo', 'CASA')->firstOrFail();
        $fabrica = PlantaUbicacion::where('codigo', 'FABRICA')->firstOrFail();
        $admin = $this->admin();

        $recepcion = $this->saldoDisponibleEn($casa, '5');
        $detalle = $recepcion->refresh()->detalles->first();

        $escenario = [
            'origen' => $casa, 'destino' => $fabrica,
            'transito' => PlantaUbicacion::where('codigo', 'TRANSITO')->firstOrFail(),
            'insumo_id' => (int) $detalle->planta_insumo_id,
            'lote_id' => (int) $detalle->planta_lote_id,
        ];

        $traslado = $this->servicioTraslado()->crearBorrador(
            $this->payloadTraslado($escenario, '200'),
            $admin,
        );

        $this->servicioTraslado()->enviar($traslado, $admin);
        $this->assertSame('200.0000', $this->saldo($this->bucketTransito($escenario, $traslado)));

        $this->servicioTraslado()->recibir($traslado, $admin);
        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($escenario)));
        $this->assertSame('200.0000', $this->saldo($this->bucketDestino($escenario)));
    }
}
