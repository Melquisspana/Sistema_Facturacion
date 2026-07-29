<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaProductoBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * CRUD, filtros, validaciones y auditoría de productos base, presentaciones y
 * configuraciones de empaque (paso 4).
 */
class PlantaCatalogosProductosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('planta.enabled', true);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    // ------------------------------------------------------------------
    // Productos base
    // ------------------------------------------------------------------

    public function test_el_administrador_crea_edita_y_alterna_un_producto_base(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.productos-base.store'), [
                'codigo' => 'COCO', 'nombre' => 'Dulce de coco', 'descripcion' => null, 'activo' => '1',
            ])
            ->assertRedirect(route('planta.productos-base.index'));

        $producto = PlantaProductoBase::firstWhere('codigo', 'COCO');
        $this->assertNotNull($producto);

        $this->actingAs($admin)
            ->put(route('planta.productos-base.update', $producto), [
                'codigo' => 'COCO', 'nombre' => 'Dulce de coco rallado', 'activo' => '1',
            ])
            ->assertRedirect(route('planta.productos-base.index'));

        $this->assertSame('Dulce de coco rallado', $producto->fresh()->nombre);

        $this->actingAs($admin)->patch(route('planta.productos-base.toggle-activo', $producto));
        $this->assertFalse($producto->fresh()->activo);
    }

    public function test_el_codigo_de_producto_base_es_unico(): void
    {
        PlantaProductoBase::factory()->create(['codigo' => 'COCO']);

        $this->actingAs($this->admin())
            ->post(route('planta.productos-base.store'), ['codigo' => 'COCO', 'nombre' => 'Otro', 'activo' => '1'])
            ->assertSessionHasErrors('codigo');
    }

    public function test_el_producto_base_exige_codigo_y_nombre(): void
    {
        $this->actingAs($this->admin())
            ->post(route('planta.productos-base.store'), ['codigo' => '', 'nombre' => '', 'activo' => '1'])
            ->assertSessionHasErrors(['codigo', 'nombre']);
    }

    public function test_el_producto_base_no_tiene_producto_id(): void
    {
        // Aislamiento: ni columna, ni forma de llegar al catálogo fiscal.
        $this->assertFalse(Schema::hasColumn('planta_productos_base', 'producto_id'));

        $producto = PlantaProductoBase::factory()->create();
        $this->assertArrayNotHasKey('producto_id', $producto->getAttributes());
    }

    public function test_los_filtros_de_productos_base_funcionan(): void
    {
        PlantaProductoBase::factory()->create(['codigo' => 'COCO', 'nombre' => 'Dulce de coco']);
        PlantaProductoBase::factory()->create(['codigo' => 'LECHE', 'nombre' => 'Dulce de leche', 'activo' => false]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.productos-base.index', ['q' => 'coco']))
            ->assertOk()->assertSee('Dulce de coco')->assertDontSee('Dulce de leche');

        $this->actingAs($admin)->get(route('planta.productos-base.index', ['activo' => '0']))
            ->assertOk()->assertSee('Dulce de leche')->assertDontSee('Dulce de coco');
    }

    public function test_el_listado_muestra_el_numero_de_presentaciones(): void
    {
        $base = PlantaProductoBase::factory()->create(['nombre' => 'Dulce de coco']);
        PlantaPresentacion::factory()->count(2)->create(['planta_producto_base_id' => $base->id]);

        $this->actingAs($this->admin())
            ->get(route('planta.productos-base.index'))
            ->assertOk()
            ->assertSee('Dulce de coco');

        $this->assertSame(2, $base->presentaciones()->count());
    }

    // ------------------------------------------------------------------
    // Presentaciones
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function datosPresentacion(PlantaProductoBase $base, array $sobrescribir = []): array
    {
        return array_merge([
            'planta_producto_base_id' => $base->id,
            'codigo' => 'COCO85',
            'nombre' => 'Coco 85 g',
            'contenido' => '85',
            'unidad_contenido' => 'g',
            'unidades_por_bulto' => '24',
            'activo' => '1',
        ], $sobrescribir);
    }

    public function test_el_administrador_crea_edita_y_alterna_una_presentacion(): void
    {
        $admin = $this->admin();
        $base = PlantaProductoBase::factory()->create();

        $this->actingAs($admin)
            ->post(route('planta.presentaciones.store'), $this->datosPresentacion($base))
            ->assertRedirect(route('planta.presentaciones.index'));

        $presentacion = PlantaPresentacion::firstWhere('codigo', 'COCO85');
        $this->assertNotNull($presentacion);
        $this->assertSame('85.0000', $presentacion->contenido);

        $this->actingAs($admin)
            ->put(route('planta.presentaciones.update', $presentacion), $this->datosPresentacion($base, ['nombre' => 'Coco 100 g']))
            ->assertRedirect(route('planta.presentaciones.index'));

        $this->assertSame('Coco 100 g', $presentacion->fresh()->nombre);

        $this->actingAs($admin)->patch(route('planta.presentaciones.toggle-activo', $presentacion));
        $this->assertFalse($presentacion->fresh()->activo);
    }

    public function test_el_nombre_de_presentacion_es_unico_dentro_del_producto_base(): void
    {
        $base = PlantaProductoBase::factory()->create();
        PlantaPresentacion::factory()->create(['planta_producto_base_id' => $base->id, 'nombre' => 'Coco 85 g']);

        $this->actingAs($this->admin())
            ->post(route('planta.presentaciones.store'), $this->datosPresentacion($base, ['nombre' => 'Coco 85 g']))
            ->assertSessionHasErrors('nombre');
    }

    public function test_dos_productos_base_pueden_repetir_el_nombre_de_presentacion(): void
    {
        $admin = $this->admin();
        $uno = PlantaProductoBase::factory()->create();
        $otro = PlantaProductoBase::factory()->create();

        $this->actingAs($admin)->post(route('planta.presentaciones.store'), $this->datosPresentacion($uno, ['codigo' => 'A85', 'nombre' => '85 g']))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('planta.presentaciones.store'), $this->datosPresentacion($otro, ['codigo' => 'B85', 'nombre' => '85 g']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PlantaPresentacion::where('nombre', '85 g')->count());
    }

    public function test_el_codigo_de_presentacion_es_unico_globalmente(): void
    {
        $base = PlantaProductoBase::factory()->create();
        PlantaPresentacion::factory()->create(['codigo' => 'COCO85']);

        $this->actingAs($this->admin())
            ->post(route('planta.presentaciones.store'), $this->datosPresentacion($base))
            ->assertSessionHasErrors('codigo');
    }

    public function test_la_presentacion_valida_contenido_unidad_y_bulto(): void
    {
        $admin = $this->admin();
        $base = PlantaProductoBase::factory()->create();

        $this->actingAs($admin)->post(route('planta.presentaciones.store'), $this->datosPresentacion($base, ['contenido' => '0']))
            ->assertSessionHasErrors('contenido');

        $this->actingAs($admin)->post(route('planta.presentaciones.store'), $this->datosPresentacion($base, ['contenido' => '-5']))
            ->assertSessionHasErrors('contenido');

        $this->actingAs($admin)->post(route('planta.presentaciones.store'), $this->datosPresentacion($base, ['unidad_contenido' => 'toneladas']))
            ->assertSessionHasErrors('unidad_contenido');

        $this->actingAs($admin)->post(route('planta.presentaciones.store'), $this->datosPresentacion($base, ['unidades_por_bulto' => '0']))
            ->assertSessionHasErrors('unidades_por_bulto');

        $this->assertSame(0, PlantaPresentacion::count());
    }

    public function test_la_presentacion_no_puede_colgar_de_un_producto_base_inactivo(): void
    {
        $base = PlantaProductoBase::factory()->create(['activo' => false]);

        $this->actingAs($this->admin())
            ->post(route('planta.presentaciones.store'), $this->datosPresentacion($base))
            ->assertSessionHasErrors('planta_producto_base_id');
    }

    public function test_los_filtros_de_presentaciones_funcionan(): void
    {
        $coco = PlantaProductoBase::factory()->create(['nombre' => 'Coco']);
        $leche = PlantaProductoBase::factory()->create(['nombre' => 'Leche']);

        PlantaPresentacion::factory()->create(['planta_producto_base_id' => $coco->id, 'nombre' => 'Coco 85 g', 'unidad_contenido' => 'g']);
        PlantaPresentacion::factory()->create(['planta_producto_base_id' => $leche->id, 'nombre' => 'Leche 1 lb', 'unidad_contenido' => 'lb']);
        PlantaPresentacion::factory()->create(['planta_producto_base_id' => $coco->id, 'nombre' => 'Coco retirado', 'activo' => false]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.presentaciones.index', ['q' => 'Leche 1']))
            ->assertOk()->assertSee('Leche 1 lb')->assertDontSee('Coco 85 g');

        $this->actingAs($admin)->get(route('planta.presentaciones.index', ['producto_base' => $leche->id]))
            ->assertOk()->assertSee('Leche 1 lb')->assertDontSee('Coco 85 g');

        $this->actingAs($admin)->get(route('planta.presentaciones.index', ['unidad' => 'lb']))
            ->assertOk()->assertSee('Leche 1 lb')->assertDontSee('Coco 85 g');

        $this->actingAs($admin)->get(route('planta.presentaciones.index', ['activo' => '0']))
            ->assertOk()->assertSee('Coco retirado')->assertDontSee('Leche 1 lb');
    }

    // ------------------------------------------------------------------
    // Configuraciones de empaque
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function datosEmpaque(array $sobrescribir = []): array
    {
        // Las factories se resuelven SOLO si no se sobrescriben: si no, cada
        // llamada crearía filas de más y falsearía los conteos de auditoría.
        return array_merge([
            'planta_presentacion_id' => $sobrescribir['planta_presentacion_id']
                ?? PlantaPresentacion::factory()->create()->id,
            'planta_insumo_bolsa_id' => $sobrescribir['planta_insumo_bolsa_id']
                ?? PlantaInsumo::factory()->bolsa()->create()->id,
            'planta_insumo_vinieta_id' => '',
            'marca' => 'La Negrita',
            'mercado' => MercadoPlanta::Nacional->value,
            'referencia_cliente' => null,
            'es_predeterminada' => '0',
            'activo' => '1',
            'vigente_desde' => null,
            'vigente_hasta' => null,
        ], $sobrescribir);
    }

    public function test_el_administrador_crea_edita_y_alterna_una_configuracion(): void
    {
        $admin = $this->admin();
        $datos = $this->datosEmpaque();

        $this->actingAs($admin)
            ->post(route('planta.empaques.store'), $datos)
            ->assertRedirect(route('planta.empaques.index'));

        $config = PlantaEmpaqueConfig::first();
        $this->assertNotNull($config);
        $this->assertSame('LA NEGRITA', $config->marca_norm);

        $this->actingAs($admin)
            ->put(route('planta.empaques.update', $config), $datos + ['referencia_cliente' => 'Cliente X'])
            ->assertRedirect(route('planta.empaques.index'));

        $this->actingAs($admin)->patch(route('planta.empaques.toggle-activo', $config));
        $this->assertFalse($config->fresh()->activo);
    }

    public function test_la_bolsa_debe_ser_de_tipo_bolsa_tambien_por_http(): void
    {
        // El servicio revalida aunque la petición se fuerce sin el formulario.
        $materia = PlantaInsumo::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('planta.empaques.store'), $this->datosEmpaque(['planta_insumo_bolsa_id' => $materia->id]))
            ->assertSessionHasErrors('planta_insumo_bolsa_id');

        $this->assertSame(0, PlantaEmpaqueConfig::count());
    }

    public function test_la_vinieta_debe_ser_de_tipo_vinieta_tambien_por_http(): void
    {
        $bolsa = PlantaInsumo::factory()->bolsa()->create();

        $this->actingAs($this->admin())
            ->post(route('planta.empaques.store'), $this->datosEmpaque(['planta_insumo_vinieta_id' => $bolsa->id]))
            ->assertSessionHasErrors('planta_insumo_vinieta_id');
    }

    public function test_no_se_puede_usar_un_insumo_inactivo_por_http(): void
    {
        $bolsa = PlantaInsumo::factory()->bolsa()->create(['activo' => false]);

        $this->actingAs($this->admin())
            ->post(route('planta.empaques.store'), $this->datosEmpaque(['planta_insumo_bolsa_id' => $bolsa->id]))
            ->assertSessionHasErrors('planta_insumo_bolsa_id');
    }

    public function test_la_vigencia_invertida_se_rechaza_por_http(): void
    {
        $this->actingAs($this->admin())
            ->post(route('planta.empaques.store'), $this->datosEmpaque([
                'vigente_desde' => '2026-08-01', 'vigente_hasta' => '2026-07-01',
            ]))
            ->assertSessionHasErrors('vigente_hasta');
    }

    public function test_marcar_predeterminada_por_http_desmarca_la_anterior(): void
    {
        $admin = $this->admin();
        $presentacion = PlantaPresentacion::factory()->create();
        $bolsa = PlantaInsumo::factory()->bolsa()->create();

        $primera = PlantaEmpaqueConfig::factory()->predeterminada()->create([
            'planta_presentacion_id' => $presentacion->id, 'planta_insumo_bolsa_id' => $bolsa->id, 'marca' => 'A',
        ]);
        $segunda = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $presentacion->id, 'planta_insumo_bolsa_id' => $bolsa->id, 'marca' => 'B',
        ]);

        $this->actingAs($admin)
            ->patch(route('planta.empaques.predeterminada', $segunda))
            ->assertRedirect();

        $this->assertFalse($primera->fresh()->es_predeterminada);
        $this->assertTrue($segunda->fresh()->es_predeterminada);
    }

    public function test_los_filtros_de_configuraciones_funcionan(): void
    {
        $coco = PlantaProductoBase::factory()->create(['nombre' => 'Coco']);
        $presentacion = PlantaPresentacion::factory()->create(['planta_producto_base_id' => $coco->id, 'nombre' => 'Coco 85 g']);
        $otraPres = PlantaPresentacion::factory()->create(['nombre' => 'Leche 1 lb']);
        $bolsa = PlantaInsumo::factory()->bolsa()->create();

        PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $presentacion->id, 'planta_insumo_bolsa_id' => $bolsa->id, 'marca' => 'La Negrita',
        ]);
        PlantaEmpaqueConfig::factory()->exportacion()->create([
            'planta_presentacion_id' => $otraPres->id, 'planta_insumo_bolsa_id' => $bolsa->id, 'marca' => 'Exporta',
        ]);

        $admin = $this->admin();

        // Se comprueba el CONJUNTO devuelto, no el HTML: los desplegables de
        // filtro listan todas las presentaciones y ensuciarían un assertSee.
        $marcasFiltradas = function (array $filtros) use ($admin): array {
            $respuesta = $this->actingAs($admin)->get(route('planta.empaques.index', $filtros))->assertOk();

            return $respuesta->viewData('configs')->pluck('marca')->sort()->values()->all();
        };

        $this->assertSame(['Exporta'], $marcasFiltradas(['mercado' => 'exportacion']));
        $this->assertSame(['La Negrita'], $marcasFiltradas(['producto_base' => $coco->id]));
        $this->assertSame(['La Negrita'], $marcasFiltradas(['presentacion' => $presentacion->id]));
        // La marca filtra por la columna normalizada: la caja no importa.
        $this->assertSame(['La Negrita'], $marcasFiltradas(['marca' => 'la negrita']));
        $this->assertSame(['Exporta', 'La Negrita'], $marcasFiltradas([]));
    }

    public function test_el_selector_de_bolsas_solo_ofrece_bolsas_activas(): void
    {
        $bolsaActiva = PlantaInsumo::factory()->bolsa()->create(['nombre' => 'Bolsa vigente']);
        $bolsaInactiva = PlantaInsumo::factory()->bolsa()->create(['nombre' => 'Bolsa retirada', 'activo' => false]);
        $vinieta = PlantaInsumo::factory()->vinieta()->create(['nombre' => 'Vinieta vigente']);
        $materia = PlantaInsumo::factory()->create(['nombre' => 'Azucar blanca']);

        $this->actingAs($this->admin())
            ->get(route('planta.empaques.create'))
            ->assertOk()
            ->assertSee('Bolsa vigente')
            ->assertSee('Vinieta vigente')
            ->assertDontSee('Bolsa retirada')
            ->assertDontSee('Azucar blanca');
    }

    public function test_al_editar_el_selector_incluye_el_insumo_historico_inactivo(): void
    {
        $bolsa = PlantaInsumo::factory()->bolsa()->create(['nombre' => 'Bolsa historica']);
        $config = PlantaEmpaqueConfig::factory()->create(['planta_insumo_bolsa_id' => $bolsa->id]);

        $bolsa->update(['activo' => false]);

        $this->actingAs($this->admin())
            ->get(route('planta.empaques.edit', $config))
            ->assertOk()
            ->assertSee('Bolsa historica');
    }

    // ------------------------------------------------------------------
    // Auditoría
    // ------------------------------------------------------------------

    public function test_activitylog_registra_los_tres_catalogos_con_su_log_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('planta.productos-base.store'), [
            'codigo' => 'COCO', 'nombre' => 'Dulce de coco', 'activo' => '1',
        ]);
        $base = PlantaProductoBase::firstWhere('codigo', 'COCO');

        $this->actingAs($admin)->post(route('planta.presentaciones.store'), $this->datosPresentacion($base));
        // Se reutiliza la presentación recién creada: si la factory creara otra,
        // habría un segundo registro de `planta_presentacion` y el conteo mentiría.
        $this->actingAs($admin)->post(route('planta.empaques.store'), $this->datosEmpaque([
            'planta_presentacion_id' => PlantaPresentacion::firstWhere('codigo', 'COCO85')->id,
        ]));

        $this->assertSame(1, Activity::where('log_name', 'planta_producto_base')->count());
        $this->assertSame(1, Activity::where('log_name', 'planta_presentacion')->count());
        $this->assertSame(1, Activity::where('log_name', 'planta_empaque')->count());

        $this->assertSame(
            'creó la configuración de empaque',
            Activity::where('log_name', 'planta_empaque')->first()->description
        );
    }

    public function test_activitylog_no_registra_entradas_vacias(): void
    {
        $admin = $this->admin();
        $datos = $this->datosEmpaque();

        $this->actingAs($admin)->post(route('planta.empaques.store'), $datos);
        $config = PlantaEmpaqueConfig::first();

        $antes = Activity::where('log_name', 'planta_empaque')->count();

        // Guardar exactamente lo mismo no debe dejar rastro.
        $this->actingAs($admin)->put(route('planta.empaques.update', $config), $datos);

        $this->assertSame($antes, Activity::where('log_name', 'planta_empaque')->count());
    }

    public function test_activitylog_registra_el_cambio_de_predeterminada(): void
    {
        $admin = $this->admin();
        $config = PlantaEmpaqueConfig::factory()->create();

        $this->actingAs($admin)->patch(route('planta.empaques.predeterminada', $config));

        $registro = Activity::where('log_name', 'planta_empaque')->latest('id')->first();

        $this->assertSame('actualizó la configuración de empaque', $registro->description);
        $this->assertTrue($registro->properties['attributes']['es_predeterminada']);
        $this->assertSame($admin->id, $registro->causer_id);
    }

    public function test_no_existe_ruta_de_eliminacion_en_los_tres_catalogos(): void
    {
        foreach (['planta.productos-base', 'planta.presentaciones', 'planta.empaques'] as $prefijo) {
            $this->assertFalse(
                app('router')->has($prefijo.'.destroy'),
                "No debería existir la ruta {$prefijo}.destroy."
            );
        }
    }
}
