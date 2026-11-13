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
use App\Services\Dte\DteBorradorService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
}
