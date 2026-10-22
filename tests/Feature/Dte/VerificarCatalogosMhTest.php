<?php

namespace Tests\Feature\Dte;

use App\Models\ActividadEconomica;
use App\Models\CatalogoMh;
use App\Models\Departamento;
use App\Models\Pais;
use App\Models\UnidadMedida;
use App\Services\Importacion\ImportadorCatalogosMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan dte:catalogos --verificar` (issue #42, H11): compara, SIN ESCRIBIR, lo que
 * hay en la base contra el Excel oficial activo (verificado por SHA-256):
 *
 * - `catalogos_mh`: filas que faltan, que sobran o cuyo valor cambió;
 * - los catálogos propios que viajan en el JSON (departamentos CAT-012, unidades CAT-014,
 *   actividades CAT-019, países CAT-020): todo código usado debe existir en el oficial.
 *
 * Sale con código 0 si todo coincide y 1 si hay diferencias. Pensado para correrlo en
 * producción antes de desplegar, sin riesgo.
 */
class VerificarCatalogosMhTest extends TestCase
{
    use RefreshDatabase;

    private function importar(): void
    {
        app(ImportadorCatalogosMh::class)->importar();
    }

    public function test_base_recien_importada_coincide(): void
    {
        $this->importar();

        $this->artisan('dte:catalogos', ['--verificar' => true])
            ->expectsOutputToContain('coinciden')
            ->assertExitCode(0);
    }

    public function test_detecta_filas_cambiadas_faltantes_y_sobrantes_sin_escribir(): void
    {
        $this->importar();
        CatalogoMh::where('cat', '014')->where('codigo', '59')->update(['valor' => 'Valor alterado']);
        CatalogoMh::where('cat', '017')->where('codigo', '01')->delete();
        CatalogoMh::insert(['cat' => '031', 'codigo' => 'ZZ', 'valor' => 'Inventado', 'nombre_catalogo' => 'INCOTERMS']);
        $antes = CatalogoMh::orderBy('id')->get(['cat', 'codigo', 'valor'])->toArray();

        $this->artisan('dte:catalogos', ['--verificar' => true])
            ->expectsOutputToContain('CAT-014')
            ->expectsOutputToContain('CAT-017')
            ->expectsOutputToContain('CAT-031')
            ->assertExitCode(1);

        $this->assertSame($antes, CatalogoMh::orderBy('id')->get(['cat', 'codigo', 'valor'])->toArray(), 'La verificación no debe modificar la base.');
    }

    public function test_base_vacia_no_se_importa_al_verificar(): void
    {
        $this->artisan('dte:catalogos', ['--verificar' => true])->assertExitCode(1);

        $this->assertSame(0, CatalogoMh::count());
    }

    public function test_detecta_codigos_propios_que_no_existen_en_el_oficial(): void
    {
        $this->importar();
        Departamento::create(['codigo' => '99', 'nombre' => 'Departamento inexistente']);
        UnidadMedida::create(['codigo' => '777', 'nombre' => 'Unidad inexistente']);
        ActividadEconomica::create(['codigo' => '00000', 'nombre' => 'Actividad inexistente']);
        Pais::create(['codigo' => 'QQ', 'nombre' => 'País inexistente']);

        $this->artisan('dte:catalogos', ['--verificar' => true])
            ->expectsOutputToContain('Departamento inexistente')
            ->expectsOutputToContain('Unidad inexistente')
            ->expectsOutputToContain('Actividad inexistente')
            ->expectsOutputToContain('País inexistente')
            ->assertExitCode(1);
    }
}
