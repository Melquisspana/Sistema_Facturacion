<?php

namespace Tests\Feature\Facturacion;

use App\Enums\EstadoDte;
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
use App\Models\ProductoPrecioCliente;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\FacturarPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Facturar desde el pedido (etapa 2, PR 2): la oficina crea el borrador del CCF con todo lo
 * que llegó de la fábrica y no se facturó. La fábrica nunca factura. Datos inventados.
 */
class FacturarPedidoTest extends TestCase
{
    use RefreshDatabase;

    private User $oficina;

    private User $fabrica;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private Cliente $cliente;

    private ClienteSucursal $sala;

    private Producto $galleta;

    private Producto $canilla;

    private Producto $quesadilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');

        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta de prueba']);

        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba', 'requiere_orden_compra' => false]);
        $this->sala = $this->cliente->sucursales()->create(['nombre' => 'Sala de prueba Norte', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);

        // Precio: la galleta tiene especial de la sala, la canilla del cliente y la quesadilla el general.
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 1.00]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 2.00]);
        $this->quesadilla = Producto::factory()->create(['nombre' => 'Quesadilla de prueba', 'codigo' => 'Q01', 'precio_unitario' => 3.00]);
        ProductoPrecioCliente::create(['producto_id' => $this->galleta->id, 'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $this->sala->id, 'precio' => 1.25, 'activo' => true]);
        ProductoPrecioCliente::create(['producto_id' => $this->galleta->id, 'cliente_id' => $this->cliente->id, 'precio' => 1.40, 'activo' => true]);
        ProductoPrecioCliente::create(['producto_id' => $this->canilla->id, 'cliente_id' => $this->cliente->id, 'precio' => 1.50, 'activo' => true]);

        $this->actingAs($this->oficina);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(array $cantidades, ?string $oc = null, ?ClienteSucursal $sala = null): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => ($sala ?? $this->sala)->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->oficina->id,
            'fecha' => '2026-11-08', 'estado' => EstadoPedidoSala::Enviado, 'numero_orden_compra' => $oc,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $pedido->lineas()->create(['producto_id' => $producto, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function envio(PedidoSala $pedido, array $cantidades, string $fecha = '2026-11-09', bool $anulado = false): PedidoSalaEnvio
    {
        $envio = $pedido->envios()->create([
            'numero' => ((int) $pedido->envios()->max('numero')) + 1, 'clave' => (string) Str::uuid(), 'fecha' => $fecha,
            'registrado_por' => $this->fabrica->id, 'anulado_en' => $anulado ? now() : null, 'anulado_por' => $anulado ? $this->fabrica->id : null,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $envio->lineas()->create([
                'pedido_sala_linea_id' => $pedido->lineas()->where('producto_id', $producto)->value('id'), 'cantidad' => $cantidad,
            ]);
        }

        return $envio;
    }

    /** @return array<int, float> [producto_id => cantidad] */
    private function cantidades(Dte $dte): array
    {
        return $dte->lineas()->get()->mapWithKeys(fn ($l) => [$l->producto_id => (float) $l->cantidad])->all();
    }

    public function test_crea_el_borrador_con_la_sala_la_oc_y_todo_lo_que_llego_al_precio_del_resolver(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '4', $this->quesadilla->id => '3']);
        $uno = $this->envio($pedido, [$this->galleta->id => '6', $this->canilla->id => '4'], '2026-11-08');
        $dos = $this->envio($pedido, [$this->galleta->id => '4', $this->quesadilla->id => '3.0000']);

        $respuesta = $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000123']);
        $respuesta->assertSessionHasNoErrors();

        $dte = Dte::sole();
        $respuesta->assertRedirect(route('facturacion.edit', $dte));
        $this->assertSame(EstadoDte::Borrador, $dte->estado);
        $this->assertSame('03', $dte->tipo_dte->value);
        $this->assertSame($this->cliente->id, $dte->cliente_id);
        $this->assertSame($this->sala->id, $dte->cliente_sucursal_id);
        $this->assertSame('26119001000123', $dte->numero_orden_compra);
        $this->assertEquals([$this->galleta->id => 10.0, $this->canilla->id => 4.0, $this->quesadilla->id => 3.0], $this->cantidades($dte));
        $precios = $dte->lineas()->pluck('precio_unitario', 'producto_id')->map(fn ($p) => (float) $p)->all();
        $this->assertEquals([$this->galleta->id => 1.25, $this->canilla->id => 1.50, $this->quesadilla->id => 3.00], $precios);
        // Los dos envíos quedan en el mismo CCF.
        $this->assertSame($dte->id, $uno->refresh()->dte_id);
        $this->assertSame($dte->id, $dos->refresh()->dte_id);
        $this->assertSame(0, PedidoSalaEnvio::porFacturar()->count());
    }

    public function test_ignora_la_sala_y_el_cliente_que_vengan_del_formulario(): void
    {
        $otroCliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente de prueba']);
        $otraSala = $otroCliente->sucursales()->create(['nombre' => 'Sala de prueba Sur', 'codigo' => '9002', 'ruta_id' => $this->ruta->id]);
        $pedido = $this->pedido([$this->quesadilla->id => '2']);
        $this->envio($pedido, [$this->quesadilla->id => '2']);

        $this->post(route('facturacion.pedidos.facturar', $pedido), [
            'cliente_id' => $otroCliente->id, 'cliente_sucursal_id' => $otraSala->id, 'tipo_dte' => '01',
        ])->assertSessionHasNoErrors();

        $dte = Dte::sole();
        $this->assertSame('03', $dte->tipo_dte->value);
        $this->assertSame($this->cliente->id, $dte->cliente_id);
        $this->assertSame($this->sala->id, $dte->cliente_sucursal_id);
    }

    public function test_un_doble_toque_hace_un_solo_ccf_y_devuelve_el_mismo_borrador(): void
    {
        $pedido = $this->pedido([$this->quesadilla->id => '2']);
        $this->envio($pedido, [$this->quesadilla->id => '2']);

        $primero = $this->post(route('facturacion.pedidos.facturar', $pedido));
        $segundo = $this->post(route('facturacion.pedidos.facturar', $pedido));

        $dte = Dte::sole();
        $primero->assertRedirect(route('facturacion.edit', $dte));
        $segundo->assertRedirect(route('facturacion.edit', $dte));
        $this->assertEquals([$this->quesadilla->id => 2.0], $this->cantidades($dte));
    }

    public function test_no_toma_los_envios_anulados(): void
    {
        $pedido = $this->pedido([$this->quesadilla->id => '5']);
        $anulado = $this->envio($pedido, [$this->quesadilla->id => '3'], anulado: true);
        $this->envio($pedido, [$this->quesadilla->id => '2']);

        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();

        $this->assertEquals([$this->quesadilla->id => 2.0], $this->cantidades(Dte::sole()));
        $this->assertNull($anulado->refresh()->dte_id);
    }

    public function test_sin_nada_por_facturar_da_un_mensaje_y_no_crea_nada(): void
    {
        $pedido = $this->pedido([$this->quesadilla->id => '5']);
        $this->post(route('facturacion.pedidos.facturar', $pedido))
            ->assertSessionHasErrors(['pedido' => 'Todavía no llegó nada de la fábrica para este pedido.']);

        $this->envio($pedido, [$this->quesadilla->id => '5']);
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        Dte::query()->update(['estado' => EstadoDte::Aceptado->value]);
        $this->post(route('facturacion.pedidos.facturar', $pedido))
            ->assertSessionHasErrors(['pedido' => 'Ya se facturó todo lo que llegó de este pedido.']);
        $this->assertSame(1, Dte::withTrashed()->count());
    }

    public function test_un_borrador_borrado_o_un_ccf_invalidado_o_rechazado_devuelven_el_envio_a_por_facturar(): void
    {
        $pedido = $this->pedido([$this->quesadilla->id => '5']);
        $envio = $this->envio($pedido, [$this->quesadilla->id => '5']);
        $deshacer = [
            'borrado' => fn (Dte $d) => $d->delete(),
            'invalidado' => fn (Dte $d) => Dte::whereKey($d->id)->update(['estado' => EstadoDte::Invalidado->value]),
            'rechazado' => fn (Dte $d) => Dte::whereKey($d->id)->update(['estado' => EstadoDte::Rechazado->value]),
        ];

        $anterior = null;
        foreach ($deshacer as $como => $paso) {
            $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
            $dte = Dte::latest('id')->first();
            $this->assertNotSame($anterior?->id, $dte->id, $como);
            $this->assertTrue($envio->refresh()->estaFacturado(), $como);
            $this->assertSame(0, PedidoSalaEnvio::porFacturar()->count(), $como);

            $paso($dte);
            $this->assertFalse($envio->refresh()->estaFacturado(), $como);
            $this->assertSame([$envio->id], PedidoSalaEnvio::porFacturar()->pluck('id')->all(), $como);
            $anterior = $dte;
        }

        // Facturar otra vez funciona y la bitácora guarda el CCF que tenía antes.
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $this->assertSame(4, Dte::withTrashed()->count());
        $ultimo = Activity::where('description', 'facturó lo que llegó del pedido')->latest('id')->first();
        $this->assertSame([$anterior->id], $ultimo->properties['dte_anteriores']);
    }

    public function test_un_producto_sin_precio_inactivo_o_con_decimales_frena_y_no_crea_nada(): void
    {
        $sinPrecio = Producto::factory()->create(['nombre' => 'Turrón de prueba', 'precio_unitario' => 0]);
        $inactivo = Producto::factory()->create(['nombre' => 'Cocadita de prueba', 'activo' => false]);
        $pedido = $this->pedido([$sinPrecio->id => '1', $inactivo->id => '1', $this->galleta->id => '3', $this->quesadilla->id => '3']);
        $this->envio($pedido, [$sinPrecio->id => '1', $inactivo->id => '1', $this->galleta->id => '2.5000', $this->quesadilla->id => '3']);

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000123'])
            ->assertSessionHasErrors('pedido');
        $this->assertSame([
            'Turrón de prueba no tiene precio para esta sala.',
            'Cocadita de prueba está inactivo en Productos.',
            'Galleta de prueba llegó con decimales (2.5): el CCF solo lleva cantidades enteras.',
        ], session('errors')->get('pedido'));

        $this->assertSame(0, Dte::withTrashed()->count());
        $this->assertSame(1, PedidoSalaEnvio::porFacturar()->count());
        $this->assertFalse(Activity::where('description', 'facturó lo que llegó del pedido')->exists());
    }

    public function test_la_sala_que_exige_oc_sin_oc_da_error_y_no_crea_nada(): void
    {
        $this->sala->update(['requiere_orden_compra' => true]);
        $pedido = $this->pedido([$this->quesadilla->id => '2']);
        $this->envio($pedido, [$this->quesadilla->id => '2']);

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => ''])
            ->assertSessionHasErrors('numero_orden_compra');

        $this->assertSame(0, Dte::withTrashed()->count());
        $this->assertSame(1, PedidoSalaEnvio::porFacturar()->count());
    }

    public function test_la_sala_que_no_permite_ccf_da_un_mensaje_claro(): void
    {
        $this->sala->update(['permite_ccf' => false]);
        $pedido = $this->pedido([$this->quesadilla->id => '2']);
        $this->envio($pedido, [$this->quesadilla->id => '2']);

        $this->post(route('facturacion.pedidos.facturar', $pedido))
            ->assertSessionHasErrors(['cliente_sucursal_id' => 'Esta sala no permite emitir CCF.']);

        $this->assertSame(0, Dte::withTrashed()->count());
    }

    public function test_un_pedido_anulado_no_se_factura(): void
    {
        $pedido = $this->pedido([$this->quesadilla->id => '2']);
        $this->envio($pedido, [$this->quesadilla->id => '2']);
        $pedido->update(['estado' => EstadoPedidoSala::Anulado]);

        $this->post(route('facturacion.pedidos.facturar', $pedido))
            ->assertSessionHasErrors(['pedido' => 'El vendedor anuló este pedido.']);
        $this->assertSame(0, Dte::withTrashed()->count());
    }

    public function test_la_fabrica_y_quien_no_factura_reciben_403(): void
    {
        $pedido = $this->pedido([$this->quesadilla->id => '2']);
        $this->envio($pedido, [$this->quesadilla->id => '2']);

        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');
        foreach ([$this->fabrica, $jefatura] as $usuario) {
            $this->actingAs($usuario);
            $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertForbidden();
            $this->post(route('facturacion.pedidos.facturar', $pedido))->assertForbidden();
        }
        $this->actingAs($this->fabrica)->get(route('facturacion.llego-de-fabrica', ['sin_facturar' => 1]))->assertForbidden();

        $this->assertSame(0, Dte::withTrashed()->count());
    }

    public function test_la_bitacora_registra_el_pedido_los_envios_el_ccf_y_la_oc(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->quesadilla->id => '3']);
        $uno = $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $dos = $this->envio($pedido, [$this->galleta->id => '4', $this->quesadilla->id => '3']);

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000123'])->assertSessionHasNoErrors();

        $log = Activity::where('description', 'facturó lo que llegó del pedido')->sole();
        $this->assertSame('pedido_sala', $log->log_name);
        $this->assertTrue($log->subject->is($pedido));
        $this->assertSame($this->oficina->id, $log->causer_id);
        $this->assertSame(Dte::sole()->id, $log->properties['dte_id']);
        $this->assertSame('26119001000123', $log->properties['numero_orden_compra']);
        $this->assertSame([['envio_id' => $uno->id, 'numero' => 1], ['envio_id' => $dos->id, 'numero' => 2]], $log->properties['envios']);
        $this->assertSame([
            ['producto_id' => $this->galleta->id, 'cantidad' => 10],
            ['producto_id' => $this->quesadilla->id, 'cantidad' => 3],
        ], $log->properties['lineas']);
    }

    public function test_el_formulario_del_ccf_recibe_el_pedido_y_lo_que_llego(): void
    {
        $sinPrecio = Producto::factory()->create(['nombre' => 'Turrón de prueba', 'precio_unitario' => 0]);
        $pedido = $this->pedido([$this->galleta->id => '10', $sinPrecio->id => '1'], '26119001000123');
        $this->envio($pedido, [$this->galleta->id => '10', $sinPrecio->id => '1']);

        $respuesta = $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertViewHas('pedido', fn ($p) => $p->is($pedido));
        $propuesta = $respuesta->viewData('propuesta');
        $this->assertSame('26119001000123', $propuesta['oc']);
        $this->assertSame(['Galleta de prueba', 'Turrón de prueba'], array_column($propuesta['lineas'], 'nombre'));
        $this->assertSame([10, 1], array_column($propuesta['lineas'], 'entero'));
        $this->assertSame([1.25, null], array_map(fn ($p) => $p === null ? null : (float) $p, array_column($propuesta['lineas'], 'precio')));
        $this->assertSame(['Turrón de prueba no tiene precio para esta sala.'], $propuesta['problemas']);

        // Sin ?pedido= la pantalla queda igual.
        $this->get(route('facturacion.create-ccf'))->assertOk()->assertViewMissing('pedido')->assertViewMissing('propuesta');
        $this->get(route('facturacion.create-ccf', ['pedido' => 999999]))->assertNotFound();
    }

    public function test_regresa_es_lo_que_llego_menos_lo_facturado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->quesadilla->id => '3']);
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->envio($pedido, [$this->galleta->id => '4', $this->quesadilla->id => '3']);
        $facturar = app(FacturarPedido::class);
        $this->assertSame([], $facturar->regresa($pedido));

        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $dte = Dte::sole();
        // La OC pidió 7 galletas y nada de quesadillas: se baja en el editor.
        $this->post(route('facturacion.productos.cantidad', [$dte, $this->galleta]), ['cantidad' => 7])->assertSessionHasNoErrors();
        $this->post(route('facturacion.productos.cantidad', [$dte, $this->quesadilla]), ['cantidad' => 0])->assertSessionHasNoErrors();

        $this->assertSame([
            $this->galleta->id => ['llego' => '10.0000', 'facturado' => '7.0000', 'regresa' => '3.0000'],
            $this->quesadilla->id => ['llego' => '3.0000', 'facturado' => '0.0000', 'regresa' => '3.0000'],
        ], $facturar->regresa($pedido));

        // Si el CCF se invalida, ya no hay nada facturado.
        Dte::whereKey($dte->id)->update(['estado' => EstadoDte::Invalidado->value]);
        $this->assertSame([], $facturar->regresa($pedido));
    }

    public function test_el_estado_de_cada_pedido_sale_de_los_envios_cargados(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->quesadilla->id => '3']);
        $uno = $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $cargar = fn () => $pedido->fresh()->load(['envios' => fn ($q) => $q->whereNull('anulado_en'), 'envios.dte']);

        $estado = FacturarPedido::estado($cargar());
        $this->assertSame([$uno->id], $estado['por_facturar']->modelKeys());
        $this->assertNull($estado['borrador']);

        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $dos = $this->envio($pedido, [$this->galleta->id => '4']);
        $estado = FacturarPedido::estado($cargar());
        $this->assertSame([$dos->id], $estado['por_facturar']->modelKeys());
        $this->assertSame(Dte::sole()->id, $estado['borrador']->id);
        $this->assertCount(0, $estado['facturados']);

        Dte::query()->update(['estado' => EstadoDte::Aceptado->value]);
        $estado = FacturarPedido::estado($cargar());
        $this->assertNull($estado['borrador']);
        $this->assertSame([Dte::sole()->id], $estado['facturados']->modelKeys());
    }
}
