<?php

namespace Tests\Feature\Cobros;

use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PpqLote;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * #121 · La migración que agrega `ejercicio_control` corre en producción sobre los datos
 * reales de 2026: no puede fallar con ellos ni adivinarles un año. Antes del reinicio del
 * correlativo (2027) el número de control ya era único, así que todo lo existente queda en
 * 2026, tenga fecha, la tome del DTE o no tenga ninguna.
 *
 * En MySQL el DDL de `down()`/`up()` confirma la transacción de RefreshDatabase, así que
 * lo que la prueba escribió quedaría para las siguientes. Por eso al cerrar se borran las
 * filas nuevas de las tablas que toca y la migración queda aplicada, en los dos motores.
 */
class IdentidadPorEjercicioMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = '2026_10_21_120000_identidad_por_ejercicio_en_cobros_y_ppq.php';

    /** En orden de borrado: primero las que apuntan a las otras. */
    private const TABLAS = ['cobro_documentos', 'ppq_items', 'ppq_lotes', 'dtes', 'establecimientos', 'empresas', 'clientes'];

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
        if (! Schema::hasColumn('cobro_documentos', 'ejercicio_control')) {
            $this->migracion()->up();
        }
        foreach (self::TABLAS as $tabla) {
            DB::table($tabla)->where('id', '>', $this->ultimoId[$tabla])->delete();
        }

        parent::tearDown();
    }

    private function migracion(): object
    {
        return require database_path('migrations/'.self::MIGRACION);
    }

    private function dte(string $numero, string $fecha): Dte
    {
        $establecimiento = Establecimiento::firstOr(fn () => Establecimiento::create([
            'empresa_id' => Empresa::create(['razon_social' => 'Emisor de prueba'])->id,
            'codigo' => 'M001', 'nombre' => 'Casa Matriz',
        ]));

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'numero_control' => $numero, 'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'fecha_emision' => $fecha, 'hora_emision' => '08:00:00', 'total_pagar' => 100,
        ]);
    }

    private function documentoViejo(int $clienteId, int $n, ?string $fecha, ?int $dteId = null): int
    {
        $numero = 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT);

        return DB::table('cobro_documentos')->insertGetId([
            'cliente_id' => $clienteId, 'origen' => $dteId ? 'dte' : 'externo', 'dte_id' => $dteId, 'tipo_dte' => '03',
            'numero_control' => $numero, 'numero_control_norm' => str_replace('-', '', $numero),
            'fecha_emision' => $fecha, 'monto' => '100.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function itemViejo(int $loteId, int $n, ?string $fecha, ?int $dteId = null): int
    {
        return DB::table('ppq_items')->insertGetId([
            'ppq_lote_id' => $loteId, 'dte_id' => $dteId, 'origen' => $dteId ? 'local' : 'gmail', 'tipo_dte' => '03',
            'numero_control' => $dteId ? null : 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_documento' => $fecha, 'monto_dte' => 100, 'sin_albaran' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_los_datos_de_2026_quedan_en_2026_tengan_fecha_o_no(): void
    {
        $this->migracion()->down();

        $cliente = Cliente::factory()->create();
        $lote = PpqLote::create(['referencia' => 'PPQ viejo', 'fecha' => '2026-09-04', 'estado' => 'listo']);
        $dte = $this->dte('DTE-03-M001P002-000000000000903', '2026-08-01');

        $documentos = [
            $this->documentoViejo($cliente->id, 901, '2026-09-10'),
            $this->documentoViejo($cliente->id, 902, '2025-11-03'),
            $this->documentoViejo($cliente->id, 903, null, $dte->id),
            $this->documentoViejo($cliente->id, 904, null),
        ];
        $items = [
            $this->itemViejo($lote->id, 901, '2026-09-10'),
            $this->itemViejo($lote->id, 902, '2025-11-03'),
            $this->itemViejo($lote->id, 903, null, $dte->id),
            $this->itemViejo($lote->id, 904, null),
        ];

        $this->migracion()->up();

        $this->assertSame([2026, 2026, 2026, 2026], DB::table('cobro_documentos')->whereIn('id', $documentos)->orderBy('id')->pluck('ejercicio_control')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([2026, 2026, 2026, 2026], DB::table('ppq_items')->whereIn('id', $items)->orderBy('id')->pluck('ejercicio_control')->map(fn ($v) => (int) $v)->all());

        // El único nuevo es por número + ejercicio.
        $de2027 = CobroDocumento::create(['cliente_id' => $cliente->id, 'origen' => 'externo', 'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000901', 'fecha_emision' => '2027-01-15', 'monto' => '1.00']);
        try {
            CobroDocumento::create(['cliente_id' => $cliente->id, 'origen' => 'externo', 'tipo_dte' => '03',
                'numero_control' => 'DTE-03-M001P002-000000000000901', 'fecha_emision' => '2026-12-01', 'monto' => '1.00']);
            $this->fail('El mismo número no cabe dos veces en 2026.');
        } catch (QueryException) {
            // esperado
        }
        $de2027->delete();
    }

    public function test_la_reversa_se_niega_si_ya_hay_numeros_repetidos_entre_anios(): void
    {
        $cliente = Cliente::factory()->create();
        foreach (['2026-12-20', '2027-01-15'] as $fecha) {
            CobroDocumento::create(['cliente_id' => $cliente->id, 'origen' => 'externo', 'tipo_dte' => '03',
                'numero_control' => 'DTE-03-M001P002-000000000000015', 'fecha_emision' => $fecha, 'monto' => '1.00']);
        }

        try {
            $this->migracion()->down();
            $this->fail('Revertir fundiría dos documentos distintos en una sola identidad.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('repetidos', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('cobro_documentos', 'ejercicio_control'));
        $this->assertTrue(Schema::hasColumn('ppq_items', 'ejercicio_control'));
        $this->assertSame(2, CobroDocumento::count());
    }

    public function test_la_reversa_sin_repetidos_devuelve_el_unico_por_numero(): void
    {
        $cliente = Cliente::factory()->create();
        $this->migracion()->down();

        $this->assertFalse(Schema::hasColumn('cobro_documentos', 'ejercicio_control'));
        $this->assertFalse(Schema::hasColumn('ppq_items', 'ejercicio_control'));

        $this->documentoViejo($cliente->id, 15, '2026-12-20');
        try {
            $this->documentoViejo($cliente->id, 15, '2027-01-15');
            $this->fail('Revertido, el número vuelve a ser único por sí solo.');
        } catch (QueryException) {
            // esperado
        }

        $this->migracion()->up();
        $this->assertTrue(Schema::hasColumn('cobro_documentos', 'ejercicio_control'));
    }
}
