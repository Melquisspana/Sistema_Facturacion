<?php

namespace Tests\Feature\Facturacion;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\OrdenCompraRecibida;
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

        $respuesta = $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000317']);
        $respuesta->assertSessionHasNoErrors();

        $dte = Dte::sole();
        $respuesta->assertRedirect(route('facturacion.edit', $dte));
        $this->assertSame(EstadoDte::Borrador, $dte->estado);
        $this->assertSame('03', $dte->tipo_dte->value);
        $this->assertSame($this->cliente->id, $dte->cliente_id);
        $this->assertSame($this->sala->id, $dte->cliente_sucursal_id);
        $this->assertSame('26119001000317', $dte->numero_orden_compra);
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
            ->assertSessionHasErrors(['pedido' => 'Todavía no se despachó nada de este pedido.']);

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

    public function test_el_ccf_que_corrige_a_uno_rechazado_se_queda_con_los_envios(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $envio = $this->envio($pedido, [$this->galleta->id => '10']);
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $original = Dte::sole();

        // Mientras el CCF cuenta, «Duplicar» no le quita los envíos.
        Dte::whereKey($original->id)->update(['estado' => EstadoDte::Aceptado->value]);
        $this->post(route('facturacion.duplicar', $original))->assertSessionMissing('error');
        $this->assertSame($original->id, $envio->refresh()->dte_id);
        Dte::latest('id')->first()->delete();

        // Hacienda lo rechaza y se corrige con «Duplicar»: lo que llegó queda en el CCF nuevo.
        Dte::whereKey($original->id)->update(['estado' => EstadoDte::Rechazado->value]);
        $this->post(route('facturacion.duplicar', $original))->assertSessionMissing('error');
        $nuevo = Dte::latest('id')->first();
        $this->assertSame($nuevo->id, $envio->refresh()->dte_id);
        $this->assertTrue($envio->estaFacturado());
        $this->assertSame(0, PedidoSalaEnvio::porFacturar()->count());

        // «Facturar» ya no hace otro CCF con la misma mercadería: lleva al que lo corrige.
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertRedirect(route('facturacion.edit', $nuevo));
        $this->assertSame(3, Dte::withTrashed()->count());

        $log = Activity::where('description', 'el CCF que corrige se quedó con lo que llegó del pedido')->sole();
        $this->assertTrue($log->subject->is($pedido));
        $this->assertSame($nuevo->id, $log->properties['dte_id']);
        $this->assertSame($original->id, $log->properties['dte_anterior']);
        // assertEquals: MySQL reordena las claves de la columna JSON.
        $this->assertEquals([['envio_id' => $envio->id, 'numero' => 1]], $log->properties['envios']);
    }

    public function test_un_producto_sin_precio_inactivo_o_con_decimales_frena_y_no_crea_nada(): void
    {
        $sinPrecio = Producto::factory()->create(['nombre' => 'Turrón de prueba', 'precio_unitario' => 0]);
        $inactivo = Producto::factory()->create(['nombre' => 'Cocadita de prueba', 'activo' => false]);
        $pedido = $this->pedido([$sinPrecio->id => '1', $inactivo->id => '1', $this->galleta->id => '3', $this->quesadilla->id => '3']);
        $this->envio($pedido, [$sinPrecio->id => '1', $inactivo->id => '1', $this->galleta->id => '2.5000', $this->quesadilla->id => '3']);

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000317'])
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
            ->assertSessionHasErrors(['pedido' => 'Este pedido está anulado.']);
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

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000317'])->assertSessionHasNoErrors();

        $log = Activity::where('description', 'facturó lo que llegó del pedido')->sole();
        $this->assertSame('pedido_sala', $log->log_name);
        $this->assertTrue($log->subject->is($pedido));
        $this->assertSame($this->oficina->id, $log->causer_id);
        $this->assertSame(Dte::sole()->id, $log->properties['dte_id']);
        $this->assertSame('26119001000317', $log->properties['numero_orden_compra']);
        // assertEquals: MySQL reordena las claves de la columna JSON.
        $this->assertEquals([['envio_id' => $uno->id, 'numero' => 1], ['envio_id' => $dos->id, 'numero' => 2]], $log->properties['envios']);
        $this->assertEquals([
            ['producto_id' => $this->galleta->id, 'cantidad' => 10],
            ['producto_id' => $this->quesadilla->id, 'cantidad' => 3],
        ], $log->properties['lineas']);
    }

    public function test_el_formulario_del_ccf_recibe_el_pedido_y_lo_que_llego(): void
    {
        $sinPrecio = Producto::factory()->create(['nombre' => 'Turrón de prueba', 'precio_unitario' => 0]);
        $pedido = $this->pedido([$this->galleta->id => '10', $sinPrecio->id => '1'], '26119001000317');
        $this->envio($pedido, [$this->galleta->id => '10', $sinPrecio->id => '1']);

        $respuesta = $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertViewHas('pedido', fn ($p) => $p->is($pedido));
        $propuesta = $respuesta->viewData('propuesta');
        $this->assertSame('26119001000317', $propuesta['oc']);
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

        // Si en el editor se pone más de lo que llegó, no regresa nada (nunca negativo).
        $this->post(route('facturacion.productos.cantidad', [$dte, $this->galleta]), ['cantidad' => 12])->assertSessionHasNoErrors();
        $this->assertSame(
            ['llego' => '10.0000', 'facturado' => '12.0000', 'regresa' => '0.0000'],
            $facturar->regresa($pedido)[$this->galleta->id],
        );

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

    private function botonApagado(string $html): bool
    {
        preg_match_all('/<button\b[^>]*>/', $html, $botones);
        $boton = collect($botones[0])->first(fn (string $b) => str_contains($b, 'id="crear-borrador"'));
        $this->assertNotNull($boton, 'Falta el botón «Crear borrador».');

        return (bool) preg_match('/\sdisabled(\s|=|>)/', $boton);
    }

    public function test_el_formulario_con_el_pedido_muestra_lo_que_llego_y_manda_a_facturar(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->quesadilla->id => '3'], '26119001000317');
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->envio($pedido, [$this->galleta->id => '4', $this->quesadilla->id => '3']);

        $respuesta = $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk();

        $respuesta->assertSee('action="'.route('facturacion.pedidos.facturar', $pedido).'"', false)
            ->assertDontSee('action="'.route('facturacion.store-ccf').'"', false)
            // La sala va fija: sin buscador de cliente.
            ->assertDontSee('id="cliente_buscar"', false)
            ->assertSee('Sala de prueba Norte')
            ->assertSee('value="26119001000317"', false)
            ->assertSee('Lo que llegó')
            ->assertSeeInOrder(['Galleta de prueba', '10', 'Quesadilla de prueba', '3'])
            ->assertSee('Envío 1 · 08/11/2026')
            ->assertSee('Envío 2 · 09/11/2026')
            ->assertSee('Crear borrador');
        $this->assertFalse($this->botonApagado($respuesta->getContent()));

        // Lo que se escribió y volvió con error manda sobre la OC del pedido.
        $this->withSession(['_old_input' => ['numero_orden_compra' => '26119001000999']])
            ->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))
            ->assertSee('value="26119001000999"', false)
            ->assertDontSee('value="26119001000317"', false);
    }

    public function test_el_formulario_muestra_lo_que_frena_y_apaga_el_boton(): void
    {
        $sinPrecio = Producto::factory()->create(['nombre' => 'Turrón de prueba', 'precio_unitario' => 0]);
        $pedido = $this->pedido([$this->galleta->id => '3', $sinPrecio->id => '1']);
        $this->envio($pedido, [$this->galleta->id => '2.5000', $sinPrecio->id => '1']);

        $respuesta = $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertSee('Turrón de prueba no tiene precio para esta sala.')
            ->assertSee('Galleta de prueba llegó con decimales (2.5): el CCF solo lleva cantidades enteras.');
        $this->assertTrue($this->botonApagado($respuesta->getContent()));

        // Sin nada por facturar, también apagado.
        $vacio = $this->pedido([$this->galleta->id => '3']);
        $respuesta = $this->get(route('facturacion.create-ccf', ['pedido' => $vacio->id]))->assertOk()
            ->assertSee('No queda nada por facturar de este pedido.');
        $this->assertTrue($this->botonApagado($respuesta->getContent()));
    }

    public function test_sin_pedido_el_formulario_del_ccf_queda_igual(): void
    {
        $this->get(route('facturacion.create-ccf'))->assertOk()
            ->assertSee('action="'.route('facturacion.store-ccf').'"', false)
            ->assertSee('id="cliente_buscar"', false)
            ->assertSee('Crear CCF')
            ->assertDontSee('Lo que llegó')
            ->assertDontSee('crear-borrador');
    }

    public function test_con_un_borrador_abierto_lo_que_llega_despues_no_hace_otro_ccf(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $borrador = Dte::sole();
        $this->envio($pedido, [$this->galleta->id => '4']);

        // El formulario lo avisa con el enlace al borrador y no deja crear otro.
        $respuesta = $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertSee(FacturarPedido::BORRADOR_ABIERTO)
            ->assertSee(route('facturacion.edit', $borrador), false);
        $this->assertTrue($this->botonApagado($respuesta->getContent()));

        // El servidor tampoco: dos CCF con la misma OC confunden la entrega y Cobros.
        $this->post(route('facturacion.pedidos.facturar', $pedido))
            ->assertSessionHasErrors(['pedido' => FacturarPedido::BORRADOR_ABIERTO]);
        $this->assertSame(1, Dte::withTrashed()->count());

        // Borrado el borrador, sale un solo CCF con los dos envíos.
        $borrador->delete();
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $this->assertEquals([$this->galleta->id => 10.0], $this->cantidades(Dte::sole()));
    }

    public function test_el_editor_avisa_si_un_producto_pasa_de_lo_que_llego_o_no_vino_sin_frenar(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->envio($pedido, [$this->galleta->id => '4']);
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $dte = Dte::sole();

        $this->get(route('facturacion.edit', $dte))->assertOk()
            ->assertSee('Viene del pedido N.º '.$pedido->id)
            ->assertSee(route('rutas.pedidos.show', $pedido), false)
            ->assertDontSee('Revisá contra lo que llegó de pedidos');

        $this->post(route('facturacion.productos.cantidad', [$dte, $this->galleta]), ['cantidad' => 12])->assertSessionHasNoErrors();
        $this->post(route('facturacion.productos.cantidad', [$dte, $this->canilla]), ['cantidad' => 2])->assertSessionHasNoErrors();

        $this->get(route('facturacion.edit', $dte))->assertOk()
            ->assertSee('Revisá contra lo que llegó de pedidos')
            ->assertSee('Galleta de prueba: van 12 y llegaron 10.')
            ->assertSee('Canilla de prueba no vino en lo despachado de este pedido.');

        // Un CCF hecho por «Nuevo CCF» no dice nada de pedidos.
        $this->post(route('facturacion.store-ccf'), ['tipo_dte' => '03', 'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $this->sala->id])
            ->assertSessionHasNoErrors();
        $suelto = Dte::latest('id')->first();
        $this->assertNotSame($dte->id, $suelto->id);
        $this->get(route('facturacion.edit', $suelto))->assertOk()
            ->assertDontSee('Viene del pedido')
            ->assertDontSee('Revisá contra lo que llegó de pedidos');
    }

    public function test_despues_de_un_rechazo_el_formulario_no_avisa_que_la_oc_ya_tiene_ccf(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10'], '26119001000317');
        $this->envio($pedido, [$this->galleta->id => '10']);
        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000317'])->assertSessionHasNoErrors();
        Dte::query()->update(['estado' => EstadoDte::Rechazado->value]);

        // Hacienda lo rechazó: hay que volver a facturar, sin el aviso de facturar dos veces.
        $respuesta = $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertDontSee('La OC del pedido ya tiene un CCF');
        $this->assertNull($respuesta->viewData('propuesta')['oc_con_ccf']);
        $this->assertFalse($this->botonApagado($respuesta->getContent()));

        // Un CCF con esa OC que sí cuenta sigue avisando.
        $this->post(route('facturacion.store-ccf'), ['tipo_dte' => '03', 'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $this->sala->id, 'numero_orden_compra' => '26119001000317'])
            ->assertSessionHasNoErrors();
        $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertSee('La OC del pedido ya tiene un CCF');
    }

    public function test_con_un_borrador_abierto_el_editor_y_el_pedido_dicen_que_llego_algo_despues(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $borrador = Dte::sole();
        $aviso = 'El envío 2 de este pedido llegó después y no está en este borrador.';
        $this->get(route('facturacion.edit', $borrador))->assertOk()->assertDontSee($aviso);

        $this->envio($pedido, [$this->galleta->id => '4']);

        $this->get(route('facturacion.edit', $borrador))->assertOk()
            ->assertSee($aviso)
            ->assertSee('Para que salga un solo CCF con todo, eliminá este borrador en la lista de Facturación y tocá «Facturar» otra vez.');
        // El detalle no tiene «Facturar» y dice por qué.
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertDontSee(route('facturacion.create-ccf', ['pedido' => $pedido->id]), false)
            ->assertSee('Llegó algo después del borrador. Borralo y tocá «Facturar» otra vez: así sale un solo CCF con todo.');
    }

    public function test_el_aviso_de_lo_que_llego_se_actualiza_al_cambiar_cantidades_sin_recargar(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '10']);
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();
        $dte = Dte::sole();
        $cantidad = fn (int $n) => $this->postJson(route('facturacion.productos.cantidad', [$dte, $this->galleta]), ['cantidad' => $n])
            ->assertOk()->json('resumen_html');

        $html = $cantidad(12);
        $this->assertStringContainsString('Revisá contra lo que llegó de pedidos', $html);
        $this->assertStringContainsString('Galleta de prueba: van 12 y llegaron 10.', $html);

        $this->assertStringNotContainsString('Revisá contra lo que llegó de pedidos', $cantidad(10));
    }

    public function test_cancelar_vuelve_a_donde_se_estaba_y_va_antes_que_crear_borrador(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '10']);
        $enlace = fn (string $url) => 'href="'.e($url).'"';

        // Desde el detalle del pedido vuelve a la ficha (el enlace de la ficha, en
        // test_el_facturar_de_la_ficha_del_pedido_tambien_va_directo).
        $desdePedido = route('facturacion.create-ccf', ['pedido' => $pedido->id, 'desde' => 'pedido']);
        $this->get($desdePedido)->assertOk()
            ->assertSeeInOrder([$enlace(route('rutas.pedidos.show', $pedido)), 'Cancelar', 'Crear borrador'], false);

        // Desde la lista, a la lista con el mismo día; si no se sabe, a todo lo pendiente.
        $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id, 'fecha' => '2026-11-08']))->assertOk()
            ->assertSee($enlace(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-08'])), false);
        // (El menú de Facturación también enlaza la lista: se mira el «Cancelar».)
        foreach ([['fecha' => 'no-es-fecha'], []] as $filtro) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($enlace(route('facturacion.llego-de-fabrica')), '/').'\s*class="[^"]*">Cancelar</',
                $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id] + $filtro))->assertOk()->getContent(),
            );
        }
    }

    private function ocDelCorreo(ClienteSucursal $sala, string $numero): void
    {
        OrdenCompraRecibida::create([
            'numero' => $numero, 'sala_codigo' => $sala->codigo, 'cliente_sucursal_id' => $sala->id,
            'fecha_creacion' => '2026-11-08', 'fecha_despacho' => '2026-11-10', 'total' => '22.90',
            'pdf_path' => 'ppq/ordenes-compra/x.pdf', 'pdf_hash' => hash('sha256', $numero),
            'gmail_message_id' => 'm-'.$numero, 'estado' => OrdenCompraRecibida::LEIDA,
        ]);
    }

    private function salaQueExigeOc(string $nombre, string $codigo): ClienteSucursal
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba '.$codigo, 'requiere_orden_compra' => true])
            ->sucursales()->create(['nombre' => $nombre, 'codigo' => $codigo, 'ruta_id' => $this->ruta->id]);
    }

    /** El «Facturar» de la fila: el formulario que manda directo al borrador, o el enlace al formulario del CCF. */
    private function directo(string $html, PedidoSala $pedido): ?string
    {
        $accion = preg_quote('action="'.route('facturacion.pedidos.facturar', $pedido).'"', '/');
        if (preg_match('/<form method="POST" '.$accion.'>(.*?)<\/form>/s', $html, $form) !== 1) {
            return null;
        }
        preg_match('/name="numero_orden_compra" value="([^"]*)"/', $form[1], $oc);

        return $oc[1] ?? '';
    }

    /**
     * Melqui (24/11/2026): «Facturar» va directo al borrador cuando hay una sola OC clara (la
     * del vendedor, o la única que llegó por correo) o la sala no pide OC; el formulario sale
     * solo si falta la OC o hay varias para elegir.
     */
    public function test_con_una_sola_oc_clara_facturar_va_directo_al_borrador(): void
    {
        $delVendedor = $this->pedido([$this->galleta->id => '4'], '26119001000317');
        $this->envio($delVendedor, [$this->galleta->id => '4']);
        $salaCorreo = $this->salaQueExigeOc('Sala de prueba Correo', '9002');
        $this->ocDelCorreo($salaCorreo, '26119002000555');
        $delCorreo = $this->pedido([$this->quesadilla->id => '2'], sala: $salaCorreo);
        $this->envio($delCorreo, [$this->quesadilla->id => '2']);
        $sinOc = $this->pedido([$this->quesadilla->id => '1'], sala: $this->salaQueExigeOc('Sala de prueba Sin OC', '9003'));
        $this->envio($sinOc, [$this->quesadilla->id => '1']);
        $salaVarias = $this->salaQueExigeOc('Sala de prueba Varias', '9004');
        $this->ocDelCorreo($salaVarias, '26119004000111');
        $this->ocDelCorreo($salaVarias, '26119004000222');
        $varias = $this->pedido([$this->quesadilla->id => '1'], sala: $salaVarias);
        $this->envio($varias, [$this->quesadilla->id => '1']);
        $noPide = $this->pedido([$this->quesadilla->id => '3']);
        $this->envio($noPide, [$this->quesadilla->id => '3']);

        $html = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->getContent();

        $this->assertSame('26119001000317', $this->directo($html, $delVendedor));
        $this->assertSame('26119002000555', $this->directo($html, $delCorreo));
        $this->assertSame('', $this->directo($html, $noPide));
        foreach ([$sinOc, $varias] as $pedido) {
            $this->assertNull($this->directo($html, $pedido));
            $this->assertStringContainsString('href="'.e(route('facturacion.create-ccf', ['pedido' => $pedido->id])).'"', $html);
        }
        $this->assertStringNotContainsString(e(route('facturacion.create-ccf', ['pedido' => $delCorreo->id])), $html);

        // Lo que manda la fila: el borrador sale con la OC del correo y abre el editor.
        $this->post(route('facturacion.pedidos.facturar', $delCorreo), ['numero_orden_compra' => '26119002000555', 'directo' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('facturacion.edit', $dte = Dte::sole()));
        $this->assertSame('26119002000555', $dte->numero_orden_compra);
        $this->assertSame($salaCorreo->id, $dte->cliente_sucursal_id);
        $this->assertSame([$this->quesadilla->id => 2.0], $this->cantidades($dte));
    }

    public function test_si_algo_frena_el_directo_abre_el_formulario_con_lo_que_falta(): void
    {
        $sinPrecio = Producto::factory()->create(['nombre' => 'Turrón de prueba', 'precio_unitario' => 0]);
        $pedido = $this->pedido([$sinPrecio->id => '1'], '26119001000317');
        $this->envio($pedido, [$sinPrecio->id => '1']);
        $formulario = route('facturacion.create-ccf', ['pedido' => $pedido->id]);

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '26119001000317', 'directo' => '1'])
            ->assertRedirect($formulario)
            ->assertSessionHasErrors('pedido');
        $this->assertSame(0, Dte::withTrashed()->count());
        $this->get($formulario)->assertOk()->assertSee('Turrón de prueba no tiene precio para esta sala.');

        // Lo que no pasa la validación del formulario (la OC que pide la sala) también vuelve ahí.
        $this->sala->update(['requiere_orden_compra' => true]);
        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '', 'directo' => '1'])
            ->assertRedirect($formulario)
            ->assertSessionHasErrors('numero_orden_compra');
        $this->assertSame(0, Dte::withTrashed()->count());
    }

    public function test_si_hay_que_elegir_el_emisor_facturar_abre_el_formulario(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '4'], '26119001000317');
        $this->envio($pedido, [$this->galleta->id => '4']);
        PuntoVenta::create(['establecimiento_id' => Establecimiento::sole()->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);

        $html = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->getContent();

        $this->assertNull($this->directo($html, $pedido));
        $this->assertStringContainsString('href="'.e(route('facturacion.create-ccf', ['pedido' => $pedido->id])).'"', $html);
    }

    /** En el formulario, la única OC del correo de la sala viene puesta, no como un botón que hay que tocar. */
    public function test_el_formulario_trae_puesta_la_unica_oc_del_correo(): void
    {
        $sala = $this->salaQueExigeOc('Sala de prueba Correo', '9002');
        $this->ocDelCorreo($sala, '26119002000555');
        $pedido = $this->pedido([$this->quesadilla->id => '2'], sala: $sala);
        $this->envio($pedido, [$this->quesadilla->id => '2']);

        $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertViewHas('propuesta', fn ($p) => $p['oc'] === '26119002000555')
            ->assertSee('value="26119002000555"', false);

        // Con dos, se elige: ninguna viene puesta.
        $this->ocDelCorreo($sala, '26119002000666');
        $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertViewHas('propuesta', fn ($p) => $p['oc'] === null);
    }

    /** El «Facturar» de la ficha del pedido sigue la misma regla que el de la lista. */
    public function test_el_facturar_de_la_ficha_del_pedido_tambien_va_directo(): void
    {
        $conOc = $this->pedido([$this->galleta->id => '4'], '26119001000317');
        $this->envio($conOc, [$this->galleta->id => '4']);
        $sinOc = $this->pedido([$this->quesadilla->id => '1'], sala: $this->salaQueExigeOc('Sala de prueba Sin OC', '9003'));
        $this->envio($sinOc, [$this->quesadilla->id => '1']);

        $html = $this->get(route('rutas.pedidos.show', $conOc))->assertOk()->getContent();
        $this->assertSame('26119001000317', $this->directo($html, $conOc));
        $this->assertStringContainsString('name="desde" value="pedido"', $html);
        $this->assertStringNotContainsString(e(route('facturacion.create-ccf', ['pedido' => $conOc->id, 'desde' => 'pedido'])), $html);

        $html = $this->get(route('rutas.pedidos.show', $sinOc))->assertOk()->getContent();
        $this->assertNull($this->directo($html, $sinOc));
        $this->assertStringContainsString('href="'.e(route('facturacion.create-ccf', ['pedido' => $sinOc->id, 'desde' => 'pedido'])).'"', $html);

        // Si algo frena, el formulario abre con «Cancelar» a la ficha.
        $this->post(route('facturacion.pedidos.facturar', $sinOc), ['numero_orden_compra' => '', 'directo' => '1', 'desde' => 'pedido'])
            ->assertRedirect(route('facturacion.create-ccf', ['pedido' => $sinOc->id, 'desde' => 'pedido']))
            ->assertSessionHasErrors('numero_orden_compra');
    }

    /** En el celular el formulario no queda pegado a los bordes: el contenedor lleva su margen. */
    public function test_el_formulario_del_ccf_tiene_margen_en_el_celular(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '4'], '26119001000317');
        $this->envio($pedido, [$this->galleta->id => '4']);

        foreach ([route('facturacion.create-ccf', ['pedido' => $pedido->id]), route('facturacion.create-ccf')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8"', false)
                ->assertSee('class="bg-white shadow rounded-lg p-4 sm:p-6"', false);
        }
    }

    public function test_la_ficha_del_ccf_dice_de_que_pedido_viene(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->envio($pedido, [$this->galleta->id => '4']);
        $this->post(route('facturacion.pedidos.facturar', $pedido))->assertSessionHasNoErrors();

        $this->get(route('facturacion.show', Dte::sole()))->assertOk()
            ->assertSee('Viene del pedido N.º '.$pedido->id.' (envíos 1 y 2)')
            ->assertSee(route('rutas.pedidos.show', $pedido), false);
    }
}
