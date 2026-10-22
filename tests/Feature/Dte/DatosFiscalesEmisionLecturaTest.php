<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Municipio;
use App\Support\Dte\DatosFiscalesEmision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatosFiscalesEmisionLecturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ubicacion_conserva_nombres_si_coincide_y_resuelve_codigos_si_cambia(): void
    {
        $departamento = Departamento::create(['codigo' => '06', 'nombre' => 'Departamento emitido']);
        $municipio = Municipio::create(['departamento_id' => $departamento->id, 'codigo' => '22', 'nombre' => 'Municipio emitido']);
        $distrito = Distrito::create(['departamento_id' => $departamento->id, 'codigo' => '01', 'nombre' => 'Distrito emitido', 'municipio' => 'Municipio emitido', 'municipio_codigo' => '22']);
        $cliente = (new Cliente)->setRelation('departamento', $departamento)->setRelation('municipio', $municipio)->setRelation('distrito', $distrito);
        $direccion = ['departamento' => '06', 'municipio' => '22', 'distrito' => '01'];
        $nombres = ['Formato departamento', 'Formato municipio', 'Formato distrito'];
        $this->assertSame($nombres, DatosFiscalesEmision::ubicacion($cliente, $direccion, $nombres));
        $cliente->setRelation('departamento', null)->setRelation('municipio', null)->setRelation('distrito', null);
        $this->assertSame(['Departamento emitido', 'Municipio emitido', 'Distrito emitido'], DatosFiscalesEmision::ubicacion($cliente, $direccion, $nombres));
        $this->assertSame(['99', '98', '97'], DatosFiscalesEmision::ubicacion($cliente, ['departamento' => '99', 'municipio' => '98', 'distrito' => '97'], $nombres));
    }

    public function test_lectura_falla_sin_alterar_el_documento(): void
    {
        Storage::fake('local');
        config()->set('dte.storage.disk', 'local');
        $dte = (new Dte)->forceFill(['estado' => EstadoDte::Generado, 'json_generado_path' => 'emitido.json']);
        $antes = $dte->getAttributes();
        $this->assertNull(DatosFiscalesEmision::desde($dte));
        foreach (['no json', 'null', '[]', '{"emisor":null}'] as $contenido) {
            Storage::disk('local')->put('emitido.json', $contenido);
            $this->assertNull(DatosFiscalesEmision::desde($dte));
        }
        $this->assertSame($antes, $dte->getAttributes());
    }

    public function test_normaliza_nit_y_respeta_receptor_nulo_y_borrador(): void
    {
        Storage::fake('local');
        config()->set('dte.storage.disk', 'local');
        $dte = (new Dte)->forceFill(['estado' => EstadoDte::Generado, 'json_generado_path' => 'emitido.json']);
        Storage::disk('local')->put('emitido.json', json_encode(['emisor' => ['nombre' => 'Emisor'], 'receptor' => ['nit' => '06140101010011']]));
        $receptor = DatosFiscalesEmision::desde($dte)['receptor'];
        $this->assertSame('36', $receptor['tipoDocumento']);
        $this->assertSame('06140101010011', $receptor['numDocumento']);
        Storage::disk('local')->put('emitido.json', '{"emisor":{},"receptor":null}');
        $this->assertNull(DatosFiscalesEmision::desde($dte)['receptor']);
        $dte->estado = EstadoDte::Borrador;
        $this->assertNull(DatosFiscalesEmision::desde($dte));
        $this->assertSame('0614-010101-001-1', DatosFiscalesEmision::valor('0614-010101-001-1', '06140101010011', true));
        $this->assertSame('original', DatosFiscalesEmision::valor('editado', 'original'));
    }
}
