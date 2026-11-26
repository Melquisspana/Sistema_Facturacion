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

    /** FEX aceptada, emitida al cliente y todavía sin lista. */
    private function fexAceptada(ExportacionCliente $perfil): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa de prueba', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja de prueba', 'activo' => true]);

        return Dte::create([
            'tipo_dte' => TipoDte::FacturaExportacion->value, 'estado' => 'aceptado', 'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'cliente_id' => $perfil->cliente_id,
            'numero_control' => 'DTE-11-M001P001-000000000000001',
            'fecha_emision' => '2026-10-01', 'hora_emision' => '10:00:00', 'total_pagar' => 1838.00,
        ]);
    }

    /** Lista cerrada de verdad: con su FEX vinculada, número de factura y quién la finalizó. */
    private function listaFinalizadaConFactura(User $quien): Exportacion
    {
        $lista = $this->lista();
        $fex = $this->fexAceptada($lista->cliente);
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
     * Lista ya finalizada, con líneas del catálogo. Sin perfil, una lista sin cliente.
     *
     * @param  list<array{0: ExportacionProducto, 1: float}>  $lineas  presentación y precio por caja
     */
    private function listaDelCatalogo(?ExportacionCliente $perfil, array $lineas, string $fecha = '2026-10-01'): Exportacion
    {
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil?->id, 'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba', 'fecha' => $fecha,
            'estado' => Exportacion::ESTADO_FINALIZADA, 'finalizada_en' => $fecha.' 10:00',
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

    private function precioDelCliente(ExportacionCliente $perfil, ExportacionProducto $producto): float
    {
        return (float) ExportacionClienteProducto::query()
            ->where('exportacion_cliente_id', $perfil->id)
            ->where('exportacion_producto_id', $producto->id)
            ->value('precio_caja');
    }

    /**
     * Decisión de Melqui: la copia toma de una el precio actual del cliente. Al
     * finalizarla, el precio de la lista pasa a ser el del cliente; una copia con el
     * precio viejo se lo bajaba. Acá los pesos no cambiaron en Productos, así que solo
     * cambia el precio: el resto de la línea se copia tal cual.
     */
    public function test_la_copia_toma_el_precio_actual_del_cliente_y_al_finalizarla_no_lo_baja(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $producto = $this->presentacion();
        $otro = $this->presentacion('Otro producto de prueba');
        foreach ([[$producto, 140.00], [$otro, 99.50]] as [$presentacion, $precio]) {
            ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $presentacion->id, 'precio_caja' => $precio, 'precio_fijado_en' => '2026-11-01', 'activo' => true]);
        }
        $original = $this->listaDelCatalogo($perfil, [[$producto, 140.00], [$otro, 99.50]], '2026-11-01');
        $lineasOriginales = $this->lineas($original);

        // El 20/11 la oficina sube el precio del cliente desde Productos.
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00', HoraNegocio::zona()));
        $this->actingAs($usuario)->put(route('productos.exportacion.clientes', $producto), [
            'clientes' => [$perfil->id => ['activo' => '1', 'precio' => '150']],
        ])->assertSessionHasNoErrors();

        // El 25/11 duplica la lista del 01/11.
        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00', HoraNegocio::zona()));
        $respuesta = $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original));

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $respuesta->assertRedirect(route('facturacion.listas.show', $copia))
            ->assertSessionHas('status', "Lista duplicada desde la #{$original->id}. Revisá la fecha y volvé a facturarla.")
            ->assertSessionHas('aviso_precios', 'Se pusieron los precios actuales del cliente: Producto de prueba 144 u $140.00 → $150.00.');
        $this->assertEquals([150.00, 99.50], $this->precios($copia));

        // Lo demás de cada línea (nombres, pesos, unidades, cajas) no cambia, y la original tampoco.
        $sinPrecio = fn (array $lineas) => array_map(fn (array $linea) => collect($linea)->except('precio_caja')->all(), $lineas);
        $this->assertEquals($sinPrecio($lineasOriginales), $sinPrecio($this->lineas($copia)));
        $this->assertEquals($lineasOriginales, $this->lineas($original->fresh()));

        // Se factura y se finaliza la copia: el precio del cliente sigue en $150.
        $fex = $this->fexAceptada($perfil);
        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $copia), ['dte_id' => $fex->id])
            ->assertSessionHas('status');
        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $copia))
            ->assertSessionHas('status');
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, $copia->fresh()->estado);
        $this->assertStringNotContainsString('Precios del cliente actualizados', (string) session('status'));
        $this->assertEquals(150.00, $this->precioDelCliente($perfil, $producto));
        $this->assertEquals(99.50, $this->precioDelCliente($perfil, $otro));
    }

    /**
     * Si la presentación se editó en Productos (otra caja con el mismo producto), el
     * precio del cliente es el de la caja nueva y la línea copiada sigue describiendo
     * la vieja. Mezclarlos cobraría la caja de 120 u por cajas de 144 u: la línea
     * conserva su precio y se avisa que hay que quitarla y volver a agregarla.
     */
    public function test_si_la_presentacion_cambio_en_productos_la_copia_conserva_el_precio_y_lo_avisa(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $producto = $this->presentacion();
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id, 'precio_caja' => 140.00, 'precio_fijado_en' => '2026-11-01', 'activo' => true]);
        $original = $this->listaDelCatalogo($perfil, [[$producto, 140.00]], '2026-11-01');
        $lineasOriginales = $this->lineas($original);

        // El 20/11 la caja pasa a 120 u en Productos y el precio del cliente baja a $125.
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00', HoraNegocio::zona()));
        $this->actingAs($usuario)->put(route('productos.exportacion.update', $producto), [
            'unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 120, 'gramos_por_unidad' => 85,
            'peso_neto_caja_kg' => 11, 'precio_caja' => 114.00, 'activo' => '1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($usuario)->put(route('productos.exportacion.clientes', $producto), [
            'clientes' => [$perfil->id => ['activo' => '1', 'precio' => '125']],
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00', HoraNegocio::zona()));
        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('status')
            ->assertSessionHas('aviso_precios', "Ojo: estas líneas cambiaron de presentación en Productos y quedaron con el precio y los pesos de la lista #{$original->id}: "
                .'Producto de prueba 144 u $140.00 (ahora 120 u). Quitalas en Editar y volvé a agregarlas antes de facturar.');

        // La línea queda entera como estaba: 144 u a $140 con sus pesos, no 144 u a $125 ni los kilos de la caja de 120 u.
        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals($lineasOriginales, $this->lineas($copia));
        $this->assertEquals(125.00, $this->precioDelCliente($perfil, $producto));
    }

    /**
     * Un producto apagado para el cliente sí tiene precio guardado, solo que apagado.
     * La copia conserva el de la lista, y el aviso dice el motivo real y lo que pasa
     * al finalizar con esa línea: se le vuelve a habilitar al cliente a ese precio.
     */
    public function test_si_el_cliente_tiene_el_producto_apagado_la_copia_avisa_que_al_finalizar_se_vuelve_a_habilitar(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $producto = $this->presentacion();
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id, 'precio_caja' => 175.00, 'precio_fijado_en' => '2026-11-01', 'activo' => true]);
        $original = $this->listaDelCatalogo($perfil, [[$producto, 160.00]], '2026-11-01');

        // El 20/11 la oficina se lo apaga desde Productos: ya no se le vende.
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00', HoraNegocio::zona()));
        $this->actingAs($usuario)->put(route('productos.exportacion.clientes', $producto), [
            'clientes' => [$perfil->id => ['precio' => '175']],
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00', HoraNegocio::zona()));
        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('status')
            ->assertSessionHas('aviso_precios', "Ojo: estas líneas están apagadas para el cliente en Productos y quedaron con el precio de la lista #{$original->id}: "
                .'Producto de prueba 144 u $160.00. Si finalizás con ellas, se le vuelven a habilitar a ese precio. Si ya no se le venden, quitalas en Editar.');
        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals([160.00], $this->precios($copia));

        // Lo que dice el aviso es lo que pasa: al finalizar, vuelve a estar habilitado a $160.
        $fex = $this->fexAceptada($perfil);
        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $copia), ['dte_id' => $fex->id])
            ->assertSessionHas('status');
        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $copia))
            ->assertSessionHas('status');
        $asignacion = ExportacionClienteProducto::query()->where('exportacion_cliente_id', $perfil->id)->where('exportacion_producto_id', $producto->id)->sole();
        $this->assertTrue($asignacion->activo);
        $this->assertEquals(160.00, (float) $asignacion->precio_caja);
    }

    /**
     * Lado seguro: sin precio del cliente (no lo tiene, está apagado o en $0) la línea
     * conserva el de la lista vieja y se avisa, porque al finalizar ese pasa a ser el
     * del cliente. Nunca toma el precio base ni queda sin precio. Una línea que no
     * viene del catálogo conserva su precio y el aviso solo dice que no está en
     * Productos; una lista sin cliente conserva sus precios sin aviso: al finalizar no
     * le cambia el precio a ningún cliente.
     */
    public function test_sin_precio_del_cliente_la_copia_conserva_el_de_la_lista_y_lo_avisa(): void
    {
        $usuario = $this->usuario();
        $perfil = $this->perfil();
        $igual = $this->presentacion();
        $sinPrecio = $this->presentacion('Producto sin precio de prueba');
        $apagado = $this->presentacion('Producto apagado de prueba');
        $enCero = $this->presentacion('Producto en cero de prueba');
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $igual->id, 'precio_caja' => 140.00, 'activo' => true]);
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $apagado->id, 'precio_caja' => 175.00, 'activo' => false]);
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $enCero->id, 'precio_caja' => 0, 'activo' => true]);
        $original = $this->listaDelCatalogo($perfil, [[$igual, 140.00], [$sinPrecio, 120.00], [$apagado, 160.00], [$enCero, 110.00]]);
        $original->items()->create([
            'nombre_es' => 'Línea anterior de prueba', 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 4, 'precio_caja' => 99.50,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
        ]);

        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('status')
            ->assertSessionHas('aviso_precios');

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals([140.00, 120.00, 160.00, 110.00, 99.50], $this->precios($copia));
        $aviso = (string) session('aviso_precios');
        $this->assertStringContainsString("precio de la lista #{$original->id}", $aviso);
        $this->assertStringContainsString('porque el cliente no tiene precio para ellas: Producto sin precio de prueba 144 u $120.00; Producto en cero de prueba 144 u $110.00.', $aviso);
        $this->assertStringContainsString("están apagadas para el cliente en Productos y quedaron con el precio de la lista #{$original->id}: Producto apagado de prueba 144 u \$160.00.", $aviso);
        $this->assertStringNotContainsString('Producto de prueba 144 u', $aviso);
        // La línea que no viene del catálogo conserva su precio: el aviso solo dice que no está en Productos.
        $this->assertStringContainsString("Ojo: estas líneas no están en Productos y quedaron con el precio y los pesos de la lista #{$original->id}: Línea anterior de prueba 144 u \$99.50.", $aviso);
        $this->assertSame(1, substr_count($aviso, 'Línea anterior de prueba'));
        $this->assertStringNotContainsString('Se pusieron los precios actuales', $aviso);

        // Lista sin cliente: conserva sus precios y no hay aviso.
        $sinCliente = $this->listaDelCatalogo(null, [[$igual, 130.00]]);
        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $sinCliente))
            ->assertSessionHas('status')
            ->assertSessionMissing('aviso_precios');
        $copiaSinCliente = Exportacion::query()->whereKeyNot([$original->id, $copia->id, $sinCliente->id])->sole();
        $this->assertNull($copiaSinCliente->exportacion_cliente_id);
        $this->assertEquals([130.00], $this->precios($copiaSinCliente));
    }

    /** Más de seis líneas con otro precio: se nombran seis y se cuentan las demás. */
    public function test_el_aviso_de_precios_cambiados_nombra_seis_lineas_y_cuenta_las_demas(): void
    {
        $perfil = $this->perfil();
        $lineas = [];
        foreach (range(1, 8) as $n) {
            $presentacion = $this->presentacion("Producto {$n} de prueba");
            ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $presentacion->id, 'precio_caja' => 150.00, 'activo' => true]);
            $lineas[] = [$presentacion, 140.00];
        }
        $original = $this->listaDelCatalogo($perfil, $lineas);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('aviso_precios');

        $aviso = (string) session('aviso_precios');
        $this->assertStringContainsString('Producto 6 de prueba 144 u $140.00 → $150.00 y 2 más.', $aviso);
        $this->assertStringNotContainsString('Producto 7 de prueba', $aviso);
        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals(array_fill(0, 8, 150.00), $this->precios($copia));
    }

    /** Cambia la presentación desde Productos, como lo hace la oficina. */
    private function cambiarEnProductos(ExportacionProducto $producto, array $datos): void
    {
        $this->actingAs($this->usuario())->put(route('productos.exportacion.update', $producto), $datos + [
            'unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85,
            'peso_neto_caja_kg' => 13, 'precio_caja' => 136.80, 'activo' => '1',
        ])->assertSessionHasNoErrors();
    }

    /** @return array<string, float> los pesos de la única línea de la lista, como números */
    private function pesos(Exportacion $lista, string $nombre = 'Producto de prueba'): array
    {
        $linea = $lista->items()->where('nombre_es', $nombre)->sole();

        return collect(['gramos_por_unidad', 'onzas_por_unidad', 'peso_neto_caja_kg', 'peso_bruto_caja_kg', 'peso_neto_caja_lb', 'peso_bruto_caja_lb'])
            ->mapWithKeys(fn (string $campo) => [$campo => (float) $linea->{$campo}])
            ->all();
    }

    /**
     * Decisión de Melqui: la copia toma de hoy los pesos de cada línea, los de la
     * presentación en Productos (neto y bruto por caja, gramos, onzas y libras). Los
     * totales de la lista salen de las líneas, así que cuadran con ellas. La original
     * no cambia y el aviso dice qué líneas cambiaron de peso.
     */
    public function test_la_copia_toma_los_pesos_de_hoy_de_productos_y_los_totales_cuadran(): void
    {
        $producto = $this->presentacion();
        $otro = $this->presentacion('Otro producto de prueba');
        $original = $this->listaDelCatalogo(null, [[$producto, 136.80], [$otro, 136.80]]);
        $pesosOriginales = $this->pesos($original);

        // La caja de «Producto de prueba» pesa ahora 15 kg neto y 16.50 kg bruto.
        $this->cambiarEnProductos($producto, ['peso_neto_caja_kg' => 15, 'peso_bruto_caja_kg' => 16.5]);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('status')
            ->assertSessionHas('aviso_precios', 'Se pusieron el empaque y los pesos actuales de Productos: Producto de prueba 144 u.');

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals([
            'gramos_por_unidad' => 85.00, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 15.00, 'peso_bruto_caja_kg' => 16.50,
            'peso_neto_caja_lb' => 33.07, 'peso_bruto_caja_lb' => 36.38,
        ], $this->pesos($copia));
        // La otra línea no cambió en Productos: sigue igual. Precios y cajas tampoco cambian.
        $this->assertEquals($this->pesos($original, 'Otro producto de prueba'), $this->pesos($copia, 'Otro producto de prueba'));
        $this->assertEquals([136.80, 136.80], $this->precios($copia));

        // Totales: 10 cajas de 15 kg + 10 de 13 kg neto; 10 de 16.50 kg + 10 de 14 kg bruto.
        $copia->load('items');
        $this->assertEquals(280.00, $copia->pesoNetoTotalKg());
        $this->assertEquals(305.00, $copia->pesoBrutoTotalKg());
        $this->assertEquals(617.30, $copia->pesoNetoTotalLb());
        $this->assertEquals(672.40, $copia->pesoBrutoTotalLb());

        // La original conserva sus pesos.
        $this->assertEquals($pesosOriginales, $this->pesos($original->fresh()));
    }

    /**
     * El empaque también es de la presentación y sale impreso junto a los pesos: si en
     * Productos se cambió la bolsa por una caja master (mismas unidades y gramos), la
     * copia lleva el empaque de hoy con el peso de hoy, no el empaque viejo con el peso
     * de la caja nueva.
     */
    public function test_la_copia_toma_el_empaque_de_hoy_junto_con_los_pesos(): void
    {
        $producto = $this->presentacion();
        $original = $this->listaDelCatalogo(null, [[$producto, 136.80]]);

        $this->cambiarEnProductos($producto, ['unidad' => 'Caja master de prueba', 'peso_bruto_caja_kg' => 15.5]);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('aviso_precios', 'Se pusieron el empaque y los pesos actuales de Productos: Producto de prueba 144 u.');

        $linea = Exportacion::query()->whereKeyNot($original->id)->sole()->items()->sole();
        $this->assertSame('Caja master de prueba', $linea->unidad);
        $this->assertEquals(15.50, (float) $linea->peso_bruto_caja_kg);
        $this->assertSame('Bolsa de prueba', $original->items()->sole()->unidad);
    }

    /**
     * Solo cambió el empaque en Productos, no los pesos: igual se pone al día y se avisa.
     */
    public function test_si_solo_cambio_el_empaque_la_copia_lo_pone_al_dia_y_lo_avisa(): void
    {
        $producto = $this->presentacion();
        $original = $this->listaDelCatalogo(null, [[$producto, 136.80]]);

        $this->cambiarEnProductos($producto, ['unidad' => 'Caja master de prueba']);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('aviso_precios', 'Se pusieron el empaque y los pesos actuales de Productos: Producto de prueba 144 u.');

        $this->assertSame('Caja master de prueba', Exportacion::query()->whereKeyNot($original->id)->sole()->items()->sole()->unidad);
    }

    /**
     * Si los gramos cambiaron en Productos, es otra presentación: los pesos de hoy son
     * de otra caja. La línea conserva todo lo de la lista vieja y el aviso lo dice,
     * también en una lista sin cliente.
     */
    public function test_si_cambiaron_los_gramos_la_copia_conserva_los_pesos_y_lo_avisa(): void
    {
        $producto = $this->presentacion();
        $original = $this->listaDelCatalogo(null, [[$producto, 136.80]]);
        $lineasOriginales = $this->lineas($original);

        $this->cambiarEnProductos($producto, ['gramos_por_unidad' => 90, 'peso_neto_caja_kg' => 14]);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('status')
            ->assertSessionHas('aviso_precios', "Ojo: estas líneas cambiaron de presentación en Productos y quedaron con el precio y los pesos de la lista #{$original->id}: "
                .'Producto de prueba 144 u $136.80 (ahora 144 u de 90 g). Quitalas en Editar y volvé a agregarlas antes de facturar.');

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals($lineasOriginales, $this->lineas($copia));
    }

    /**
     * Si el producto ya no está en Productos (se borró, o la línea nunca vino del
     * catálogo), no hay de dónde tomar pesos ni precio: la línea conserva todo lo de la
     * lista vieja y el aviso lo dice.
     */
    public function test_si_el_producto_ya_no_esta_la_copia_conserva_la_linea_y_lo_avisa(): void
    {
        $perfil = $this->perfil();
        $borrado = $this->presentacion('Producto borrado de prueba');
        $original = $this->listaDelCatalogo($perfil, [[$borrado, 120.00]]);
        // La aplicación no deja borrar un producto que está en una lista; solo por fuera.
        DB::table('exportacion_productos')->where('id', $borrado->id)->delete();
        $lineasOriginales = $this->lineas($original->fresh());

        $this->actingAs($this->usuario())->post(route('facturacion.listas.duplicar', $original))
            ->assertSessionHas('status')
            ->assertSessionHas('aviso_precios', "Ojo: estas líneas no están en Productos y quedaron con el precio y los pesos de la lista #{$original->id}: "
                .'Producto borrado de prueba 144 u $120.00. Revisalas en Editar antes de facturar.');

        $copia = Exportacion::query()->whereKeyNot($original->id)->sole();
        $this->assertEquals($lineasOriginales, $this->lineas($copia));
    }

    /**
     * Los datos del exportador salen de Configuración, como el FDA y como en una lista
     * nueva. El que Configuración no tenga se conserva de la original, no el de la
     * Empresa emisora: en producción siempre hay una, y la copia salía con su razón
     * social o su dirección fiscal en vez de la bodega que se escribió en la lista.
     */
    public function test_la_copia_toma_el_exportador_de_configuracion_y_conserva_lo_que_falte(): void
    {
        config(['exportaciones.exportador_nombre' => '', 'exportaciones.exportador_direccion' => '', 'exportaciones.fda_reg_number' => '']);
        Empresa::create(['razon_social' => 'Empresa de prueba', 'direccion' => 'Dirección fiscal de prueba', 'ambiente' => '00', 'activo' => true]);
        $usuario = $this->usuario();
        $original = $this->lista(['exportador_direccion' => 'Dirección del exportador de prueba']);

        // Nada configurado: conserva los de la original.
        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))->assertRedirect();
        $sinConfigurar = Exportacion::query()->whereKeyNot($original->id)->latest('id')->first();
        $this->assertSame('Exportador de prueba', $sinConfigurar->exportador_nombre);
        $this->assertSame('Dirección del exportador de prueba', $sinConfigurar->exportador_direccion);

        // Solo el nombre configurado: el nombre es el de hoy y la dirección, la de la original.
        Ajustes::guardarComoSistema('exportaciones.exportador_nombre', 'Exportador configurado de prueba');
        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))->assertRedirect();
        $conNombre = Exportacion::query()->latest('id')->first();
        $this->assertSame('Exportador configurado de prueba', $conNombre->exportador_nombre);
        $this->assertSame('Dirección del exportador de prueba', $conNombre->exportador_direccion);

        // Con los dos configurados, la copia lleva los dos.
        Ajustes::guardarComoSistema('exportaciones.exportador_direccion', 'Dirección configurada de prueba');
        $this->actingAs($usuario)->post(route('facturacion.listas.duplicar', $original))->assertRedirect();
        $conTodo = Exportacion::query()->latest('id')->first();
        $this->assertSame('Exportador configurado de prueba', $conTodo->exportador_nombre);
        $this->assertSame('Dirección configurada de prueba', $conTodo->exportador_direccion);

        // La original no cambia.
        $original->refresh();
        $this->assertSame('Exportador de prueba', $original->exportador_nombre);
        $this->assertSame('Dirección del exportador de prueba', $original->exportador_direccion);
    }

    /**
     * Duplicar crea una lista nueva por cada envío: el botón se apaga al apretarlo, igual
     * que «Generar respaldo ahora», para que un doble clic no deje dos copias.
     */
    public function test_el_boton_duplicar_se_apaga_al_apretarlo(): void
    {
        $original = $this->lista();

        $html = $this->actingAs($this->usuario())->get(route('facturacion.listas.show', $original))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<form method="POST" action="'.preg_quote(route('facturacion.listas.duplicar', $original), '~').'"\s+'
            .'x-data="\{ enviando: false \}" @submit="enviando = true">\s*'
            .'<input type="hidden" name="_token"[^>]*>\s*'
            .'<button type="submit" :disabled="enviando" class="[^"]*\bdisabled:opacity-50\b[^"]*"[^>]*>\s*Duplicar\s*</button>~',
            $html,
        );
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
