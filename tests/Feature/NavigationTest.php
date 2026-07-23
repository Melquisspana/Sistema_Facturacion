<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sidebar (layouts/navigation.blade.php): jerarquía de categorías/enlaces,
 * textos nuevos ("Clientes de facturación", "Perfiles y precios de
 * exportación"), estado activo y visibilidad por rol. Mismas rutas y permisos
 * de siempre — solo cambian los textos visibles y el estilo.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    public function test_categorias_y_textos_nuevos_del_menu(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        foreach ([
            'Inicio', 'Comercial', 'Facturación', 'Prontos Pagos', 'Contabilidad', 'Exportaciones', 'Administración',
        ] as $categoria) {
            $resp->assertSee($categoria);
        }

        // Renombres pedidos: solo el texto visible, no las rutas.
        $resp->assertSee('Clientes de facturación');
        $resp->assertDontSee('>Clientes<', false); // ya no aparece el rótulo viejo "Clientes" solo
        $resp->assertSee('Perfiles y precios de exportación');
        $resp->assertDontSee('Clientes y precios');
    }

    public function test_enlaces_del_menu_apuntan_a_las_mismas_rutas_de_siempre(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee(route('clientes.index'), false);
        $resp->assertSee(route('exportaciones.clientes.index'), false);
        $resp->assertSee(route('documentos-recibidos.index'), false);
        $resp->assertSee(route('facturacion.preparar-produccion'), false);
        $resp->assertSee(route('admin.salud-sistema'), false);
    }

    public function test_ruta_activa_se_marca_con_aria_current(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('exportaciones.clientes.index'))
            ->assertOk();

        // El enlace activo lleva aria-current="page"; los demás no.
        $resp->assertSee('aria-current="page"', false);
    }

    public function test_jefatura_ve_secciones_operativas_de_lectura_pero_no_administracion(): void
    {
        // Nueva política: jefatura tiene lectura amplia (ve PPQ, Contabilidad y
        // Exportaciones), pero NO la sección Administración (usuarios/config/salud/
        // auditoría) ni los botones de gestión dentro de esos módulos.
        $resp = $this->actingAs($this->usuario('jefatura'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Prontos Pagos');
        $resp->assertSee('Contabilidad');
        $resp->assertSee('Exportaciones');
        $resp->assertDontSee('Administración');
        // Sin enlaces de administración.
        $resp->assertDontSee(route('usuarios.index'), false);
        $resp->assertDontSee(route('admin.salud-sistema'), false);
    }

    public function test_administrador_ve_administracion_con_badge_de_jobs_fallidos(): void
    {
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'sync', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Salud del sistema');
        $resp->assertSeeInOrder(['Salud del sistema', '1']);
    }

    public function test_sidebar_tiene_boton_de_tema_y_es_desplazable(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Claro / oscuro', false);
        $resp->assertSee('overflow-y-auto', false);
    }
}
