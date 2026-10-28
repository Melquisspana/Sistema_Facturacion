<?php

namespace Tests\Feature\Ubicacion;

use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una agrupación fiscal 2024 sin fila representante en `municipios` no se puede elegir en
 * el formulario y arrastra a todos sus distritos (caso San Salvador Norte / Guazapa).
 * {@see VinculaMunicipioDistrito::asegurarAgrupaciones()} crea la fila que falta, completa
 * el código de una homónima sin código y nunca pisa una homónima con OTRO código.
 *
 * Departamento y nombres ficticios: la regla no depende del catálogo real.
 */
class AsegurarAgrupacionesTest extends TestCase
{
    use RefreshDatabase;

    private int $departamentoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->departamentoId = DB::table('departamentos')->insertGetId([
            'codigo' => '98', 'nombre' => 'Departamento de prueba', 'activo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function distrito(string $agrupacion, string $codigo, string $nombre): void
    {
        DB::table('distritos')->insert([
            'departamento_id' => $this->departamentoId, 'municipio' => $agrupacion,
            'municipio_codigo' => $codigo, 'nombre' => $nombre, 'activo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function municipio(string $nombre, ?string $codigo): int
    {
        return DB::table('municipios')->insertGetId([
            'departamento_id' => $this->departamentoId, 'codigo' => $codigo, 'nombre' => $nombre,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, string|null> nombre => código, solo del departamento de prueba */
    private function municipiosDelDepartamento(): array
    {
        return DB::table('municipios')->where('departamento_id', $this->departamentoId)
            ->orderBy('nombre')->pluck('codigo', 'nombre')->all();
    }

    public function test_crea_la_fila_que_falta_y_la_segunda_corrida_no_escribe_nada(): void
    {
        $this->municipio('Prueba Centro', '01');
        $this->distrito('Prueba Centro', '01', 'Distrito Uno');
        $this->distrito('Prueba Norte', '02', 'Distrito Dos');
        $this->distrito('Prueba Norte', '02', 'Distrito Tres');

        $this->assertContains('Prueba Norte (CAT-013 02)', VinculaMunicipioDistrito::agrupacionesInalcanzables());

        $resultado = VinculaMunicipioDistrito::asegurarAgrupaciones();

        $this->assertContains('Prueba Norte (CAT-013 02)', $resultado['agrupaciones']);
        $this->assertSame(['Prueba Centro' => '01', 'Prueba Norte' => '02'], $this->municipiosDelDepartamento());
        $this->assertNotContains('Prueba Norte (CAT-013 02)', VinculaMunicipioDistrito::agrupacionesInalcanzables());

        $segunda = VinculaMunicipioDistrito::asegurarAgrupaciones();
        $this->assertSame(0, $segunda['creados'] + $segunda['completados']);
        $this->assertCount(2, $this->municipiosDelDepartamento());
    }

    public function test_en_seco_no_escribe(): void
    {
        $this->distrito('Prueba Norte', '02', 'Distrito Dos');

        $resultado = VinculaMunicipioDistrito::asegurarAgrupaciones(dryRun: true);

        $this->assertContains('Prueba Norte (CAT-013 02)', $resultado['agrupaciones']);
        $this->assertSame([], $this->municipiosDelDepartamento());
    }

    public function test_completa_el_codigo_de_una_homonima_sin_codigo_sin_cambiar_su_id(): void
    {
        $id = $this->municipio('Prueba Norte', null);
        $this->distrito('Prueba Norte', '02', 'Distrito Dos');

        VinculaMunicipioDistrito::asegurarAgrupaciones();

        $this->assertSame('02', DB::table('municipios')->where('id', $id)->value('codigo'));
        $this->assertCount(1, $this->municipiosDelDepartamento());
    }

    public function test_no_pisa_una_homonima_con_otro_codigo_y_lo_informa(): void
    {
        $id = $this->municipio('Prueba Norte', '07');
        $this->distrito('Prueba Norte', '02', 'Distrito Dos');

        $resultado = VinculaMunicipioDistrito::asegurarAgrupaciones();

        $this->assertSame('07', DB::table('municipios')->where('id', $id)->value('codigo'));
        $this->assertCount(1, $this->municipiosDelDepartamento());
        $this->assertNotEmpty(array_filter($resultado['conflictos'], fn ($c) => str_contains($c, 'Prueba Norte')));
    }

    public function test_la_migracion_repara_y_se_puede_volver_a_correr(): void
    {
        $this->distrito('Prueba Norte', '02', 'Distrito Dos');

        /** @var Migration $migracion */
        $migracion = require database_path('migrations/2026_09_09_090000_asegurar_agrupaciones_municipales_2024.php');
        $migracion->up();
        $migracion->up();

        $this->assertSame(['Prueba Norte' => '02'], $this->municipiosDelDepartamento());
    }
}
