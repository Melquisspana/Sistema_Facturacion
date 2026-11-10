<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La hoja de lo que salió (etapa 2, PR 1): el sugerido en limpio, una por pedido. Todos
 * los productos que pidió el vendedor, cuánto pidió, cuánto llegó en cada envío vigente y
 * una «F» en lo que faltó, con su motivo. Sin precios. Datos inventados.
 */
class HojaPedidoTest extends TestCase
{
    use RefreshDatabase;

    private User $oficina;

    private User $fabrica;

    private ClienteSucursal $sala;

    private Producto $galleta;

    private Producto $canilla;

    private Producto $pan;

    private PedidoSala $pedido;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');

        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $vendedor->id]);
        $ruta = Ruta::create(['nombre' => 'Ruta de prueba']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Cliente de prueba'])
            ->sucursales()->create(['nombre' => 'Sala de prueba Norte', 'codigo' => '9001', 'ruta_id' => $ruta->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 3.33]);
        $this->pan = Producto::factory()->create(['nombre' => 'Pan de prueba', 'codigo' => 'P01', 'precio_unitario' => 1.11]);

        $this->pedido = PedidoSala::create([
            'cliente_sucursal_id' => $this->sala->id, 'ruta_id' => $ruta->id,
            'sacado_por_id' => $persona->id, 'registrado_por' => $vendedor->id,
            'fecha' => '2026-11-08', 'estado' => EstadoPedidoSala::Enviado, 'numero_orden_compra' => '43010000123456',
        ]);
        $this->pedido->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => '10', 'motivo_no_enviado' => 'No hubo masa']);
        $this->pedido->lineas()->create(['producto_id' => $this->canilla->id, 'cantidad' => '4']);
        $this->pedido->lineas()->create(['producto_id' => $this->pan->id, 'cantidad' => '2']);

        $this->actingAs($this->oficina);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function envio(array $cantidades, bool $anulado = false, string $fecha = '2026-11-09'): PedidoSalaEnvio
    {
        $envio = $this->pedido->envios()->create([
            'numero' => ((int) $this->pedido->envios()->max('numero')) + 1, 'clave' => (string) Str::uuid(), 'fecha' => $fecha,
            'registrado_por' => $this->fabrica->id, 'anulado_en' => $anulado ? now() : null, 'anulado_por' => $anulado ? $this->fabrica->id : null,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $envio->lineas()->create([
                'pedido_sala_linea_id' => $this->pedido->lineas()->where('producto_id', $producto)->value('id'), 'cantidad' => $cantidad,
            ]);
        }

        return $envio;
    }

    public function test_la_oficina_ve_el_sugerido_en_limpio_con_la_f_en_lo_que_falto_y_sin_precios(): void
    {
        $this->envio([$this->galleta->id => '6', $this->canilla->id => '1']);
        $this->envio([$this->canilla->id => '3']);
        // Este se anuló: no llegó, no cuenta.
        $this->envio([$this->pan->id => '2'], anulado: true);

        $respuesta = $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk();

        $respuesta->assertSee('Sala de prueba Norte')
            ->assertSee('9001')
            ->assertSee('Ruta de prueba')
            ->assertSee('Vendedor de prueba')
            ->assertSee('08/11/2026')
            ->assertSee('43010000123456')
            ->assertSee('Envío 1')
            ->assertSee('Envío 2')
            ->assertDontSee('Envío 3')
            // código, producto, pidió, envío 1, envío 2, llegó, falta, motivo
            ->assertSeeInOrder(['G01', 'Galleta de prueba', '10', '6', '6', 'F', '4', 'No hubo masa'])
            ->assertSeeInOrder(['C01', 'Canilla de prueba', '4', '1', '3', '4'])
            ->assertSeeInOrder(['P01', 'Pan de prueba', '2', 'F', '2'])
            ->assertSee('Mandado')
            ->assertSee('F: faltó')
            ->assertSee('Firma')
            ->assertDontSee('7.77')
            ->assertDontSee('3.33')
            ->assertDontSee('1.11')
            ->assertDontSee('Precio');
        // La F solo en la galleta y el pan, que faltaron.
        $this->assertSame(2, substr_count($respuesta->getContent(), 'aria-label="Faltó"'));
    }

    public function test_la_fabrica_no_ve_la_hoja(): void
    {
        $this->envio([$this->galleta->id => '6']);

        $this->actingAs($this->fabrica)->get(route('rutas.pedidos.hoja', $this->pedido))->assertForbidden();
    }

    public function test_el_detalle_del_pedido_lleva_a_la_hoja(): void
    {
        $this->get(route('rutas.pedidos.show', $this->pedido))->assertOk()
            ->assertSee('Imprimir hoja')
            ->assertSee(route('rutas.pedidos.hoja', $this->pedido), false);
    }

    public function test_pedido_anulado_o_pendiente_avisa_y_no_marca_f(): void
    {
        foreach ([
            [EstadoPedidoSala::Anulado, 'Anulado', 'Este pedido está anulado: no se factura.'],
            [EstadoPedidoSala::Registrado, 'Pendiente', 'La fábrica todavía no mandó nada de este pedido.'],
        ] as [$estado, $etiqueta, $aviso]) {
            $this->pedido->update(['estado' => $estado]);

            $respuesta = $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()
                ->assertSee($etiqueta)
                ->assertSee($aviso)
                ->assertDontSee('F: faltó');
            $this->assertSame(0, substr_count($respuesta->getContent(), 'aria-label="Faltó"'));

            // La ficha no ofrece imprimir una hoja que no dice nada.
            $this->get(route('rutas.pedidos.show', $this->pedido))->assertOk()
                ->assertDontSee('Imprimir hoja');
        }
    }

    public function test_mandado_en_parte_marca_lo_que_va_en_otro_envio_en_vez_de_la_f(): void
    {
        $this->pedido->update(['estado' => EstadoPedidoSala::Parcial]);
        $this->envio([$this->galleta->id => '6', $this->canilla->id => '4', $this->pan->id => '2']);

        $respuesta = $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()
            ->assertSee('Mandado en parte')
            ->assertSeeInOrder(['G01', 'Galleta de prueba', '10', '6', 'Va en otro envío', '4'])
            ->assertDontSee('No hubo masa')
            ->assertDontSee('F: faltó');
        $this->assertSame(0, substr_count($respuesta->getContent(), 'aria-label="Faltó"'));
    }

    public function test_vuelve_al_dia_que_se_estaba_mirando_y_cada_envio_dice_su_dia(): void
    {
        $this->envio([$this->galleta->id => '6'], fecha: '2026-11-08');
        $this->envio([$this->canilla->id => '4']);

        $this->get(route('rutas.pedidos.hoja', [$this->pedido, 'fecha' => '2026-11-08']))->assertOk()
            ->assertSee(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-08']), false)
            ->assertDontSee(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']), false)
            ->assertSeeInOrder(['Envío 1 · 08/11/2026', 'Envío 2 · 09/11/2026', 'Envío 1<br>08/11', 'Envío 2<br>09/11', 'G01'], false);

        // Sin fecha, la del último envío; una fecha mal escrita no se usa.
        $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()
            ->assertSee(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']), false);
        $this->get(route('rutas.pedidos.hoja', [$this->pedido, 'fecha' => '8/11/2026']))
            ->assertSessionHasErrors('fecha');
    }

    public function test_la_hoja_y_el_detalle_reciben_lo_que_regresa_de_lo_facturado(): void
    {
        $envio = $this->envio([$this->galleta->id => '6', $this->canilla->id => '4']);
        $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()->assertViewHas('regresa', []);

        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => '03', 'ambiente' => '00', 'estado' => 'borrador', 'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id, 'cliente_id' => $this->sala->cliente_id, 'fecha_emision' => '2026-11-09', 'hora_emision' => '08:00:00',
        ]);
        $dte->save();
        app(DteBorradorService::class)->establecerCantidadProducto($dte, $this->galleta, 5);
        Dte::whereKey($dte->id)->update(['estado' => 'aceptado']);
        $envio->update(['dte_id' => $dte->id]);

        $esperado = [
            $this->galleta->id => ['llego' => '6.0000', 'facturado' => '5.0000', 'regresa' => '1.0000'],
            $this->canilla->id => ['llego' => '4.0000', 'facturado' => '0.0000', 'regresa' => '4.0000'],
        ];
        $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()->assertViewHas('regresa', $esperado);
        $this->get(route('rutas.pedidos.show', $this->pedido))->assertOk()->assertViewHas('regresa', $esperado);
    }

    /** Un CCF aceptado con estas cantidades, enlazado a los envíos (como lo deja FacturarPedido). */
    private function ccf(array $envios, array $cantidades, string $estado = 'aceptado'): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::firstOrCreate(['codigo' => 'M001'], ['empresa_id' => $empresa->id, 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::firstOrCreate(['codigo' => 'P001'], ['establecimiento_id' => $estab->id, 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => '03', 'ambiente' => '00', 'estado' => 'borrador', 'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id, 'cliente_id' => $this->sala->cliente_id, 'fecha_emision' => '2026-11-09', 'hora_emision' => '08:00:00',
        ]);
        $dte->save();
        foreach ($cantidades as $producto => $cantidad) {
            app(DteBorradorService::class)->establecerCantidadProducto($dte, Producto::find($producto), $cantidad);
        }
        Dte::whereKey($dte->id)->update(['estado' => $estado, 'numero_control' => $estado === 'borrador' ? null : 'DTE-03-M001P001-000000000004321']);
        foreach ($envios as $envio) {
            $envio->update(['dte_id' => $dte->id]);
        }

        return $dte->refresh();
    }

    public function test_la_hoja_facturada_lleva_el_ccf_y_lo_que_regresa(): void
    {
        $envio = $this->envio([$this->galleta->id => '6', $this->canilla->id => '4']);
        $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()
            ->assertDontSee('Regresa')
            ->assertDontSee('CCF');

        $this->ccf([$envio], [$this->galleta->id => 5, $this->canilla->id => 4]);

        $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()
            ->assertSee('CCF n.º …4321')
            ->assertSeeInOrder(['Llegó', 'Regresa', 'Falta'])
            // Galleta: llegó 6, se facturó 5, regresa 1. Canilla: regresa 0.
            ->assertSeeInOrder(['Galleta de prueba', '6', '1', 'Canilla de prueba', '4', '0'])
            ->assertDontSee('$');
    }

    public function test_el_detalle_dice_como_va_cada_envio_y_lo_que_regresa(): void
    {
        $uno = $this->envio([$this->galleta->id => '6'], fecha: '2026-11-08');
        $this->envio([$this->canilla->id => '4']);
        $this->get(route('rutas.pedidos.show', $this->pedido))->assertOk()
            ->assertDontSee('Regresa')
            ->assertSee('Por facturar')
            ->assertSee(route('facturacion.create-ccf', ['pedido' => $this->pedido->id]), false);

        $ccf = $this->ccf([$uno], [$this->galleta->id => 5]);

        $this->get(route('rutas.pedidos.show', $this->pedido))->assertOk()
            ->assertSeeInOrder(['Envío 1', 'Facturado en CCF n.º …4321', 'Envío 2', 'Por facturar'])
            ->assertSee(route('facturacion.show', $ccf), false)
            ->assertSeeInOrder(['Mandado', 'Regresa', 'Falta'])
            ->assertSee(route('facturacion.create-ccf', ['pedido' => $this->pedido->id]), false);

        // La fábrica, en «Hechos», no ve nada de facturación.
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))
            ->assertDontSee('Facturado en CCF')->assertDontSee('Por facturar');
    }

    public function test_la_hoja_lleva_el_nombre_de_la_empresa_de_configuracion(): void
    {
        config(['app.name' => 'Sistema de prueba']);
        Empresa::create(['razon_social' => 'Empresa de prueba, S.A. de C.V.', 'nombre_comercial' => 'Confitería de prueba', 'activo' => true]);

        $this->get(route('rutas.pedidos.hoja', $this->pedido))->assertOk()
            ->assertSee('<p class="empresa">Confitería de prueba</p>', false)
            ->assertDontSee('Sistema de prueba');
    }
}
