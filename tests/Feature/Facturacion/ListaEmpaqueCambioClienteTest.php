<?php

namespace Tests\Feature\Facturacion;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Exportaciones\CatalogoExportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Listas de empaque › «Editar» un borrador eligiendo OTRO cliente, por la ruta.
 *
 * Las líneas guardadas llevan el precio negociado con el cliente anterior y la FEX
 * vinculada es de ese cliente. Si el cambio pasara, la lista diría un cliente y saldría
 * impresa con la factura del otro, y al finalizarla los precios del primero quedarían
 * como los del segundo. Lo seguro es negarlo sin tocar nada. Datos ficticios.
 */
class ListaEmpaqueCambioClienteTest extends TestCase
{
    use RefreshDatabase;

    private ExportacionCliente $clienteA;

    private ExportacionCliente $clienteB;

    private ExportacionProducto $producto;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->producto = app(CatalogoExportacion::class)->crearProducto(
            ['nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'categoria' => null],
            ['unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13, 'precio_caja' => 136.80],
        )->presentaciones()->sole();
        $this->clienteA = $this->perfil('Cliente de prueba A', 140.00);
        $this->clienteB = $this->perfil('Cliente de prueba B', 150.00);
    }

    private function perfil(string $nombre, float $precio): ExportacionCliente
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => $nombre]);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $nombre, 'activo' => true]);
        ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $this->producto->id, 'precio_caja' => $precio, 'activo' => true]);

        return $perfil;
    }

    private function formulario(ExportacionCliente $perfil, array $items): array
    {
        return [
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => $perfil->nombre,
            'exportador_nombre' => 'Exportador de prueba',
            'fecha' => '2026-11-20',
            'items' => $items,
        ];
    }

    /** Lista del cliente A armada desde el formulario: su línea sale a $140. */
    private function listaDelClienteA(): Exportacion
    {
        $this->actingAs($this->usuario)->post(route('facturacion.listas.store'), $this->formulario($this->clienteA, [
            ['exportacion_producto_id' => $this->producto->id, 'cantidad_cajas' => 10, 'precio_caja' => ''],
        ]))->assertSessionHasNoErrors();

        $lista = Exportacion::query()->sole();
        $this->assertEquals(140.00, (float) $lista->items()->sole()->precio_caja);

        return $lista;
    }

    private function vincularFexDelClienteA(Exportacion $lista): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa de prueba', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja de prueba', 'activo' => true]);
        $fex = Dte::create([
            'tipo_dte' => TipoDte::FacturaExportacion->value, 'estado' => 'borrador', 'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'cliente_id' => $this->clienteA->cliente_id,
            'fecha_emision' => '2026-11-20', 'hora_emision' => '10:00:00', 'total_pagar' => 1400.00,
        ]);
        DB::table('exportacion_dte')->insert([
            'exportacion_id' => $lista->id, 'dte_id' => $fex->id, 'principal' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lista->update(['dte_id' => $fex->id]);

        return $fex;
    }

    /** @return array{cliente: int, nombre: string, lineas: array<int, array<string, mixed>>} */
    private function foto(Exportacion $lista): array
    {
        $lista->refresh();

        return [
            'cliente' => (int) $lista->exportacion_cliente_id,
            'nombre' => $lista->cliente_nombre,
            'lineas' => $lista->items()->orderBy('id')->get(['id', 'exportacion_producto_id', 'cantidad_cajas', 'precio_caja'])->toArray(),
        ];
    }

    public function test_con_una_fex_del_cliente_anterior_no_se_puede_cambiar_el_cliente(): void
    {
        $lista = $this->listaDelClienteA();
        $fex = $this->vincularFexDelClienteA($lista);
        $antes = $this->foto($lista);

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['exportacion_producto_id' => $this->producto->id, 'cantidad_cajas' => 10, 'precio_caja' => ''],
        ]))->assertSessionHasErrors('exportacion_cliente_id');

        $this->assertEquals($antes, $this->foto($lista));
        $this->assertSame([$fex->id], DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->pluck('dte_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_sin_fex_pero_con_lineas_guardadas_no_se_puede_cambiar_el_cliente(): void
    {
        $lista = $this->listaDelClienteA();
        $antes = $this->foto($lista);

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['id' => $lista->items()->sole()->id, 'cantidad_cajas' => 12, 'precio_caja' => '140.00'],
        ]))->assertSessionHasErrors('exportacion_cliente_id');

        $this->assertEquals($antes, $this->foto($lista));
    }

    /** Sin FEX y con las líneas vueltas a cargar, el cambio pasa y queda en la bitácora. */
    public function test_sin_fex_y_con_lineas_nuevas_el_cliente_cambia_y_toma_sus_precios(): void
    {
        $lista = $this->listaDelClienteA();

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['exportacion_producto_id' => $this->producto->id, 'cantidad_cajas' => 10, 'precio_caja' => ''],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('facturacion.listas.show', $lista));

        $lista->refresh();
        $this->assertSame($this->clienteB->id, (int) $lista->exportacion_cliente_id);
        $this->assertEquals(150.00, (float) $lista->items()->sole()->precio_caja);

        $cambio = Activity::query()->where('log_name', 'lista_empaque')->where('subject_id', $lista->id)->where('event', 'updated')->sole();
        $this->assertSame($this->usuario->id, (int) $cambio->causer_id);
        $this->assertEquals($this->clienteA->id, $cambio->properties['old']['exportacion_cliente_id']);
        $this->assertEquals($this->clienteB->id, $cambio->properties['attributes']['exportacion_cliente_id']);
        $this->assertEquals('Cliente de prueba B', $cambio->properties['attributes']['cliente_nombre']);
    }

    public function test_con_el_mismo_cliente_las_lineas_guardadas_se_editan_como_siempre(): void
    {
        $lista = $this->listaDelClienteA();
        $this->vincularFexDelClienteA($lista);
        $linea = $lista->items()->sole();

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteA, [
            ['id' => $linea->id, 'cantidad_cajas' => 12, 'precio_caja' => '140.00'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(12, (int) $linea->fresh()->cantidad_cajas);
        $this->assertSame($this->clienteA->id, (int) $lista->fresh()->exportacion_cliente_id);
    }
}
