<?php

namespace Tests\Feature\Dte;

use App\Models\Empresa;
use App\Models\Establecimiento;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CorrelativoAnualMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS = ['dtes', 'establecimientos', 'empresas'];

    /** @var array<string, int> */
    private array $ultimoId = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLAS as $tabla) {
            $this->ultimoId[$tabla] = (int) DB::table($tabla)->max('id');
        }
    }

    protected function tearDown(): void
    {
        // El DDL confirma la transacción en MySQL: restaurar el esquema y borrar las filas nuevas.
        $this->migracion()->up();
        foreach (self::TABLAS as $tabla) {
            DB::table($tabla)->where('id', '>', $this->ultimoId[$tabla])->delete();
        }

        parent::tearDown();
    }

    private function migracion(): object
    {
        return require database_path('migrations/2026_10_20_000001_add_ejercicio_a_dtes.php');
    }

    private function dte(?string $numero, string $fecha, ?int $ejercicio = null): int
    {
        $establecimiento = Establecimiento::firstOr(fn () => Establecimiento::create([
            'empresa_id' => Empresa::create(['razon_social' => 'Emisor de prueba'])->id,
            'codigo' => 'M001', 'nombre' => 'Casa Matriz',
        ]));
        $datos = [
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03',
            'estado' => $numero === null ? 'borrador' : 'aceptado', 'ambiente' => '01',
            'numero_control' => $numero, 'codigo_generacion' => strtoupper((string) Str::uuid()),
            'fecha_emision' => $fecha, 'hora_emision' => '08:00:00', 'total_pagar' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (Schema::hasColumn('dtes', 'ejercicio')) {
            $datos['ejercicio'] = $ejercicio;
        }

        // Insertar sin eventos: se prueban datos anteriores al relleno de la migración.
        return DB::table('dtes')->insertGetId($datos);
    }

    /** @return array<int, array<int, string>> */
    private function unicos(): array
    {
        return collect(Schema::getIndexes('dtes'))
            ->filter(fn (array $i) => $i['unique'] && ! $i['primary'])
            ->pluck('columns')->values()->all();
    }

    private function assertIndicesAplicados(): void
    {
        $this->assertContains(['ambiente', 'ejercicio', 'numero_control'], $this->unicos());
        $this->assertNotContains(['ambiente', 'numero_control'], $this->unicos());
        $this->assertContains('dtes_ambiente_ejercicio_numero_control_unique', array_column(Schema::getIndexes('dtes'), 'name'));
    }

    public function test_borra_el_unico_viejo_con_nombre_de_produccion(): void
    {
        $this->migracion()->down();
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropUnique('dtes_ambiente_numero_control_unique');
            $table->unique(['ambiente', 'numero_control'], 'dtes_control_produccion');
        });

        $this->migracion()->up();

        $this->assertIndicesAplicados();
    }

    public function test_retoma_una_corrida_cortada_y_se_puede_repetir(): void
    {
        $this->migracion()->down();
        $primero = $this->dte('DTE-03-M001P002-000000000000901', '2026-06-01');
        $segundo = $this->dte('DTE-03-M001P002-000000000000902', '2025-06-01');
        Schema::table('dtes', fn (Blueprint $table) => $table->unsignedSmallInteger('ejercicio')->nullable());
        DB::table('dtes')->where('id', $primero)->update(['ejercicio' => 2026]);

        $this->migracion()->up();

        $this->assertSame(2026, (int) DB::table('dtes')->where('id', $primero)->value('ejercicio'));
        $this->assertSame(2025, (int) DB::table('dtes')->where('id', $segundo)->value('ejercicio'));
        $this->assertIndicesAplicados();

        // Un valor ya rellenado se conserva aunque la fecha cambie antes de repetir up().
        DB::table('dtes')->where('id', $primero)->update(['fecha_emision' => '2027-06-01']);
        $this->migracion()->up();

        $this->assertSame(2026, (int) DB::table('dtes')->where('id', $primero)->value('ejercicio'));
        $this->assertIndicesAplicados();
    }

    public function test_rellena_el_anio_solo_para_documentos_con_numero(): void
    {
        $this->migracion()->down();
        $emitido = $this->dte('DTE-03-M001P002-000000000000903', '2026-08-01');
        $borrador = $this->dte(null, '2026-08-01');

        $this->migracion()->up();

        $this->assertSame(2026, (int) DB::table('dtes')->where('id', $emitido)->value('ejercicio'));
        $this->assertNull(DB::table('dtes')->where('id', $borrador)->value('ejercicio'));
    }

    public function test_la_reversa_con_repetidos_no_toca_el_esquema(): void
    {
        foreach ([2026, 2027] as $ejercicio) {
            $this->dte('DTE-03-M001P002-000000000000904', $ejercicio.'-08-01', $ejercicio);
        }
        $indices = Schema::getIndexes('dtes');

        try {
            $this->migracion()->down();
            $this->fail('No se puede restaurar la unicidad anterior con números repetidos.');
        } catch (RuntimeException $e) {
            $this->assertSame('No se puede revertir: hay números de control repetidos entre ejercicios.', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('dtes', 'ejercicio'));
        $this->assertSame($indices, Schema::getIndexes('dtes'));
        $this->assertIndicesAplicados();
        $this->assertSame(2, DB::table('dtes')->where('numero_control', 'DTE-03-M001P002-000000000000904')->count());
    }
}
