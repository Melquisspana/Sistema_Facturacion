<?php

namespace Tests\Feature\Dte;

use App\Models\CatalogoMh;
use App\Services\Importacion\ImportadorCatalogosMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importación de los catálogos oficiales del MH desde el Excel oficial
 * (resources/dte/catalogos/*.xlsx) a la tabla catalogos_mh. Sin dependencias
 * externas, sin generar JSON, sin firmar ni transmitir.
 */
class CatalogosMhImportTest extends TestCase
{
    use RefreshDatabase;

    private function importador(): ImportadorCatalogosMh
    {
        return app(ImportadorCatalogosMh::class);
    }

    public function test_detecta_el_excel_si_existe(): void
    {
        $ruta = $this->importador()->archivoPorDefecto();

        $this->assertNotNull($ruta, 'No se encontró el .xlsx en resources/dte/catalogos.');
        $this->assertFileExists($ruta);
    }

    public function test_reconoce_cat_001_al_033(): void
    {
        $r = $this->importador()->importar();

        $this->assertSame(33, $r['secciones']);
        $this->assertSame(33, CatalogoMh::distinct()->count('cat'));
        $this->assertTrue(CatalogoMh::where('cat', '001')->exists());
        $this->assertTrue(CatalogoMh::where('cat', '033')->exists());
    }

    public function test_importa_cat014_unidades_completo(): void
    {
        $this->importador()->importar();

        $this->assertSame(40, CatalogoMh::where('cat', '014')->count());
        $this->assertSame('Unidad de Medida', CatalogoMh::where('cat', '014')->value('nombre_catalogo'));
    }

    public function test_importa_cat015_tributos(): void
    {
        $this->importador()->importar();

        $this->assertSame(49, CatalogoMh::where('cat', '015')->count());
    }

    public function test_importa_cat017_formas_de_pago(): void
    {
        $this->importador()->importar();

        $this->assertSame(12, CatalogoMh::where('cat', '017')->count());
    }

    public function test_importa_cat031_incoterms(): void
    {
        $this->importador()->importar();

        // CAT-031 = INCOTERMS (no CAT-018, que es Plazo).
        $this->assertSame(11, CatalogoMh::where('cat', '031')->count());
        $this->assertSame('INCOTERMS', CatalogoMh::where('cat', '031')->value('nombre_catalogo'));
        $this->assertSame(3, CatalogoMh::where('cat', '018')->count()); // CAT-018 Plazo
    }

    public function test_no_duplica_al_correr_dos_veces(): void
    {
        $this->importador()->importar();
        $primera = CatalogoMh::count();

        $this->importador()->importar();
        $segunda = CatalogoMh::count();

        $this->assertSame($primera, $segunda);
        $this->assertSame(40, CatalogoMh::where('cat', '014')->count());
        $this->assertSame(45, CatalogoMh::where('cat', '013')->count()); // municipios jerárquicos preservados
    }

    public function test_dte_insumos_muestra_catalogos_presentes(): void
    {
        $this->importador()->importar();

        $this->artisan('dte:insumos')
            ->assertExitCode(0)
            ->expectsOutputToContain('Catálogos clave')
            ->expectsOutputToContain('CAT-031 INCOTERMS')
            ->expectsOutputToContain('CAT-018 Plazo');
    }
}
