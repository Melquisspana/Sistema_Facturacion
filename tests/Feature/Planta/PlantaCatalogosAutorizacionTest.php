<?php

namespace Tests\Feature\Planta;

use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaProveedor;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso del CRUD de catálogos de Planta.
 *
 * El orden importa: primero el feature flag (404 para todos), luego el permiso
 * de área (403), luego el de catálogos. Ningún botón oculto sustituye a esto:
 * todas las pruebas fuerzan la URL directamente.
 */
class PlantaCatalogosAutorizacionTest extends TestCase
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

    /** Todas las rutas de lectura del paso 3. @return array<string, array{0: string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'insumos.index' => ['planta.insumos.index'],
            'proveedores.index' => ['planta.proveedores.index'],
            'ubicaciones.index' => ['planta.ubicaciones.index'],
        ];
    }

    /** Rutas GET de escritura. @return array<string, array{0: string}> */
    public static function rutasDeCreacion(): array
    {
        return [
            'insumos.create' => ['planta.insumos.create'],
            'proveedores.create' => ['planta.proveedores.create'],
            'ubicaciones.create' => ['planta.ubicaciones.create'],
        ];
    }

    // --- Feature flag ---

    #[DataProvider('rutasDeLectura')]
    public function test_con_el_modulo_apagado_el_administrador_recibe_404_en_los_listados(string $ruta): void
    {
        // phpunit.xml fija PLANTA_ENABLED=false: no hay que apagar nada.
        $this->actingAs($this->usuario('administrador'))
            ->get(route($ruta))
            ->assertNotFound();
    }

    #[DataProvider('rutasDeCreacion')]
    public function test_con_el_modulo_apagado_el_administrador_recibe_404_en_los_formularios(string $ruta): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route($ruta))
            ->assertNotFound();
    }

    public function test_con_el_modulo_apagado_las_escrituras_tambien_devuelven_404(): void
    {
        $admin = $this->usuario('administrador');

        $this->actingAs($admin)->post(route('planta.insumos.store'), [])->assertNotFound();
        $this->actingAs($admin)->post(route('planta.proveedores.store'), [])->assertNotFound();
        $this->actingAs($admin)->post(route('planta.ubicaciones.store'), [])->assertNotFound();
    }

    // --- Invitado ---

    #[DataProvider('rutasDeLectura')]
    public function test_el_invitado_es_redirigido_al_login(string $ruta): void
    {
        $this->encenderModulo();

        $this->get(route($ruta))->assertRedirect(route('login'));
    }

    // --- Rol produccion: lee pero no escribe ---

    #[DataProvider('rutasDeLectura')]
    public function test_produccion_entra_a_los_listados(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route($ruta))
            ->assertOk();
    }

    #[DataProvider('rutasDeCreacion')]
    public function test_produccion_no_entra_a_los_formularios_de_creacion(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route($ruta))
            ->assertForbidden();
    }

    public function test_produccion_no_puede_crear_editar_ni_alternar_insumos(): void
    {
        $this->encenderModulo();
        $prod = $this->usuario('produccion');
        $insumo = PlantaInsumo::factory()->create();

        $this->actingAs($prod)->post(route('planta.insumos.store'), [])->assertForbidden();
        $this->actingAs($prod)->get(route('planta.insumos.edit', $insumo))->assertForbidden();
        $this->actingAs($prod)->put(route('planta.insumos.update', $insumo), [])->assertForbidden();
        $this->actingAs($prod)->patch(route('planta.insumos.toggle-activo', $insumo))->assertForbidden();

        // Y el dato no cambió por el intento.
        $this->assertTrue($insumo->fresh()->activo);
    }

    public function test_produccion_no_puede_crear_editar_ni_alternar_proveedores(): void
    {
        $this->encenderModulo();
        $prod = $this->usuario('produccion');
        $proveedor = PlantaProveedor::factory()->create();

        $this->actingAs($prod)->post(route('planta.proveedores.store'), [])->assertForbidden();
        $this->actingAs($prod)->get(route('planta.proveedores.edit', $proveedor))->assertForbidden();
        $this->actingAs($prod)->put(route('planta.proveedores.update', $proveedor), [])->assertForbidden();
        $this->actingAs($prod)->patch(route('planta.proveedores.toggle-activo', $proveedor))->assertForbidden();

        $this->assertTrue($proveedor->fresh()->activo);
    }

    public function test_produccion_no_puede_crear_editar_ni_alternar_ubicaciones(): void
    {
        $this->encenderModulo();
        $prod = $this->usuario('produccion');
        $ubicacion = PlantaUbicacion::factory()->create();

        $this->actingAs($prod)->post(route('planta.ubicaciones.store'), [])->assertForbidden();
        $this->actingAs($prod)->get(route('planta.ubicaciones.edit', $ubicacion))->assertForbidden();
        $this->actingAs($prod)->put(route('planta.ubicaciones.update', $ubicacion), [])->assertForbidden();
        $this->actingAs($prod)->patch(route('planta.ubicaciones.toggle-activo', $ubicacion))->assertForbidden();

        $this->assertTrue($ubicacion->fresh()->activo);
    }

    public function test_produccion_no_ve_botones_de_escritura_en_los_listados(): void
    {
        $this->encenderModulo();
        PlantaInsumo::factory()->create();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.insumos.index'))
            ->assertOk()
            ->assertDontSee(route('planta.insumos.create'));
    }

    // --- Administrador ---

    #[DataProvider('rutasDeLectura')]
    public function test_el_administrador_entra_a_los_listados(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('administrador'))
            ->get(route($ruta))
            ->assertOk();
    }

    #[DataProvider('rutasDeCreacion')]
    public function test_el_administrador_entra_a_los_formularios(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('administrador'))
            ->get(route($ruta))
            ->assertOk();
    }

    public function test_el_administrador_ve_los_botones_de_escritura(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('planta.insumos.index'))
            ->assertOk()
            ->assertSee(route('planta.insumos.create'));
    }

    // --- Roles ajenos al área ---

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
    public function test_los_roles_ajenos_reciben_403_en_todo_el_area(string $rol): void
    {
        $this->encenderModulo();
        $usuario = $this->usuario($rol);

        // Ni siquiera pasan el primer candado (planta.ver).
        foreach (['planta.insumos.index', 'planta.proveedores.index', 'planta.ubicaciones.index'] as $ruta) {
            $this->actingAs($usuario)->get(route($ruta))->assertForbidden();
        }
    }

    // --- Navegación ---

    public function test_la_sidebar_muestra_los_catalogos_a_quien_puede_verlos(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuario('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.insumos.index'))
            ->assertSee(route('planta.proveedores.index'))
            ->assertSee(route('planta.ubicaciones.index'));
    }

    public function test_la_sidebar_solo_ofrece_lo_que_tiene_ruta_real(): void
    {
        $this->encenderModulo();

        // Productos, presentaciones y empaques salieron de la lista de pendientes
        // al implementarse el paso 4, y Lotes al implementarse su pantalla de
        // consulta. Lo que sigue sin existir —y la sidebar no puede insinuar— es
        // CREAR un lote a mano: los lotes nacen al confirmar una recepción.
        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.lotes.index'), false)
            ->getContent();

        $this->assertStringNotContainsString('planta/lotes/crear', $html);
    }
}
