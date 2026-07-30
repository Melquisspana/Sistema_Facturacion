<?php

namespace Tests\Feature\Planta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados del comando `planta:diagnostico-concurrencia`.
 *
 * El comando ESCRIBE en la base configurada y lanza procesos que escriben más.
 * Estas pruebas no miden la concurrencia —eso solo puede hacerse contra MySQL y
 * con procesos reales— sino que verifican que NO PUEDE dispararse donde no debe.
 *
 * La comprobación de entorno es por lista blanca (`local`, `testing`). Se prueba
 * con varios entornos denegados a propósito, no solo `production`: una lista
 * negra dejaría entrar por omisión cualquier entorno nuevo, y ese es justo el
 * fallo que no se descubre hasta que ya ocurrió.
 */
class PlantaDiagnosticoConcurrenciaTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    private function simularEntorno(string $entorno): void
    {
        $this->app->detectEnvironment(fn () => $entorno);
    }

    // --- Bloqueo por entorno ---

    public function test_en_production_se_rechaza_aunque_lleve_confirmar(): void
    {
        $this->simularEntorno('production');

        $this->artisan('planta:diagnostico-concurrencia --confirmar')
            ->assertExitCode(1);
    }

    public function test_en_production_no_escribe_absolutamente_nada(): void
    {
        $this->simularEntorno('production');

        $this->artisan('planta:diagnostico-concurrencia --confirmar --procesos=4')
            ->assertExitCode(1);

        // Ni siquiera llega a crear el escenario: aborta antes de escribir.
        $this->assertSame(0, DB::table('planta_insumos')->count());
        $this->assertSame(0, DB::table('planta_ubicaciones')->count());
        $this->assertSame(0, DB::table('planta_movimientos')->count());
        $this->assertSame(0, DB::table('planta_existencias')->count());
    }

    public static function entornosDenegados(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'preprod' => ['preprod'],
            // Un entorno que nadie previó: con lista blanca queda fuera solo.
            'inventado' => ['qa-cliente'],
        ];
    }

    #[DataProvider('entornosDenegados')]
    public function test_solo_local_y_testing_pueden_ejecutarlo(string $entorno): void
    {
        $this->simularEntorno($entorno);

        $this->artisan('planta:diagnostico-concurrencia --confirmar')
            ->assertExitCode(1);
    }

    public function test_muestra_entorno_conexion_y_base_incluso_al_denegar(): void
    {
        $this->simularEntorno('production');

        // Quien lanza esto contra la base equivocada debe poder leer cuál era.
        $this->artisan('planta:diagnostico-concurrencia --confirmar')
            ->expectsOutputToContain('production')
            ->expectsOutputToContain('sqlite')
            ->assertExitCode(1);
    }

    // --- Confirmación explícita ---

    public function test_sin_confirmar_no_corre_ni_siquiera_en_testing(): void
    {
        $this->assertSame('testing', $this->app->environment());

        $this->artisan('planta:diagnostico-concurrencia')->assertExitCode(1);

        $this->assertSame(0, DB::table('planta_insumos')->count());
    }

    public function test_un_escenario_desconocido_se_rechaza(): void
    {
        $this->artisan('planta:diagnostico-concurrencia --confirmar --escenario=inventado')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('planta_movimientos')->count());
    }

    // --- Aislamiento de los datos ---

    public function test_el_trabajador_rechaza_identificadores_de_datos_reales(): void
    {
        // Escenario REAL, del tipo que tendría un inventario de verdad: sin el
        // prefijo del diagnóstico.
        ['insumo' => $insumo, 'lote' => $lote, 'ubicacion' => $ubicacion, 'bucket' => $bucket]
            = $this->escenarioBasico();

        $this->aplicar($bucket, '100.0000');
        $saldoAntes = $this->saldoProyectado($bucket);
        $huellaAntes = $this->huellaMayor();

        $contexto = base64_encode(json_encode([
            'insumo_id' => $insumo->id,
            'lote_id' => $lote->id,
            'ubicacion_id' => $ubicacion->id,
            'escenario' => 'sumar',
            'barrera' => storage_path('app/diagnostico-concurrencia/inexistente'),
        ]));

        $this->artisan('planta:diagnostico-concurrencia', [
            '--rol' => 'trabajador',
            '--contexto' => $contexto,
        ])->run();

        // El inventario real queda exactamente como estaba.
        $this->assertSame($saldoAntes, $this->saldoProyectado($bucket));
        $this->assertSame($huellaAntes, $this->huellaMayor());
    }

    public function test_el_trabajador_tampoco_corre_en_production(): void
    {
        $this->simularEntorno('production');

        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '50.0000');
        $huellaAntes = $this->huellaMayor();

        $this->artisan('planta:diagnostico-concurrencia', [
            '--rol' => 'trabajador',
            '--contexto' => base64_encode(json_encode([])),
        ])->run();

        $this->assertSame($huellaAntes, $this->huellaMayor());
    }

    // --- Contrato del comando ---

    public function test_el_comando_esta_registrado_con_sus_cuatro_escenarios(): void
    {
        $this->assertArrayHasKey('planta:diagnostico-concurrencia', Artisan::all());

        $descripcion = Artisan::all()['planta:diagnostico-concurrencia']
            ->getDefinition()->getOption('escenario')->getDescription();

        foreach (['crear-bucket', 'sumar', 'ultimo-saldo', 'lote-generico'] as $escenario) {
            $this->assertStringContainsString($escenario, $descripcion);
        }
    }

    public function test_no_existe_ninguna_opcion_para_pasar_identificadores(): void
    {
        $opciones = array_keys(
            Artisan::all()['planta:diagnostico-concurrencia']->getDefinition()->getOptions()
        );

        // `--contexto` es interno y lo valida el trabajador; lo que no debe existir
        // es una vía cómoda de apuntar el diagnóstico a inventario real.
        foreach (['insumo', 'lote', 'ubicacion', 'bucket', 'id'] as $prohibida) {
            $this->assertNotContains($prohibida, $opciones);
        }
    }
}
