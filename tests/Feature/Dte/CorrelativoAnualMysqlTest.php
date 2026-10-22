<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\HoraNegocio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Dos documentos generados A LA VEZ el 01/01 a las 00:00 no pueden salir los dos con el
 * número 1 (issue #42, D1). Lo garantiza el lockForUpdate del correlativo, que SQLite
 * ignora: esta prueba solo corre en el job «PHPUnit (MySQL estricto)».
 *
 * Usa dos conexiones reales a la misma base, con datos CONFIRMADOS: no usa RefreshDatabase,
 * porque otra conexión no ve una transacción sin confirmar. Por eso deja la base recién
 * migrada al empezar y al terminar (migrate:fresh): lo que confirma no puede quedar para
 * las pruebas siguientes, que corren dentro de la transacción de RefreshDatabase.
 *  1. la conexión B empieza a generar el documento A y deja su transacción ABIERTA;
 *  2. la conexión principal intenta generar el documento B: debe quedar esperando el
 *     candado del correlativo (se corta con innodb_lock_wait_timeout = 1);
 *  3. B confirma; ahora el documento B se genera y toma el número 2.
 */
class CorrelativoAnualMysqlTest extends TestCase
{
    use PreparaEmisorDte;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('El bloqueo de fila solo se puede probar en MySQL (job «PHPUnit (MySQL estricto)»).');
        }
        Artisan::call('migrate:fresh');
        Storage::fake('local');
        $this->seedCatalogosDte();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->app && DB::connection()->getDriverName() === 'mysql') {
            DB::purge('mysql_concurrente');
            // Deja la base como la encontraría RefreshDatabase: recién migrada, sin datos de prueba.
            Artisan::call('migrate:fresh');
        }
        parent::tearDown();
    }

    private function borradorCcf(array $emisor): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['correo' => 'cliente@ejemplo.sv']),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        return $dte->refresh();
    }

    public function test_dos_documentos_del_01_01_en_paralelo_no_salen_los_dos_con_uno(): void
    {
        $emisor = $this->crearEmisorDte();
        $correlativo = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id, 'ambiente' => '00',
            'ultimo_numero' => 0, 'activo' => true,
        ]);

        // Un documento de 2026 deja la serie en 1.
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:59:00', HoraNegocio::zona()));
        app(DteGeneracionService::class)->generar($this->borradorCcf($emisor));

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:00:00', HoraNegocio::zona()));
        $docA = $this->borradorCcf($emisor);
        $docB = $this->borradorCcf($emisor);

        config(['database.connections.mysql_concurrente' => config('database.connections.mysql')]);
        $principal = DB::getDefaultConnection();

        // 1. Conexión B: genera A y deja la transacción abierta (el candado sigue tomado).
        DB::setDefaultConnection('mysql_concurrente');
        DB::connection('mysql_concurrente')->beginTransaction();
        app(DteGeneracionService::class)->generar(Dte::query()->findOrFail($docA->id));
        DB::setDefaultConnection($principal);

        // 2. Conexión principal: B tiene que esperar el candado del correlativo.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $espero = false;
        try {
            app(DteGeneracionService::class)->generar(Dte::query()->findOrFail($docB->id));
        } catch (QueryException $e) {
            $espero = str_contains($e->getMessage(), 'Lock wait timeout') || (int) ($e->errorInfo[1] ?? 0) === 1205;
        }
        $this->assertTrue($espero, 'La segunda generación no esperó el candado del correlativo.');

        // 3. B confirma; ahora la principal genera y toma el siguiente número.
        DB::connection('mysql_concurrente')->commit();
        app(DteGeneracionService::class)->generar(Dte::query()->findOrFail($docB->id));

        $a = Dte::query()->findOrFail($docA->id);
        $b = Dte::query()->findOrFail($docB->id);
        $this->assertSame('000000000000001', substr((string) $a->numero_control, -15));
        $this->assertSame('000000000000002', substr((string) $b->numero_control, -15));
        $this->assertSame(2, $correlativo->refresh()->ultimo_numero);
    }
}
