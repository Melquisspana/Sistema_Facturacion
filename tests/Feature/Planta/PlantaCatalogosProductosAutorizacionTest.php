<?php

namespace Tests\Feature\Planta;

use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaProductoBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de los tres catálogos del paso 4. Todas las pruebas
 * fuerzan la URL: ningún botón oculto sustituye al middleware.
 */
class PlantaCatalogosProductosAutorizacionTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function encenderModulo(): void
    {
        config()->set('planta.enabled', true);
    }

    /** @return array<string, array{0: string}> */
    public static function listados(): array
    {
        return [
            'productos-base.index' => ['planta.productos-base.index'],
            'presentaciones.index' => ['planta.presentaciones.index'],
            'empaques.index' => ['planta.empaques.index'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function formularios(): array
    {
        return [
            'productos-base.create' => ['planta.productos-base.create'],
            'presentaciones.create' => ['planta.presentaciones.create'],
            'empaques.create' => ['planta.empaques.create'],
        ];
    }

    // --- Feature flag ---

    #[DataProvider('listados')]
    public function test_con_el_modulo_apagado_los_listados_dan_404(string $ruta): void
    {
        // phpunit.xml fija PLANTA_ENABLED=false.
        $this->actingAs($this->usuario('administrador'))->get(route($ruta))->assertNotFound();
    }

    #[DataProvider('formularios')]
    public function test_con_el_modulo_apagado_los_formularios_dan_404(string $ruta): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route($ruta))->assertNotFound();
    }

    public function test_con_el_modulo_apagado_las_escrituras_dan_404(): void
    {
        $admin = $this->usuario('administrador');

        $this->actingAs($admin)->post(route('planta.productos-base.store'), [])->assertNotFound();
        $this->actingAs($admin)->post(route('planta.presentaciones.store'), [])->assertNotFound();
        $this->actingAs($admin)->post(route('planta.empaques.store'), [])->assertNotFound();
    }

    // --- Invitado ---

    #[DataProvider('listados')]
    public function test_el_invitado_va_al_login(string $ruta): void
    {
        $this->encenderModulo();

        $this->get(route($ruta))->assertRedirect(route('login'));
    }

    // --- produccion: lee, no escribe ---

    #[DataProvider('listados')]
    public function test_produccion_entra_a_los_listados(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))->get(route($ruta))->assertOk();
    }

    #[DataProvider('formularios')]
    public function test_produccion_no_entra_a_los_formularios(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))->get(route($ruta))->assertForbidden();
    }

    public function test_produccion_no_escribe_productos_base(): void
    {
        $this->encenderModulo();
        $prod = $this->usuario('produccion');
        $producto = PlantaProductoBase::factory()->create();

        $this->actingAs($prod)->post(route('planta.productos-base.store'), [])->assertForbidden();
        $this->actingAs($prod)->get(route('planta.productos-base.edit', $producto))->assertForbidden();
        $this->actingAs($prod)->put(route('planta.productos-base.update', $producto), [])->assertForbidden();
        $this->actingAs($prod)->patch(route('planta.productos-base.toggle-activo', $producto))->assertForbidden();

        $this->assertTrue($producto->fresh()->activo);
    }

    public function test_produccion_no_escribe_presentaciones(): void
    {
        $this->encenderModulo();
        $prod = $this->usuario('produccion');
        $presentacion = PlantaPresentacion::factory()->create();

        $this->actingAs($prod)->post(route('planta.presentaciones.store'), [])->assertForbidden();
        $this->actingAs($prod)->get(route('planta.presentaciones.edit', $presentacion))->assertForbidden();
        $this->actingAs($prod)->put(route('planta.presentaciones.update', $presentacion), [])->assertForbidden();
        $this->actingAs($prod)->patch(route('planta.presentaciones.toggle-activo', $presentacion))->assertForbidden();

        $this->assertTrue($presentacion->fresh()->activo);
    }

    public function test_produccion_no_escribe_configuraciones_ni_marca_predeterminada(): void
    {
        $this->encenderModulo();
        $prod = $this->usuario('produccion');
        $config = PlantaEmpaqueConfig::factory()->create();

        $this->actingAs($prod)->post(route('planta.empaques.store'), [])->assertForbidden();
        $this->actingAs($prod)->get(route('planta.empaques.edit', $config))->assertForbidden();
        $this->actingAs($prod)->put(route('planta.empaques.update', $config), [])->assertForbidden();
        $this->actingAs($prod)->patch(route('planta.empaques.toggle-activo', $config))->assertForbidden();
        // La acción de predeterminada también está protegida.
        $this->actingAs($prod)->patch(route('planta.empaques.predeterminada', $config))->assertForbidden();

        $this->assertTrue($config->fresh()->activo);
        $this->assertFalse($config->fresh()->es_predeterminada);
    }

    public function test_produccion_no_ve_botones_de_escritura(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.empaques.index'))
            ->assertOk()
            ->assertDontSee(route('planta.empaques.create'));
    }

    // --- Administrador ---

    #[DataProvider('listados')]
    public function test_el_administrador_entra_a_los_listados(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('administrador'))->get(route($ruta))->assertOk();
    }

    #[DataProvider('formularios')]
    public function test_el_administrador_entra_a_los_formularios(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('administrador'))->get(route($ruta))->assertOk();
    }

    // --- Roles ajenos ---

    /** @return array<string, array{0: string}> */
    public static function rolesAjenos(): array
    {
        return [
            'jefatura' => ['jefatura'],
            'facturacion' => ['facturacion'],
            'contabilidad' => ['contabilidad'],
        ];
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_reciben_403(string $rol): void
    {
        $this->encenderModulo();
        $usuario = $this->usuario($rol);

        foreach (['planta.productos-base.index', 'planta.presentaciones.index', 'planta.empaques.index'] as $ruta) {
            $this->actingAs($usuario)->get(route($ruta))->assertForbidden();
        }
    }

    // --- Navegación ---

    public function test_la_sidebar_ofrece_los_tres_catalogos_nuevos(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.productos-base.index'))
            ->assertSee(route('planta.presentaciones.index'))
            ->assertSee(route('planta.empaques.index'));
    }

    /**
     * Lotes entró en la sidebar con su pantalla de consulta. Lo que sigue sin
     * existir es CREARLOS a mano: nacen al confirmar una recepción.
     */
    public function test_la_sidebar_ofrece_lotes_pero_no_su_creacion(): void
    {
        $this->encenderModulo();

        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.lotes.index'), false)
            ->getContent();

        $this->assertStringNotContainsString('planta/lotes/crear', $html);
    }
}
