<?php

namespace Tests\Feature\Planta;

use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaPresentacion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Esquema de `planta_empaque_configs` y comportamiento de las tres columnas
 * generadas `STORED`.
 *
 * Lo que estas pruebas demuestran es lo que motivó usar columnas generadas en
 * vez de mutators: las derivadas se mantienen SOLAS aunque la escritura no pase
 * por Eloquent, y el motor rechaza escribirlas a mano.
 */
class PlantaEmpaqueEsquemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = '2026_07_28_110000_create_planta_empaque_configs_table';

    private function migracion(): object
    {
        return require database_path('migrations/'.self::MIGRACION.'.php');
    }

    /** Crea presentación y bolsa reales para poder insertar filas válidas. */
    private function contexto(): array
    {
        return [
            'presentacion' => PlantaPresentacion::factory()->create(),
            'bolsa' => PlantaInsumo::factory()->bolsa()->create(),
            'vinieta' => PlantaInsumo::factory()->vinieta()->create(),
        ];
    }

    // --- Estructura ---

    public function test_la_tabla_existe_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('planta_empaque_configs'));

        $this->assertTrue(Schema::hasColumns('planta_empaque_configs', [
            'id', 'planta_presentacion_id', 'planta_insumo_bolsa_id', 'planta_insumo_vinieta_id',
            'marca', 'mercado', 'referencia_cliente', 'es_predeterminada', 'activo',
            'vigente_desde', 'vigente_hasta',
            'marca_norm', 'vinieta_key', 'predeterminada_key',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    // --- Columnas generadas ---

    public function test_marca_norm_normaliza_nulo_espacios_y_mayusculas(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        $sinMarca = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => null,
        ]);
        $conMarca = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
            'marca' => '  la negrita  ', 'mercado' => 'exportacion',
        ]);

        $this->assertSame('', $sinMarca->fresh()->marca_norm);
        $this->assertSame('LA NEGRITA', $conMarca->fresh()->marca_norm);
    }

    public function test_vinieta_key_convierte_el_nulo_en_cero(): void
    {
        ['presentacion' => $p, 'bolsa' => $b, 'vinieta' => $v] = $this->contexto();

        $sin = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
            'planta_insumo_vinieta_id' => null,
        ]);
        $con = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
            'planta_insumo_vinieta_id' => $v->id, 'mercado' => 'exportacion',
        ]);

        $this->assertSame(0, $sin->fresh()->vinieta_key);
        $this->assertSame($v->id, $con->fresh()->vinieta_key);
    }

    public function test_predeterminada_key_vale_el_mercado_solo_si_es_predeterminada(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        $normal = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'A',
        ]);
        $predeterminada = PlantaEmpaqueConfig::factory()->predeterminada()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'B',
        ]);

        $this->assertNull($normal->fresh()->predeterminada_key);
        $this->assertSame('nacional', $predeterminada->fresh()->predeterminada_key);
    }

    public function test_las_derivadas_se_recalculan_sin_pasar_por_eloquent(): void
    {
        // Es la razón de ser de las columnas generadas: un update masivo salta
        // los mutators, pero no puede saltarse al motor.
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        $config = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'vieja',
        ]);

        DB::table('planta_empaque_configs')->where('id', $config->id)->update(['marca' => '  nueva marca ']);

        $this->assertSame('NUEVA MARCA', $config->fresh()->marca_norm);
    }

    public function test_el_motor_rechaza_escribir_una_columna_generada(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();
        $config = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
        ]);

        $this->expectException(QueryException::class);

        DB::table('planta_empaque_configs')->where('id', $config->id)->update(['marca_norm' => 'FALSIFICADA']);
    }

    public function test_las_derivadas_no_son_asignables_en_masa(): void
    {
        $fillable = (new PlantaEmpaqueConfig)->getFillable();

        foreach (PlantaEmpaqueConfig::DERIVADAS as $derivada) {
            $this->assertNotContains($derivada, $fillable, "{$derivada} no debe ser fillable.");
        }
    }

    // --- Índices únicos ---

    public function test_se_rechaza_una_duplicada_con_marca_y_vinieta_nulas(): void
    {
        // El caso que los NULL habrían dejado pasar sin las columnas derivadas.
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        $datos = [
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
            'planta_insumo_vinieta_id' => null, 'marca' => null, 'mercado' => 'nacional',
        ];

        PlantaEmpaqueConfig::factory()->create($datos);

        $this->expectException(QueryException::class);

        PlantaEmpaqueConfig::factory()->create($datos);
    }

    public function test_se_rechaza_una_duplicada_que_solo_cambia_mayusculas_o_espacios(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'La Negrita',
        ]);

        $this->expectException(QueryException::class);

        PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => '  LA NEGRITA ',
        ]);
    }

    public function test_solo_una_predeterminada_por_presentacion_y_mercado(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        PlantaEmpaqueConfig::factory()->predeterminada()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'A',
        ]);

        $this->expectException(QueryException::class);

        PlantaEmpaqueConfig::factory()->predeterminada()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'B',
        ]);
    }

    public function test_se_admiten_predeterminadas_distintas_por_mercado(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        PlantaEmpaqueConfig::factory()->predeterminada()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'A',
        ]);
        PlantaEmpaqueConfig::factory()->predeterminada()->exportacion()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => 'A',
        ]);

        $this->assertSame(2, PlantaEmpaqueConfig::where('es_predeterminada', true)->count());
    }

    public function test_conviven_muchas_no_predeterminadas(): void
    {
        // `predeterminada_key` es NULL en todas, y los NULL no chocan entre sí.
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        foreach (['A', 'B', 'C'] as $marca) {
            PlantaEmpaqueConfig::factory()->create([
                'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => $marca,
            ]);
        }

        $this->assertSame(3, PlantaEmpaqueConfig::count());
    }

    // --- Claves foráneas ---

    public function test_no_se_puede_eliminar_una_presentacion_con_configuraciones(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();
        PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
        ]);

        $this->expectException(QueryException::class);

        $p->forceDelete();
    }

    public function test_no_se_puede_eliminar_un_insumo_usado_como_bolsa(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();
        PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
        ]);

        $this->expectException(QueryException::class);

        $b->forceDelete();
    }

    // --- Relaciones ---

    public function test_las_relaciones_del_modelo_resuelven(): void
    {
        ['presentacion' => $p, 'bolsa' => $b, 'vinieta' => $v] = $this->contexto();

        $config = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id,
            'planta_insumo_bolsa_id' => $b->id,
            'planta_insumo_vinieta_id' => $v->id,
        ]);

        $this->assertTrue($config->presentacion->is($p));
        $this->assertTrue($config->bolsa->is($b));
        $this->assertTrue($config->vinieta->is($v));
        $this->assertTrue($p->empaqueConfigs->contains($config));
        $this->assertTrue($b->configsComoBolsa->contains($config));
        $this->assertTrue($v->configsComoVinieta->contains($config));
        $this->assertTrue($p->productoBase->empaqueConfigs->contains($config));
    }

    public function test_la_vinieta_puede_ser_nula(): void
    {
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();

        $config = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id,
            'planta_insumo_vinieta_id' => null,
        ]);

        $this->assertNull($config->vinieta);
        $this->assertSame(0, $config->fresh()->vinieta_key);
    }

    // --- Reversibilidad ---

    public function test_el_rollback_elimina_solo_planta_empaque_configs(): void
    {
        $this->assertTrue(Schema::hasTable('planta_empaque_configs'));

        $this->migracion()->down();

        $this->assertFalse(Schema::hasTable('planta_empaque_configs'));

        // Los seis catálogos del paso 2 siguen intactos.
        foreach ([
            'planta_ubicaciones', 'planta_proveedores', 'planta_insumos',
            'planta_lotes', 'planta_productos_base', 'planta_presentaciones',
        ] as $previa) {
            $this->assertTrue(Schema::hasTable($previa), "El rollback no debe tocar {$previa}.");
        }
    }

    public function test_volver_a_migrar_recrea_la_tabla_con_sus_derivadas(): void
    {
        $migracion = $this->migracion();
        $migracion->down();
        $migracion->up();

        $this->assertTrue(Schema::hasTable('planta_empaque_configs'));

        foreach (PlantaEmpaqueConfig::DERIVADAS as $derivada) {
            $this->assertTrue(Schema::hasColumn('planta_empaque_configs', $derivada));
        }

        // Y siguen calculando: no es solo que la columna exista.
        ['presentacion' => $p, 'bolsa' => $b] = $this->contexto();
        $config = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $p->id, 'planta_insumo_bolsa_id' => $b->id, 'marca' => ' re migrada ',
        ]);

        $this->assertSame('RE MIGRADA', $config->fresh()->marca_norm);
    }
}
