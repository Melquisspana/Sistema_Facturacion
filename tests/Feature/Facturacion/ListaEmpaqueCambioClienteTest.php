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
 * Una FEX emitida a otro cliente impide el cambio: la lista diría un cliente y saldría
 * impresa con la factura del otro. Sin eso, las líneas guardadas pasan al cliente nuevo
 * con SU precio, nunca con el del anterior, que al finalizar quedaría como el suyo. Una
 * lista que no tenía cliente lo recibe sin perder sus líneas. Datos ficticios.
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

    /**
     * La línea guardada viaja con su id y con el precio que traía ($140, el del cliente A):
     * así la manda una página que no se actualizó. Pasa al cliente B con el precio de B.
     */
    public function test_sin_fex_la_linea_guardada_pasa_al_cliente_nuevo_con_su_precio_y_no_con_el_anterior(): void
    {
        $lista = $this->listaDelClienteA();

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['id' => $lista->items()->sole()->id, 'cantidad_cajas' => 12, 'precio_caja' => '140.00'],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('facturacion.listas.show', $lista));

        $lista->refresh();
        $linea = $lista->items()->sole();
        $this->assertSame($this->clienteB->id, (int) $lista->exportacion_cliente_id);
        $this->assertSame($this->producto->id, (int) $linea->exportacion_producto_id);
        $this->assertSame(12, (int) $linea->cantidad_cajas);
        $this->assertEquals(150.00, (float) $linea->precio_caja);
    }

    /** Un precio escrito en la línea, distinto del que tenía guardado, se respeta. */
    public function test_al_cambiar_el_cliente_se_respeta_el_precio_escrito_en_la_linea_guardada(): void
    {
        $lista = $this->listaDelClienteA();

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['id' => $lista->items()->sole()->id, 'cantidad_cajas' => 10, 'precio_caja' => '145.50'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame($this->clienteB->id, (int) $lista->fresh()->exportacion_cliente_id);
        $this->assertEquals(145.50, (float) $lista->items()->sole()->precio_caja);
    }

    /**
     * Lo que manda el formulario al elegir otro cliente: la línea guardada con su id y el
     * precio de B, que es el que muestra. El cambio pasa y queda en la bitácora.
     */
    public function test_sin_fex_el_cliente_cambia_con_lo_que_manda_el_formulario_y_queda_en_la_bitacora(): void
    {
        $lista = $this->listaDelClienteA();

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['id' => $lista->items()->sole()->id, 'cantidad_cajas' => 10, 'precio_caja' => '150.00'],
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

    /** Lista sin cliente: heredada, de un perfil borrado o copia de una de ellas. */
    private function sinCliente(Exportacion $lista): Exportacion
    {
        Exportacion::query()->whereKey($lista->id)->update(['exportacion_cliente_id' => null]);

        return $lista->refresh();
    }

    /**
     * Ponerle cliente a una lista que no tenía no es cambiarlo: no hay precios de otro
     * cliente que cuidar. Conserva sus líneas y avisa cuál no lleva el precio actual.
     */
    public function test_una_lista_sin_cliente_recibe_el_cliente_y_conserva_sus_lineas_con_aviso(): void
    {
        $lista = $this->sinCliente($this->listaDelClienteA());
        $linea = $lista->items()->sole();

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['id' => $linea->id, 'cantidad_cajas' => 12, 'precio_caja' => '140.00'],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('facturacion.listas.show', $lista));

        $this->assertSame($this->clienteB->id, (int) $lista->fresh()->exportacion_cliente_id);
        $this->assertSame(12, (int) $linea->fresh()->cantidad_cajas);
        $this->assertEquals(140.00, (float) $linea->fresh()->precio_caja);
        $aviso = (string) session('aviso_precios');
        $this->assertStringContainsString('Producto de prueba 144 u $140.00 (actual $150.00)', $aviso);
        $this->assertStringNotContainsString('cliente anterior', $aviso);
    }

    /** Lista heredada sin cliente con la FEX ya emitida: recibe el cliente de esa FEX. */
    public function test_una_lista_sin_cliente_con_fex_recibe_el_cliente_al_que_se_emitio(): void
    {
        $lista = $this->listaDelClienteA();
        $fex = $this->vincularFexDelClienteA($lista);
        $this->sinCliente($lista);

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteA, [
            ['id' => $lista->items()->sole()->id, 'cantidad_cajas' => 10, 'precio_caja' => '140.00'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame($this->clienteA->id, (int) $lista->fresh()->exportacion_cliente_id);
        $this->assertSame([$fex->id], DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->pluck('dte_id')->map(fn ($id) => (int) $id)->all());
    }

    /** Pero no el de otro cliente: la lista saldría con la factura emitida a otro. */
    public function test_una_lista_sin_cliente_con_fex_no_recibe_otro_cliente(): void
    {
        $lista = $this->listaDelClienteA();
        $this->vincularFexDelClienteA($lista);
        $antes = $this->foto($this->sinCliente($lista));

        $this->actingAs($this->usuario)->put(route('facturacion.listas.update', $lista), $this->formulario($this->clienteB, [
            ['id' => $lista->items()->sole()->id, 'cantidad_cajas' => 10, 'precio_caja' => '140.00'],
        ]))->assertSessionHasErrors('exportacion_cliente_id');

        $this->assertEquals($antes, $this->foto($lista));
    }
}
