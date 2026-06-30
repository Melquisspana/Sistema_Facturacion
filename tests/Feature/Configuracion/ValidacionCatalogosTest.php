<?php

namespace Tests\Feature\Configuracion;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifica que los campos de catálogo de Hacienda rechazan valores inválidos:
 * - ambiente fuera del catálogo CAT-001
 * - municipio que no pertenece al departamento (CAT-012/CAT-013)
 * - tipo de establecimiento fuera de CAT-009
 * - tipo de DTE fuera de CAT-002
 */
class ValidacionCatalogosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function departamento(string $codigo): Departamento
    {
        return Departamento::where('codigo', $codigo)->firstOrFail();
    }

    public function test_ambiente_invalido_es_rechazado(): void
    {
        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', [
                'razon_social' => 'Empresa X',
                'ambiente' => '99', // fuera de CAT-001
                'activo' => '1',
            ])
            ->assertSessionHasErrors('ambiente');

        $this->assertDatabaseCount('empresas', 0);
    }

    public function test_municipio_de_otro_departamento_es_rechazado(): void
    {
        $sanSalvador = $this->departamento('06');           // San Salvador
        $olocuilta = Municipio::where('nombre', 'Olocuilta')->firstOrFail(); // La Paz (08)

        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', [
                'razon_social' => 'Empresa X',
                'ambiente' => '00',
                'departamento_id' => $sanSalvador->id,
                'municipio_id' => $olocuilta->id, // no pertenece a San Salvador
                'activo' => '1',
            ])
            ->assertSessionHasErrors('municipio_id');

        $this->assertDatabaseCount('empresas', 0);
    }

    public function test_municipio_del_departamento_correcto_es_aceptado(): void
    {
        $laPaz = $this->departamento('08');
        $olocuilta = Municipio::where('nombre', 'Olocuilta')->firstOrFail();

        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', [
                'razon_social' => 'Empresa X',
                'ambiente' => '00',
                'departamento_id' => $laPaz->id,
                'municipio_id' => $olocuilta->id,
                'activo' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('empresas', [
            'departamento_id' => $laPaz->id,
            'municipio_id' => $olocuilta->id,
        ]);
    }

    public function test_tipo_establecimiento_invalido_es_rechazado(): void
    {
        $empresa = Empresa::create([
            'razon_social' => 'Empresa X',
            'ambiente' => '00',
            'activo' => true,
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/establecimientos', [
                'empresa_id' => $empresa->id,
                'codigo' => 'M001',
                'nombre' => 'Casa Matriz',
                'tipo_establecimiento' => '99', // fuera de CAT-009
                'activo' => '1',
            ])
            ->assertSessionHasErrors('tipo_establecimiento');

        $this->assertDatabaseCount('establecimientos', 0);
    }

    public function test_tipo_dte_invalido_es_rechazado(): void
    {
        $empresa = Empresa::create([
            'razon_social' => 'Empresa X',
            'ambiente' => '00',
            'activo' => true,
        ]);
        $establecimiento = Establecimiento::create([
            'empresa_id' => $empresa->id,
            'codigo' => 'M001',
            'nombre' => 'Casa Matriz',
            'activo' => true,
        ]);

        $this->actingAs($this->admin())
            ->post('/configuracion/correlativos', [
                'tipo_dte' => '99', // fuera de CAT-002
                'establecimiento_id' => $establecimiento->id,
                'ambiente' => '00',
                'ultimo_numero' => 0,
                'activo' => '1',
            ])
            ->assertSessionHasErrors('tipo_dte');

        $this->assertDatabaseCount('correlativos', 0);
    }
}
