<?php

namespace Tests\Feature\Facturacion;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\PpqLote;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\Dte\DteBorradorService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * Ventas y facturación en el celular (pedido del 12/11/2026): debajo de md cada tabla pasa a
 * tarjetas (o a filas apiladas) y los filtros se pliegan detrás de «Filtros»; desde md la
 * tabla queda como siempre. Solo forma: los datos y las acciones son los mismos.
 * Datos inventados.
 */
class VistasCelularTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($this->admin);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        return compact('estab', 'pv');
    }

    private function borradorConLinea(): Dte
    {
        $this->seed(CatalogosMhSeeder::class);
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba Celular']);
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'precio_unitario' => 2]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);

        return $dte->fresh();
    }

    /** La lista de tarjetas (md:hidden) y la tabla de escritorio (hidden md:…) vienen en la misma vista. */
    private function assertTarjetasYTabla(TestResponse $respuesta, string $tabla = 'hidden md:block'): void
    {
        $respuesta->assertOk()
            ->assertSee('<ul class="md:hidden', false)
            ->assertSee($tabla, false);
    }

    public function test_documentos_fiscales_lista_en_tarjetas_con_las_mismas_acciones(): void
    {
        $dte = $this->borradorConLinea();

        $respuesta = $this->get(route('facturacion.index'));

        $this->assertTarjetasYTabla($respuesta);
        // Ver, Editar y Eliminar del borrador: una vez en la tabla y otra en la tarjeta.
        $html = $respuesta->getContent();
        $this->assertSame(2, substr_count($html, 'action="'.route('facturacion.destroy', $dte).'"'));
        $this->assertSame(2, substr_count($html, 'href="'.route('facturacion.edit', $dte).'"'));
        $this->assertStringContainsString('min-h-11', $html);
    }

    public function test_ficha_del_documento_muestra_las_lineas_en_tarjetas(): void
    {
        $dte = $this->borradorConLinea();

        $this->assertTarjetasYTabla($this->get(route('facturacion.show', $dte)));
    }

    public function test_editor_del_ccf_apila_el_catalogo_sin_duplicar_formularios(): void
    {
        $dte = $this->borradorConLinea();
        $producto = Producto::where('nombre', 'Galleta de prueba')->firstOrFail();

        $html = $this->get(route('facturacion.edit', $dte))->assertOk()
            ->assertSee('block md:table min-w-full', false)
            ->assertSee('hidden md:table-header-group', false)
            ->getContent();

        // Un solo formulario de cantidad por producto: ccf-editor.js los recorre todos.
        $this->assertSame(1, substr_count($html, 'data-producto="'.$producto->id.'"'));
    }

    public function test_clientes_en_tarjetas_y_filtros_plegables(): void
    {
        Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba Norte']);

        $respuesta = $this->get(route('clientes.index', ['salas' => 'sin']));

        $respuesta->assertOk()
            ->assertSee('<ul class="md:hidden', false)
            ->assertSee('hidden md:table', false)
            ->assertSee('id="filtros-clientes"', false)
            // Indicador: hay un filtro puesto (Sin salas).
            ->assertSee('filtro puesto');
    }

    public function test_ficha_del_cliente_apila_las_salas(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba Sur']);
        $cliente->sucursales()->create(['nombre' => 'Sala de prueba Sur', 'codigo' => '9100', 'activo' => true]);

        $this->get(route('clientes.show', $cliente))->assertOk()
            ->assertSee('Sala de prueba Sur')
            ->assertSee('grid grid-cols-2 py-2 md:table-row', false)
            ->assertSee('hidden md:table-header-group', false);
    }

    public function test_productos_en_tarjetas_y_filtros_plegables_sin_indicador_si_no_hay_filtros(): void
    {
        $this->seed(CatalogosMhSeeder::class);
        $producto = Producto::factory()->create(['nombre' => 'Canilla de prueba']);

        $respuesta = $this->get(route('productos.index'));

        $this->assertTarjetasYTabla($respuesta);
        $respuesta->assertSee('id="filtros-productos"', false)
            ->assertDontSee('filtro puesto')
            ->assertDontSee('filtros puestos');
        $this->assertSame(2, substr_count($respuesta->getContent(), 'action="'.route('productos.destroy', $producto).'"'));

        $this->get(route('productos.show', $producto))->assertOk()
            ->assertSee('block md:table min-w-full', false);
    }

    public function test_productos_de_exportacion_con_filtros_plegables(): void
    {
        $this->get(route('productos.exportacion.index', ['q' => 'maní']))->assertOk()
            ->assertSee('id="filtros-exportacion"', false)
            ->assertSee('filtro puesto');
    }

    public function test_listas_de_empaque_en_tarjetas_y_ficha_apilada(): void
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'IMPORTADOR DE PRUEBA LLC']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true]);
        $producto = ExportacionProducto::create([
            'nombre_es' => 'Caja de prueba', 'nombre_en' => 'Test box', 'unidad' => 'Bolsa', 'unidades_por_caja' => 12,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'precio_caja' => 10,
            'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 1.1, 'peso_neto_caja_lb' => 2.2, 'peso_bruto_caja_lb' => 2.4, 'activo' => true,
        ]);
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id, 'precio_caja' => 11, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => $cliente->nombre, 'exportador_nombre' => 'Empresa de prueba',
            'fecha' => '2026-11-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $lista->items()->create([
            'nombre_es' => 'Caja de prueba', 'nombre_en' => 'Test box', 'unidad' => 'Bolsa', 'unidades_por_caja' => 12,
            'cantidad_cajas' => 3, 'precio_caja' => 11, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 1.1, 'peso_neto_caja_lb' => 2.2, 'peso_bruto_caja_lb' => 2.4,
        ]);

        $this->assertTarjetasYTabla($this->get(route('facturacion.listas.index')));

        $this->get(route('facturacion.listas.show', $lista))->assertOk()
            ->assertSee('grid grid-cols-2 py-2 md:table-row', false)
            ->assertSee('Valor total');

        $this->get(route('facturacion.listas.edit', $lista))->assertOk()
            ->assertSee('block md:table min-w-full', false);

        $this->get(route('productos.exportacion.show', $producto))->assertOk()
            ->assertSee('block md:table min-w-full', false);
    }

    public function test_historial_ppq_y_compras_apilan_sus_tablas_y_pliegan_filtros(): void
    {
        $lote = PpqLote::create(['referencia' => 'PPQ-PRUEBA', 'fecha' => '2026-11-10', 'estado' => 'listo']);
        DocumentoRecibido::create([
            'gmail_message_id' => 'm1', 'identidad' => 'mid:m1@proveedor.example', 'emisor_nombre' => 'PROVEEDOR DE PRUEBA',
            'tipo_documento' => '03', 'numero_control' => 'DTE-03-PRV-1', 'estado' => 'pendiente', 'total' => 100,
            'tiene_pdf' => true, 'tiene_json' => true, 'clasificacion' => 'dte_valido',
            'fecha_correo' => '2026-11-04', 'fecha_dte' => '2026-11-04',
        ]);

        $this->get(route('ppq.lotes.index', ['q' => 'PPQ']))->assertOk()
            ->assertSee('PPQ-PRUEBA')
            ->assertSee('id="filtros-ppq"', false)
            ->assertSee('filtro puesto')
            ->assertSee('grid grid-cols-2 py-2 md:table-row', false);

        $this->get(route('ppq.lotes.show', $lote))->assertOk()
            ->assertSee('block md:table w-full', false);

        $this->get(route('documentos-recibidos.index', ['rango' => 'todos', 'emisor' => 'PROVEEDOR']))->assertOk()
            ->assertSee('PROVEEDOR DE PRUEBA')
            ->assertSee('id="filtros-compras"', false)
            ->assertSee('filtro puesto')
            ->assertSee('grid grid-cols-2 py-2 md:table-row', false);
    }

    public function test_ficha_del_documento_apila_el_historial_de_envios_sin_duplicar_reenviar(): void
    {
        $dte = $this->borradorConLinea();
        Dte::withoutEvents(fn () => $dte->forceFill([
            'estado' => 'aceptado', 'numero_control' => 'DTE-03-M001P001-000000000000778',
            'sello_recepcion' => 'SELLO-DE-PRUEBA-0002', 'fecha_procesamiento_mh' => now(), 'fecha_emision' => now()->toDateString(),
        ])->saveQuietly());
        $envio = $dte->envios()->create([
            'destinatario' => 'cliente@prueba.test', 'destinatarios' => ['cliente@prueba.test'],
            'estado' => 'enviado', 'adjuntos' => 'PDF', 'canal' => 'cliente',
        ]);

        $html = $this->get(route('facturacion.show', $dte))->assertOk()
            ->assertSee('Historial de envíos (1)')
            ->assertSee('<tr class="grid grid-cols-2 py-2 md:table-row">', false)
            ->assertSee('<span class="md:hidden block text-xs text-gray-500">Destinatarios</span>', false)
            ->assertSee('inline-flex min-h-11 sm:min-h-0 items-center text-indigo-600', false)
            ->getContent();

        // Filas apiladas: la misma tabla, un solo «Reenviar» por envío.
        $this->assertSame(1, substr_count($html, 'action="'.route('facturacion.correo.reenviar', [$dte, $envio]).'"'));
    }

    public function test_paquete_mensual_y_ficha_del_lote_ppq_con_botones_de_44_px_en_el_celular(): void
    {
        $this->get(route('contabilidad.paquete'))->assertOk()
            ->assertSee('class="inline-flex min-h-11 sm:min-h-0 items-center gap-1.5 rounded-md bg-indigo-600', false)
            ->assertSee('class="inline-flex min-h-11 sm:min-h-0 items-center rounded-md border border-gray-300 bg-white px-3 py-2', false);

        $lote = PpqLote::create(['referencia' => 'PPQ-PRUEBA-44', 'fecha' => '2026-11-10', 'estado' => 'borrador']);
        $html = $this->get(route('ppq.lotes.show', $lote))->assertOk()->getContent();

        // Historial, Buscar / agregar CCF, Editar y Eliminar lote.
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'inline-flex min-h-11 sm:min-h-0 items-center'));
        $this->assertStringContainsString('items-center text-sm text-red-600 hover:underline">Eliminar lote', $html);
    }

    public function test_ficha_de_compras_apila_las_lineas_en_el_celular_y_las_imprime_como_tabla(): void
    {
        Storage::fake('local');
        $this->app->instance(MailboxClient::class, new BuzonFalso(disponible: false));
        Storage::disk('local')->put('documentos-recibidos/c1/dte.json', json_encode(['dteJson' => [
            'identificacion' => ['tipoDte' => '03', 'numeroControl' => 'DTE-03-F0000001-000000000000555', 'fecEmi' => '2026-11-04'],
            'emisor' => ['nombre' => 'PROVEEDOR DE PRUEBA'],
            'cuerpoDocumento' => [['numItem' => 1, 'cantidad' => 2, 'descripcion' => 'Caja de prueba', 'precioUni' => 5.0,
                'ventaNoSuj' => 0, 'ventaExenta' => 0, 'ventaGravada' => 10.0]],
            'resumen' => ['totalGravada' => 10.0, 'totalPagar' => 11.3],
        ]]));
        $compra = DocumentoRecibido::create([
            'gmail_message_id' => 'c1', 'emisor_nombre' => 'PROVEEDOR DE PRUEBA', 'tipo_documento' => '03',
            'numero_control' => 'DTE-03-F0000001-000000000000555', 'estado' => 'pendiente', 'clasificacion' => 'dte_valido',
            'total' => 11.30, 'tiene_pdf' => false, 'tiene_json' => true, 'fecha_correo' => '2026-11-04',
            'metadata_json' => ['archivos' => ['documentos-recibidos/c1/dte.json']],
        ]);

        $this->get(route('documentos-recibidos.ver', $compra))->assertOk()
            ->assertSee('Caja de prueba')
            // Solo en pantalla: la hoja impresa mide menos de 768 px y sigue saliendo como tabla.
            ->assertSee('@media screen and (max-width: 767px)', false)
            ->assertSee('<span class="fr-cel">Descripción</span>Caja de prueba', false)
            ->assertSee('<span class="fr-cel">Gravado</span>$10.00', false);
    }

    public function test_documentos_fiscales_pliega_accesos_y_botones_de_crear_en_el_celular(): void
    {
        $this->borradorConLinea();

        $this->get(route('facturacion.index', ['tipo_dte' => '03']))->assertOk()
            // Accesos rápidos: arriba solo desde md; en el celular, dentro del panel «Filtros».
            ->assertSee('<div class="hidden md:block">', false)
            ->assertSee('<nav aria-label="Accesos rápidos" class="md:hidden', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('col-span-2 sm:col-span-1', false);
    }

    public function test_jefatura_en_el_celular_ve_tarjetas_reducidas_con_ver_pdf(): void
    {
        $dte = $this->borradorConLinea();
        Dte::withoutEvents(fn () => $dte->forceFill([
            'estado' => 'aceptado', 'numero_control' => 'DTE-03-M001P001-000000000000777',
            'sello_recepcion' => 'SELLO-DE-PRUEBA-0001', 'fecha_procesamiento_mh' => now(), 'fecha_emision' => now()->toDateString(),
        ])->saveQuietly());
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');

        $html = $this->actingAs($jefatura)->get(route('facturacion.index'))->assertOk()
            ->assertSee('Cliente de prueba Celular')
            ->assertDontSee('href="'.route('facturacion.show', $dte).'"', false)
            ->getContent();

        // «Ver PDF» una vez en la tabla (desde md) y otra en la tarjeta del celular.
        $this->assertSame(2, substr_count($html, 'href="'.route('facturacion.pdf', $dte).'"'));
        $this->assertStringContainsString('<ul class="md:hidden', $html);
    }
}
