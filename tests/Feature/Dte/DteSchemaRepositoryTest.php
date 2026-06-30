<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Services\Dte\DteSchemaRepository;
use Tests\TestCase;

class DteSchemaRepositoryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dte_schemas_'.uniqid();
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->borrar($this->tmp);
        parent::tearDown();
    }

    private function borrar(string $ruta): void
    {
        if (! is_dir($ruta)) {
            return;
        }
        foreach (scandir($ruta) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $ruta.DIRECTORY_SEPARATOR.$item;
            is_dir($p) ? $this->borrar($p) : @unlink($p);
        }
        @rmdir($ruta);
    }

    public function test_detecta_que_faltan_schemas_si_no_existen(): void
    {
        $repo = new DteSchemaRepository($this->tmp); // carpeta vacía

        $this->assertSame([], $repo->disponibles());
        $this->assertCount(4, $repo->faltantes());
        $this->assertTrue($repo->falta(TipoDte::CreditoFiscal));
        $this->assertNull($repo->paraTipo(TipoDte::CreditoFiscal));
        $this->assertNull($repo->leer(TipoDte::CreditoFiscal));
    }

    public function test_no_rompe_si_la_carpeta_esta_vacia(): void
    {
        // Ni siquiera existen las subcarpetas de tipo.
        $repo = new DteSchemaRepository($this->tmp.DIRECTORY_SEPARATOR.'no_existe');

        $this->assertSame([], $repo->disponibles());
        $this->assertCount(4, $repo->faltantes());
        foreach ($repo->tiposSoportados() as $tipo) {
            $this->assertTrue($repo->falta($tipo));
        }
    }

    public function test_lista_schemas_disponibles_con_archivos_fake(): void
    {
        // Coloca un schema fake del CCF (no es oficial; solo para probar el lector).
        // La versión esperada del CCF viene de config dte.json.versiones['03'] = 4.
        $dir = $this->tmp.DIRECTORY_SEPARATOR.'03_ccf';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'fe-ccf-v4.json', '{"fake":true}');

        $repo = new DteSchemaRepository($this->tmp);

        $this->assertFalse($repo->falta(TipoDte::CreditoFiscal));
        $info = $repo->paraTipo(TipoDte::CreditoFiscal);
        $this->assertSame('fe-ccf-v4.json', $info['archivo']);
        $this->assertSame(4, $info['version']);
        $this->assertArrayHasKey('03', $repo->disponibles());

        // Los demás siguen faltando.
        $this->assertTrue($repo->falta(TipoDte::Factura));
        $this->assertSame('{"fake":true}', $repo->leer(TipoDte::CreditoFiscal));
    }

    public function test_readme_y_checklist_existen(): void
    {
        $this->assertFileExists(base_path('docs/dte/README.md'));
        $this->assertFileExists(base_path('docs/dte/INSUMOS_PENDIENTES.md'));
    }

    public function test_estructura_de_carpetas_de_schemas_existe(): void
    {
        foreach (['01_factura', '03_ccf', '05_nota_credito', '11_exportacion'] as $carpeta) {
            $this->assertDirectoryExists(resource_path('dte/schemas/'.$carpeta));
        }
        $this->assertDirectoryExists(resource_path('dte/catalogos'));
        $this->assertDirectoryExists(resource_path('dte/examples'));
    }
}
