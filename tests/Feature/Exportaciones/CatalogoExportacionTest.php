<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\CategoriaProductoExportacion;
use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\ExportacionProductoBase;
use App\Models\User;
use App\Services\Exportaciones\CatalogoExportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Catálogo de exportación en dos niveles: producto base → presentaciones.
 * Ver docs/DISENO_CATALOGO_EXPORTACION.md.
 */
class CatalogoExportacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function nuevo(array $extra = []): array
    {
        return $extra + [
            'nombre_es' => 'Maní dulce',
            'nombre_en' => 'Sweet baked peanut',
            'categoria' => 'semillas',
            'unidad' => 'Bolsa de polipropileno 12x12',
            'unidades_por_caja' => 144,
            'gramos_por_unidad' => 85,
            'peso_neto_caja_kg' => 13,
            'precio_caja' => 136.80,
        ];
    }

    private function base(string $nombre = 'Maní dulce', ?CategoriaProductoExportacion $categoria = CategoriaProductoExportacion::Semillas): ExportacionProductoBase
    {
        return app(CatalogoExportacion::class)->crearProducto(
            ['nombre_es' => $nombre, 'nombre_en' => 'Sweet baked peanut', 'categoria' => $categoria?->value],
            ['unidad' => 'Bolsa de polipropileno 12x12', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13],
        );
    }

    public function test_un_producto_se_crea_con_su_primera_presentacion_en_un_solo_paso(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('productos.exportacion.store'), $this->nuevo([
                // Llegan valores basura: el servidor los ignora y los calcula.
                'onzas_por_unidad' => 999,
                'peso_neto_caja_lb' => 1,
                'peso_bruto_caja_lb' => 1,
            ]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $base = ExportacionProductoBase::sole();
        $this->assertSame(CategoriaProductoExportacion::Semillas, $base->categoria);

        $p = ExportacionProducto::sole();
        $this->assertSame($base->id, $p->exportacion_producto_base_id);
        $this->assertSame('Maní dulce', $p->nombre_es);
        $this->assertSame('Sweet baked peanut', $p->nombre_en);
        $this->assertSame('3.00', $p->onzas_por_unidad);            // 85 g × 0.035274
        $this->assertSame('14.00', $p->peso_bruto_caja_kg);         // neto + 1 por defecto
        $this->assertSame('28.66', $p->peso_neto_caja_lb);          // 13 × 2.20462
        $this->assertSame('30.86', $p->peso_bruto_caja_lb);         // 14 × 2.20462
        $this->assertSame('Caja 12×12 · 144 u', $p->etiquetaEmpaque());
    }

    public function test_un_bruto_escrito_a_mano_se_respeta(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('productos.exportacion.store'), $this->nuevo(['peso_bruto_caja_kg' => 14.5]))
            ->assertRedirect();

        $this->assertSame('14.50', ExportacionProducto::sole()->peso_bruto_caja_kg);
    }

    public function test_no_se_repite_un_producto_ni_una_presentacion(): void
    {
        $base = $this->base();
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('productos.exportacion.store'), $this->nuevo())
            ->assertSessionHasErrors('nombre_es');

        $this->actingAs($usuario)
            ->post(route('productos.exportacion.presentaciones.store', $base), [
                'unidad' => 'Bolsa de polipropileno 12x12', 'unidades_por_caja' => 144,
                'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13.2,
            ])
            ->assertSessionHasErrors('unidades_por_caja');

        // Otra presentación del mismo producto sí se agrega.
        $this->actingAs($usuario)
            ->post(route('productos.exportacion.presentaciones.store', $base), [
                'unidad' => 'Bolsa de polipropileno 12x18', 'unidades_por_caja' => 216,
                'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 19.4,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ExportacionProductoBase::count());
        $this->assertSame(2, $base->presentaciones()->count());
    }

    public function test_renombrar_el_producto_cambia_sus_presentaciones_pero_no_las_listas_hechas(): void
    {
        $base = $this->base('Mani dulce');
        $presentacion = $base->presentaciones()->sole();

        $lista = Exportacion::create([
            'cliente_nombre' => 'IMPORTADOR NORTE LLC', 'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $item = $lista->items()->create(['exportacion_producto_id' => $presentacion->id, 'cantidad_cajas' => 3, 'precio_caja' => 136.80]
            + $presentacion->datosSnapshot());

        $this->actingAs($this->usuario())
            ->put(route('productos.exportacion.base.update', $base), [
                'nombre_es' => 'Maní dulce', 'nombre_en' => 'Sweet baked peanut', 'categoria' => 'semillas',
            ])
            ->assertRedirect();

        $this->assertSame('Maní dulce', $presentacion->fresh()->nombre_es);
        $this->assertSame('Mani dulce', $item->fresh()->nombre_es);
    }

    public function test_archivar_el_producto_archiva_sus_presentaciones_y_lo_saca_de_las_listas(): void
    {
        $base = $this->base('PRODUCTO A ARCHIVAR');
        $usuario = $this->usuario();

        $this->actingAs($usuario)->patch(route('productos.exportacion.base.toggle-activo', $base))->assertSessionHas('status');

        $this->assertFalse($base->fresh()->activo);
        $this->assertFalse($base->presentaciones()->sole()->activo);
        $this->actingAs($usuario)->get(route('facturacion.listas.create'))->assertOk()
            ->assertDontSee('PRODUCTO A ARCHIVAR');

        $this->actingAs($usuario)->patch(route('productos.exportacion.base.toggle-activo', $base));
        $this->assertTrue($base->presentaciones()->sole()->activo);
    }

    public function test_el_catalogo_se_agrupa_por_categoria_y_muestra_lo_que_falta_agrupar(): void
    {
        $this->base('Maní dulce', CategoriaProductoExportacion::Semillas);
        $this->base('Paleta rosada', CategoriaProductoExportacion::Paletas);
        ExportacionProducto::create([
            'nombre_es' => 'Registro viejo suelto', 'nombre_en' => 'Old', 'unidad' => 'Caja', 'unidades_por_caja' => 12,
            'gramos_por_unidad' => 10, 'onzas_por_unidad' => 0.35, 'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 2,
            'peso_neto_caja_lb' => 2.2, 'peso_bruto_caja_lb' => 4.41, 'activo' => true,
        ]);

        $this->actingAs($this->usuario())->get(route('productos.exportacion.index'))->assertOk()
            ->assertSeeInOrder(['Semillas y maní', 'Maní dulce', 'Paletas y nougat', 'Paleta rosada', 'Sin agrupar', 'Registro viejo suelto'])
            ->assertSee('Caja 12×12 · 144 u');
    }

    private function clienteExportacion(string $nombre): ExportacionCliente
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => $nombre]);

        return ExportacionCliente::create(['cliente_id' => $clienteDte->id, 'nombre' => $nombre, 'activo' => true]);
    }

    public function test_al_crear_el_producto_se_eligen_sus_clientes_y_precios(): void
    {
        $carolinas = $this->clienteExportacion('IMPORTADOR NORTE LLC');
        $diamond = $this->clienteExportacion('IMPORTADOR ESTE INC.');
        $solfi = $this->clienteExportacion('PROVEEDOR ALFA INC');

        $this->actingAs($this->usuario())
            ->post(route('productos.exportacion.store'), $this->nuevo(['clientes' => [
                $carolinas->id => ['activo' => 1, 'precio' => 140],
                $diamond->id => ['activo' => 1, 'precio' => ''],   // vacío = precio base
                $solfi->id => ['precio' => 999],                  // sin marcar: no se asigna
            ]]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $precios = ExportacionClienteProducto::where('exportacion_producto_id', ExportacionProducto::sole()->id)
            ->pluck('precio_caja', 'exportacion_cliente_id');
        $this->assertSame(['140.00', '136.80'], [$precios[$carolinas->id], $precios[$diamond->id]]);
        $this->assertFalse($precios->has($solfi->id));
    }

    public function test_marcar_un_cliente_sin_precio_ni_precio_base_no_crea_nada(): void
    {
        $carolinas = $this->clienteExportacion('IMPORTADOR NORTE LLC');

        $this->actingAs($this->usuario())
            ->post(route('productos.exportacion.store'), $this->nuevo([
                'precio_caja' => '',
                'clientes' => [$carolinas->id => ['activo' => 1, 'precio' => '']],
            ]))
            ->assertSessionHasErrors("clientes.{$carolinas->id}.precio");

        $this->assertSame(0, ExportacionProductoBase::count(), 'producto y clientes van en la misma transacción');
    }

    public function test_desde_el_catalogo_se_asignan_cambian_y_quitan_clientes(): void
    {
        $carolinas = $this->clienteExportacion('IMPORTADOR NORTE LLC');
        $diamond = $this->clienteExportacion('IMPORTADOR ESTE INC.');
        $presentacion = $this->base()->presentaciones()->sole();
        ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $diamond->id, 'exportacion_producto_id' => $presentacion->id,
            'precio_caja' => 120.96, 'precio_fijado_en' => '2026-09-08', 'activo' => true,
        ]);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->get(route('productos.exportacion.index'))->assertOk()
            ->assertSee(route('productos.exportacion.clientes', $presentacion), false);

        $this->actingAs($usuario)
            ->put(route('productos.exportacion.clientes', $presentacion), ['clientes' => [
                $carolinas->id => ['activo' => 1, 'precio' => 136.80],
                $diamond->id => ['precio' => 120.96],            // desmarcado
            ]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $carol = ExportacionClienteProducto::where('exportacion_cliente_id', $carolinas->id)->sole();
        $this->assertSame('136.80', $carol->precio_caja);
        $this->assertSame(now()->toDateString(), $carol->precio_fijado_en->toDateString());

        // Quitar no borra: apaga la asignación y conserva el precio.
        $diam = ExportacionClienteProducto::where('exportacion_cliente_id', $diamond->id)->sole();
        $this->assertFalse($diam->activo);
        $this->assertSame('120.96', $diam->precio_caja);
    }

    public function test_la_ficha_del_cliente_ya_no_asigna_productos(): void
    {
        $perfil = $this->clienteExportacion('IMPORTADOR NORTE LLC');

        $this->actingAs($this->usuario())->get(route('clientes.show', $perfil->cliente_id))->assertOk()
            ->assertSee('Asignar productos desde el catálogo')
            ->assertDontSee('name="exportacion_producto_id"', false)
            ->assertDontSee(route('clientes.exportacion.productos.copiar', $perfil->cliente_id), false);
    }

    public function test_quien_solo_consulta_ve_el_catalogo_pero_no_lo_cambia(): void
    {
        $base = $this->base();
        $jefa = $this->usuario('jefatura');

        $this->actingAs($jefa)->get(route('productos.exportacion.index'))->assertOk()
            ->assertDontSee(route('productos.exportacion.create'), false)
            ->assertDontSee(route('productos.exportacion.presentaciones.create', $base), false);

        $this->actingAs($jefa)->post(route('productos.exportacion.store'), $this->nuevo(['nombre_es' => 'Otro']))->assertForbidden();
        $this->actingAs($jefa)->get(route('productos.exportacion.base.edit', $base))->assertForbidden();
        $this->actingAs($jefa)->patch(route('productos.exportacion.base.toggle-activo', $base))->assertForbidden();
        $this->actingAs($jefa)->put(route('productos.exportacion.clientes', $base->presentaciones()->sole()), ['clientes' => []])->assertForbidden();
    }
}
