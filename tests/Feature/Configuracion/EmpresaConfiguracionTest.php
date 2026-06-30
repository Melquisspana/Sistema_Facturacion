<?php

namespace Tests\Feature\Configuracion;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmpresaConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles base necesarios para las pruebas de acceso.
        Role::findOrCreate('administrador', 'web');
        Role::findOrCreate('facturacion', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function noAdmin(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->get('/configuracion/empresa')->assertRedirect('/login');
    }

    public function test_usuario_no_admin_recibe_403(): void
    {
        $this->actingAs($this->noAdmin())
            ->get('/configuracion/empresa')
            ->assertForbidden();
    }

    public function test_administrador_puede_ver_la_configuracion(): void
    {
        $this->actingAs($this->admin())
            ->get('/configuracion/empresa')
            ->assertOk()
            ->assertSee('Empresa emisora');
    }

    public function test_administrador_puede_guardar_la_empresa(): void
    {
        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', [
                'razon_social' => 'Dulces La Negrita, S.A. de C.V.',
                'nombre_comercial' => 'Dulces La Negrita',
                'nit' => '0614-000000-000-0',
                'nrc' => '123456-7',
                'ambiente' => '00',
                'activo' => '1',
            ])
            ->assertRedirect(route('configuracion.empresa.edit'));

        $this->assertDatabaseHas('empresas', [
            'razon_social' => 'Dulces La Negrita, S.A. de C.V.',
            'ambiente' => '00',
        ]);

        $this->assertSame(1, Empresa::count());
    }

    public function test_administrador_puede_ver_todas_las_pantallas(): void
    {
        $admin = $this->admin();

        $rutas = [
            'configuracion.empresa.edit',
            'configuracion.establecimientos.index',
            'configuracion.establecimientos.create',
            'configuracion.puntos-venta.index',
            'configuracion.puntos-venta.create',
            'configuracion.correlativos.index',
            'configuracion.correlativos.create',
        ];

        foreach ($rutas as $ruta) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
        }
    }

    public function test_no_admin_no_puede_guardar_la_empresa(): void
    {
        $this->actingAs($this->noAdmin())
            ->put('/configuracion/empresa', [
                'razon_social' => 'Intento no autorizado',
                'ambiente' => '00',
                'activo' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('empresas', 0);
    }
}
