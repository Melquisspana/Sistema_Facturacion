<?php

namespace Tests\Feature\Configuracion;

use App\Models\Empresa;
use App\Models\User;
use App\Support\NombreEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El nombre de la empresa que se ve en pantallas y correos sale de
 * Configuración › Empresa emisora, nunca escrito a mano (#27).
 */
class NombreEmpresaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Sistema de prueba']);
    }

    public function test_usa_el_nombre_comercial_de_la_empresa_emisora(): void
    {
        Empresa::create(['razon_social' => 'Empresa de prueba, S.A. de C.V.', 'nombre_comercial' => 'Dulces de prueba', 'activo' => true]);

        $this->assertSame('Dulces de prueba', NombreEmpresa::actual());
    }

    public function test_sin_nombre_comercial_usa_la_razon_social(): void
    {
        Empresa::create(['razon_social' => 'Empresa de prueba, S.A. de C.V.', 'activo' => true]);

        $this->assertSame('Empresa de prueba, S.A. de C.V.', NombreEmpresa::actual());
    }

    public function test_sin_empresa_configurada_usa_el_nombre_del_sistema(): void
    {
        $this->assertSame('Sistema de prueba', NombreEmpresa::actual());
    }

    public function test_sin_la_tabla_de_empresas_no_se_cae(): void
    {
        Schema::withoutForeignKeyConstraints(fn () => Schema::drop('empresas'));
        $this->realinearTransaccionTrasDdl();

        $this->assertSame('Sistema de prueba', NombreEmpresa::actual());
    }

    public function test_cambiar_el_nombre_en_configuracion_cambia_el_nombre_que_se_muestra(): void
    {
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create()->assignRole('administrador');

        Empresa::create(['razon_social' => 'Empresa de prueba, S.A. de C.V.', 'nombre_comercial' => 'Nombre viejo', 'activo' => true]);
        $this->assertSame('Nombre viejo', NombreEmpresa::actual());

        $this->actingAs($admin)->put('/configuracion/empresa', [
            'razon_social' => 'Empresa de prueba, S.A. de C.V.',
            'nombre_comercial' => 'Nombre nuevo',
            'nit' => '0614-000000-000-0',
            'nrc' => '123456-7',
            'activo' => '1',
        ])->assertRedirect(route('configuracion.empresa.edit'));

        $this->assertSame('Nombre nuevo', NombreEmpresa::actual());
    }
}
