<?php

namespace Tests\Feature\Facturacion;

use App\Enums\TipoDte;
use App\Facades\Ajustes;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Exportaciones\CatalogoExportacion;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Ventas y facturación › Listas de empaque › «Duplicar», por su ruta.
 *
 * La copia es el punto de partida del próximo embarque: tiene que nacer en borrador,
 * editable, con la fecha de hoy, sin ninguna factura ni número de factura y visible en
 * «Abiertas», sin tocar la lista de la que sale. Datos ficticios.
 */
class ListaEmpaqueDuplicarRutasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00', HoraNegocio::zona()));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function perfil(): ExportacionCliente
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);

        return ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);
    }

    private function lista(array $extra = []): Exportacion
    {
        $lista = Exportacion::create($extra + [
            'exportacion_cliente_id' => $this->perfil()->id,
            'cliente_nombre' => 'Cliente de prueba',
            'cliente_direccion' => 'Dirección de prueba',
            'exportador_nombre' => 'Exportador de prueba',
            'fda_reg_number' => '00000000001',
            'fecha' => '2026-10-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        foreach ([['Producto de prueba', 10, 144.00], ['Otro producto de prueba', 4, 99.50]] as [$nombre, $cajas, $precio]) {
            $lista->items()->create([
                'nombre_es' => $nombre, 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
                'unidades_por_caja' => 144, 'cantidad_cajas' => $cajas, 'precio_caja' => $precio,
                'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
                'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
                'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
            ]);
        }

        return $lista->fresh();
    }

    /** Lista cerrada de verdad: con su FEX vinculada, número de factura y quién la finalizó. */
    private function listaFinalizadaConFactura(User $quien): Exportacion
    {
        $lista = $this->lista();
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa de prueba', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja de prueba', 'activo' => true]);
        $fex = Dte::create([
            'tipo_dte' => TipoDte::FacturaExportacion->value, 'estado' => 'aceptado', 'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'cliente_id' => $lista->cliente->cliente_id,
            'numero_control' => 'DTE-11-M001P001-000000000000001',
            'fecha_emision' => '2026-10-01', 'hora_emision' => '10:00:00', 'total_pagar' => 1838.00,
        ]);
        DB::table('exportacion_dte')->insert([
            'exportacion_id' => $lista->id, 'dte_id' => $fex->id, 'principal' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lista->update([
            'dte_id' => $fex->id, 'factura' => 'DTE-11-M001P001-000000000000001',
            'estado' => Exportacion::ESTADO_FINALIZADA, 'finalizada_en' => now(), 'finalizada_por_user_id' => $quien->id,
        ]);

        return $lista->fresh();
    }

    /** Lo que importa de cada línea: su copia del catálogo, sin ids ni fechas. */
    private function lineas(Exportacion $lista): array
    {
        return $lista->items()->orderBy('nombre_es')->get()
            ->map(fn (ExportacionItem $i) => collect($i->getAttributes())->except(['id', 'exportacion_id', 'created_at', 'updated_at'])->all())
            ->all();
    }

    private function idsEnAbiertas(User $quien): array
    {
        $respuesta = $this->actingAs($quien)->get(route('facturacion.listas.index'))->assertOk();
        /** @var LengthAwarePaginator $listas */
        $listas = $respuesta->viewData('listas');

        return $listas->getCollection()->pluck('id')->all();
    }

    public function test_duplicar_una_lista_finalizada_deja_una_copia_en_borrador_sin_factura_y_no_toca_la_original(): void
    {
        $usuario = $this->usuario();
        $original = $this->listaFinalizadaConFactura($this->usuario('administrador'));
        $lineasOriginales = $this->lineas($original);

        $respuesta = $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original));

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $respuesta->assertRedirect(route('facturacion.listas.show', $copia))
            ->assertSessionHas('status', "Lista duplicada desde la #{$original->id}. Revisá la fecha y volvé a facturarla.");

        // La copia: borrador editable, fecha de hoy, sin factura, mismos productos y precios.
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $copia->estado);
        $this->assertTrue($copia->puedeEditarse());
        $this->assertSame('2026-11-25', $copia->fecha->toDateString());
        $this->assertNull($copia->dte_id);
        $this->assertNull($copia->factura);
        $this->assertNull($copia->finalizada_en);
        $this->assertNull($copia->finalizada_por_user_id);
        $this->assertCount(0, $copia->dtes);
        $this->assertSame($original->exportacion_cliente_id, $copia->exportacion_cliente_id);
        $this->assertSame('Cliente de prueba', $copia->cliente_nombre);
        $this->assertEquals($lineasOriginales, $this->lineas($copia));

        // La original sigue cerrada y con su factura.
        $original->refresh();
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, $original->estado);
        $this->assertSame(1, DB::table('exportacion_dte')->where('exportacion_id', $original->id)->count());
        $this->assertSame(0, DB::table('exportacion_dte')->where('exportacion_id', $copia->id)->count());
        $this->assertEquals($lineasOriginales, $this->lineas($original));

        // Se ve en «Abiertas» y su alta queda en la bitácora con quién la hizo.
        $this->assertContains($copia->id, $this->idsEnAbiertas($usuario));
        $alta = Activity::query()->where('log_name', 'lista_empaque')->where('subject_id', $copia->id)->where('event', 'created')->sole();
        $this->assertSame($usuario->id, (int) $alta->causer_id);
    }

    /**
     * Una lista archivada (marca heredada del flujo anterior) también muestra «Duplicar».
     * La copia es una lista nueva: no hereda la marca de archivada ni la clasificación de
     * la revisión. Si las heredara nacería escondida de «Abiertas» y sin poder editarse.
     */
    public function test_duplicar_una_lista_archivada_deja_la_copia_visible_y_editable_sin_marcas_heredadas(): void
    {
        $usuario = $this->usuario();
        $admin = $this->usuario('administrador');
        $original = $this->lista([
            'estado' => 'aprobada',
            'archivada' => true,
            'archivada_en' => now()->subMonth(),
            'requiere_revision' => false,
            'revision_estado_original' => 'aprobada',
            'revision_resolucion' => 'archivada',
            'revision_resuelta_en' => now()->subMonth(),
            'revision_resuelta_por_user_id' => $admin->id,
        ]);

        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))->assertRedirect();

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $copia->estado);
        $this->assertFalse($copia->archivada);
        $this->assertNull($copia->archivada_en);
        $this->assertNull($copia->revision_estado_original);
        $this->assertNull($copia->revision_resolucion);
        $this->assertNull($copia->revision_resuelta_en);
        $this->assertNull($copia->revision_resuelta_por_user_id);
        $this->assertTrue($copia->puedeEditarse(), 'La copia tiene que poder editarse.');
        $this->assertContains($copia->id, $this->idsEnAbiertas($usuario), 'La copia tiene que verse en «Abiertas».');

        // La archivada sigue como estaba.
        $original->refresh();
        $this->assertTrue($original->archivada);
        $this->assertSame('archivada', $original->revision_resolucion);
        $this->assertNotContains($original->id, $this->idsEnAbiertas($usuario));
    }

    /**
     * El FDA es de la EMPRESA, se configura en Configuración › Módulos › Exportaciones y
     * en la lista no se puede escribir. Si la copia arrastrara el de la lista vieja, el
     * próximo embarque saldría con un FDA que ya no vale y nadie podría corregirlo. Sin
     * nada configurado, la copia conserva el de la original.
     */
    public function test_la_copia_lleva_el_fda_de_la_empresa_configurado_hoy(): void
    {
        config(['exportaciones.fda_reg_number' => '']);
        $usuario = $this->usuario();
        $original = $this->lista(['fda_reg_number' => '00000000001']);

        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))->assertRedirect();
        $sinConfigurar = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertSame('00000000001', $sinConfigurar->fda_reg_number);

        Ajustes::guardarComoSistema('exportaciones.fda_reg_number', '00000000002');

        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))->assertRedirect();
        $copia = Exportacion::query()->whereKeyNot([$original->id, $sinConfigurar->id])->sole();
        $this->assertSame('00000000002', $copia->fda_reg_number);
        $this->assertSame('00000000001', $original->fresh()->fda_reg_number);
    }

    private function presentacion(string $nombre = 'Producto de prueba'): ExportacionProducto
    {
        return app(CatalogoExportacion::class)->crearProducto(
            ['nombre_es' => $nombre, 'nombre_en' => 'Test product', 'categoria' => null],
            ['unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13, 'precio_caja' => 136.80],
        )->presentaciones()->sole();
    }

    /**
     * Lista del 01/10, ya finalizada, con líneas del catálogo.
     *
     * @param  list<array{0: ExportacionProducto, 1: float}>  $lineas  presentación y precio por caja
     */
    private function listaDelCatalogo(ExportacionCliente $perfil, array $lineas): Exportacion
    {
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-10-01',
            'estado' => Exportacion::ESTADO_FINALIZADA, 'finalizada_en' => '2026-10-01 10:00',
        ]);
        foreach ($lineas as [$producto, $precio]) {
            $lista->items()->create(['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 10, 'precio_caja' => $precio] + $producto->datosSnapshot());
        }

        return $lista->fresh();
    }

    /** @return list<float> */
    private function precios(Exportacion $lista): array
    {
        return $lista->items()->orderBy('id')->pluck('precio_caja')->map(fn ($precio) => (float) $precio)->all();
    }

    /**
     * La copia lleva los precios de la lista vieja. Si esta mañana se subió a mano el
     * precio del cliente, la copia lo avisa antes de facturar, porque al finalizarla el
     * precio de la lista pasaría a ser el del cliente. La copia no cambia de precio: si
     * debería tomar el actual lo decide Melqui.
     */
    public function test_la_copia_avisa_las_lineas_que_no_llevan_el_precio_actual_del_cliente(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $producto = $this->presentacion();
        $otro = $this->presentacion('Otro producto de prueba');
        foreach ([[$producto, 136.80], [$otro, 99.50]] as [$presentacion, $precio]) {
            ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $presentacion->id, 'precio_caja' => $precio, 'precio_fijado_en' => '2026-10-01', 'activo' => true]);
        }
        $original = $this->listaDelCatalogo($perfil, [[$producto, 136.80], [$otro, 99.50]]);

        // A la mañana, la oficina sube a mano el precio del cliente desde Productos.
        $this->actingAs($usuario)->put(route('productos.exportacion.clientes', $producto), [
            'clientes' => [$perfil->id => ['activo' => '1', 'precio' => '150']],
        ])->assertSessionHasNoErrors();

        $respuesta = $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original));

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $respuesta->assertRedirect(route('facturacion.listas.show', $copia))
            ->assertSessionHas('status', "Lista duplicada desde la #{$original->id}. Revisá la fecha y volvé a facturarla.")
            ->assertSessionHas('aviso_precios');
        $aviso = (string) session('aviso_precios');
        $this->assertStringContainsString("la lista #{$original->id}", $aviso);
        $this->assertStringContainsString('Producto de prueba 144 u $136.80 (actual $150.00)', $aviso);
        $this->assertStringNotContainsString('Otro producto de prueba', $aviso);
        $this->assertStringContainsString('Corregilo en Editar antes de facturar', $aviso);

        $this->assertEquals([136.80, 99.50], $this->precios($copia));
        $this->assertEquals(150.00, (float) ExportacionClienteProducto::query()->where('exportacion_producto_id', $producto->id)->value('precio_caja'));
    }

    /** Sin diferencias no hay aviso: mismo precio, sin precio del cliente o con su precio apagado. */
    public function test_la_copia_no_avisa_si_las_lineas_llevan_el_precio_actual_o_el_cliente_no_tiene_uno(): void
    {
        $perfil = $this->perfil();
        $igual = $this->presentacion();
        $sinPrecio = $this->presentacion('Producto sin precio de prueba');
        $apagado = $this->presentacion('Producto apagado de prueba');
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $igual->id, 'precio_caja' => 140.00, 'activo' => true]);
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $apagado->id, 'precio_caja' => 175.00, 'activo' => false]);
        $original = $this->listaDelCatalogo($perfil, [[$igual, 140.00], [$sinPrecio, 120.00], [$apagado, 160.00]]);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('status')
            ->assertSessionMissing('aviso_precios');

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals([140.00, 120.00, 160.00], $this->precios($copia));
    }

    /**
     * Una lista heredada que todavía espera que un administrador la clasifique está
     * congelada y la ficha no ofrece «Duplicar». Por la ruta tampoco se copia.
     */
    public function test_una_lista_pendiente_de_revision_no_se_duplica(): void
    {
        $original = $this->lista(['estado' => 'aprobada', 'requiere_revision' => true, 'revision_motivo' => 'Estado del flujo anterior']);

        $this->actingAs($this->usuario())->get(route('facturacion.listas.show', $original))->assertOk()
            ->assertDontSee(route('facturacion.listas.duplicar', $original), false);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertRedirect(route('facturacion.listas.show', $original))
            ->assertSessionHas('error');

        $this->assertSame(1, Exportacion::count());
        $this->assertSame(2, ExportacionItem::count());
    }

    public function test_sin_permiso_de_gestionar_da_403_y_una_lista_que_no_existe_404(): void
    {
        $original = $this->lista();
        $jefatura = $this->usuario('jefatura');

        $this->actingAs($jefatura)->post(route('facturacion.listas.duplicar', $original))->assertForbidden();
        $this->actingAs($this->usuario('produccion'))->post(route('facturacion.listas.duplicar', $original))->assertForbidden();
        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', 999999))->assertNotFound();

        $this->assertSame(1, Exportacion::count());
        $this->assertSame(2, ExportacionItem::count());
    }
}
