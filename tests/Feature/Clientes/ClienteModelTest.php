<?php

namespace Tests\Feature\Clientes;

use App\Enums\TipoCliente;
use App\Enums\TipoDocumentoCliente;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Pais;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
    }

    public function test_relaciones_y_casts(): void
    {
        $laPaz = Departamento::where('codigo', '08')->firstOrFail();
        $olocuilta = Municipio::where('nombre', 'Olocuilta')->firstOrFail();
        $elSalvador = Pais::where('nombre', 'El Salvador')->firstOrFail();
        $actividad = ActividadEconomica::firstOrFail();

        $cliente = Cliente::factory()->contribuyente()->create([
            'actividad_economica_id' => $actividad->id,
            'pais_id' => $elSalvador->id,
            'departamento_id' => $laPaz->id,
            'municipio_id' => $olocuilta->id,
        ]);

        // Relaciones.
        $this->assertSame($actividad->id, $cliente->actividadEconomica->id);
        $this->assertSame($elSalvador->id, $cliente->pais->id);
        $this->assertSame($laPaz->id, $cliente->departamento->id);
        $this->assertSame('Olocuilta', $cliente->municipio->nombre);

        // Casts a enum.
        $this->assertInstanceOf(TipoCliente::class, $cliente->tipo_cliente);
        $this->assertSame(TipoCliente::Contribuyente, $cliente->tipo_cliente);
        $this->assertSame(TipoDocumentoCliente::Nit, $cliente->tipo_documento);
        $this->assertTrue($cliente->tipo_cliente->requiereNrc());
        $this->assertTrue($cliente->activo);
    }

    public function test_soft_delete(): void
    {
        $cliente = Cliente::factory()->create();

        $cliente->delete();

        $this->assertSoftDeleted('clientes', ['id' => $cliente->id]);
        $this->assertSame(0, Cliente::count());
        $this->assertSame(1, Cliente::withTrashed()->count());
    }
}
