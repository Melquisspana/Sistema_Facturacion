<?php

namespace Tests\Feature\Rutas;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\OrigenPedidoSala;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosOficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El probador de «Clientes» de Rutas (28/11/2026): el panel recorrido como lo usan la oficina,
 * el jefe de vendedores y el vendedor, de punta a punta, tratando de romperlo. Lo que ya prueba
 * ClientesRutasTest no se repite. Cada prueba que falla dice en una línea qué se esperaba.
 * Datos ficticios.
 */
class ClientesRutasProbadorTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $norte;

    private Ruta $sur;

    private Cliente $cliente;

    private ClienteSucursal $salaNorte;

    private ClienteSucursal $salaSur;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-28 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-06-01', 'rutas.ambiente_ccf' => null, 'planta.enabled' => false]);

        $this->jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $this->vendedor->id]);
        $this->persona->funciones()->create(['funcion' => 'vendedor']);
        $this->norte = Ruta::create(['nombre' => 'Zona de prueba Norte']);
        $this->sur = Ruta::create(['nombre' => 'Zona de prueba Sur']);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba', 'requiere_orden_compra' => false]);
        $this->salaNorte = $this->sala('Sala de prueba Norte', '9001', $this->norte);
        $this->salaSur = $this->sala('Sala de prueba Sur', '9002', $this->sur);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 3.33]);
    }

    // ══════════════════════════════ armado de datos (como ClientesRutasTest)

    /** @param  array<string, mixed>  $extra */
    private function sala(string $nombre, string $codigo, ?Ruta $zona, array $extra = []): ClienteSucursal
    {
        return $this->cliente->sucursales()->create($extra + ['nombre' => $nombre, 'codigo' => $codigo, 'ruta_id' => $zona?->id]);
    }

    /** @param  array<int, array{0: Producto, 1: string}>  $lineas */
    private function pedido(ClienteSucursal $sala, string $fecha, array $lineas = [], EstadoPedidoSala $estado = EstadoPedidoSala::Registrado): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $sala->ruta_id, 'sacado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id, 'fecha' => $fecha, 'estado' => $estado,
        ]);
        foreach ($lineas as [$producto, $cantidad]) {
            $pedido->lineas()->create(['producto_id' => $producto->id, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    /** @param  array<string, mixed>  $extra */
    private function ccf(ClienteSucursal $sala, string $fecha, string $total = '123.45', array $extra = []): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::withTrashed()->count();

        return Dte::create($extra + [
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha, 'hora_emision' => '07:00:00', 'subtotal' => $total, 'total_pagar' => $total,
        ]);
    }

    /** Una entrega ya anotada; con `$quedan`, la tienda dejó quedan y no pagó al entregar. */
    private function entregado(Dte $dte, string $cuando, ?string $quedan = null): SalidaRutaEntrega
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->norte->id, 'fecha_inicio' => substr($cuando, 0, 10), 'estado' => EstadoSalidaRuta::Finalizada]);

        return $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => $cuando, 'entregado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id, 'quedan_numero' => $quedan, 'quedan_fecha_pago' => $quedan ? '2026-12-10' : null]);
    }

    private function porCobrar(Dte $dte, string $tipo = '03'): CobroDocumento
    {
        return CobroDocumento::create(['cliente_id' => $dte->cliente_id, 'dte_id' => $dte->id, 'tipo_dte' => $tipo,
            'numero_control' => $dte->numero_control, 'fecha_emision' => $dte->fecha_emision->toDateString(), 'monto' => $dte->total_pagar,
            'pago_estado' => EstadoPagoCobro::Pendiente]);
    }

    /** Lo que hace Cobros al recibir el pago: un evento y el estado recalculado desde los eventos. */
    private function pagar(CobroDocumento $cobro, string $monto): void
    {
        CobroEvento::create(['cobro_documento_id' => $cobro->id, 'tipo' => 'pago', 'origen' => 'manual',
            'monto' => $monto, 'fecha' => '2026-11-28', 'detalle' => 'Pago de prueba.']);
        $cobro->refresh()->recalcularPago();
    }

    private function corto(Dte $dte): string
    {
        return 'CCF …'.substr($dte->numero_control, -4);
    }

    private function contenido(string $html): string
    {
        $inicio = strpos($html, '<main');
        $this->assertNotFalse($inicio, 'La página no tiene <main>.');

        return substr($html, $inicio, (int) strpos($html, '</main>') - $inicio);
    }

    /** @return list<string> las tarjetas de la lista, una por sala */
    private function tarjetas(string $html): array
    {
        return array_slice(explode('<li class="flex flex-col rounded-lg', $this->contenido($html)), 1);
    }

    /** La tarjeta de una sala en la lista: tiene que haber exactamente una. */
    private function tarjeta(ClienteSucursal $sala, array $query = []): string
    {
        $html = $this->actingAs($this->jefe)->get(route('rutas.clientes.index', $query))->assertOk()->getContent();
        $suyas = array_values(array_filter($this->tarjetas($html), fn (string $t) => str_contains($t, '>'.e($sala->nombre).'</a>')));
        $this->assertCount(1, $suyas, 'Una sola tarjeta por sala: '.$sala->nombre);

        return $suyas[0];
    }

    private function historia(ClienteSucursal $sala): string
    {
        return $this->contenido($this->actingAs($this->jefe)->get(route('rutas.clientes.show', $sala))->assertOk()->getContent());
    }

    private function version(PedidoSala $pedido): string
    {
        return PedidosOficina::version(PedidoSala::with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id));
    }

    // ══════════════════════════════ el vendedor entrega, deshace o no puede

    public function test_entregar_deshacer_y_no_se_pudo_se_ven_al_momento_en_el_panel(): void
    {
        $dte = $this->ccf($this->salaNorte, '2026-11-28', '55.55');
        $this->assertStringContainsString('Por entregar', $this->tarjeta($this->salaNorte));

        // Lo entrega desde «Por entregar» (cobra en efectivo): el jefe lo ve entregado hoy.
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), [
            'resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->persona->id,
        ])->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::where('dte_id', $dte->id)->sole();
        $tarjeta = $this->tarjeta($this->salaNorte);
        $this->assertStringContainsString('Entregado el 28/11/2026', $tarjeta);
        $this->assertStringNotContainsString('Por entregar', $tarjeta);
        $historia = $this->historia($this->salaNorte);
        $this->assertStringContainsString('Entregado el 28/11/2026', $historia);
        // Lo cobrado al entregar tampoco es un monto que se muestre.
        $this->assertStringNotContainsString('55.55', $historia);

        // Se equivocó y lo deshace: vuelve a «Por entregar».
        $this->actingAs($this->vendedor)->patch(route('rutas.mi-salida.deshacer', [$entrega->salida_ruta_id, $entrega->id]))->assertSessionHasNoErrors();
        $tarjeta = $this->tarjeta($this->salaNorte);
        $this->assertStringContainsString('Por entregar', $tarjeta);
        $this->assertStringNotContainsString('Entregado', $tarjeta);

        // «No se pudo» (sala cerrada): no cuenta como entregado.
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), [
            'resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada', 'entregado_por_id' => $this->persona->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(ResultadoEntrega::NoEntregado, SalidaRutaEntrega::where('dte_id', $dte->id)->sole()->resultado);
        $this->assertStringContainsString('Por entregar', $this->tarjeta($this->salaNorte));
        $historia = $this->historia($this->salaNorte);
        $this->assertStringContainsString('Por entregar', $historia);
        $this->assertStringNotContainsString('Entregado', $historia);
    }

    /** Lo mismo cuando la entrega cae en el viaje del vendedor (no en la salida automática). */
    public function test_entregar_y_deshacer_dentro_de_un_viaje(): void
    {
        $dte = $this->ccf($this->salaSur, '2026-11-28');
        // El primer pedido del día abre el viaje del vendedor.
        $this->actingAs($this->vendedor)->post(route('rutas.sacar-pedido.store', $this->salaSur), ['cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasNoErrors();
        $viaje = PedidoSala::sole()->salida;
        $this->assertTrue($viaje->esViaje());

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), [
            'resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->persona->id,
        ])->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::where('dte_id', $dte->id)->sole();
        $this->assertSame($viaje->id, $entrega->salida_ruta_id);
        $tarjeta = $this->tarjeta($this->salaSur);
        // Lo que se le sacó hoy y su CCF entregado hoy.
        $this->assertMatchesRegularExpression('/28\/11\/2026 · Vendedor de prueba.*Galleta de prueba.*12 cajas/su', $tarjeta);
        $this->assertStringContainsString('Entregado el 28/11/2026', $tarjeta);

        $this->actingAs($this->vendedor)->patch(route('rutas.mi-salida.deshacer', [$viaje, $entrega]))->assertSessionHasNoErrors();
        $this->assertFalse(SalidaRutaEntrega::where('dte_id', $dte->id)->entregadas()->exists());
        $tarjeta = $this->tarjeta($this->salaSur);
        $this->assertStringContainsString('Por entregar', $tarjeta);
        $this->assertStringNotContainsString('Entregado', $tarjeta);
    }

    // ══════════════════════════════ por cobrar

    public function test_el_quedan_cuenta_una_vez_y_deja_de_contar_cuando_cobros_lo_marca_pagado(): void
    {
        $dte = $this->ccf($this->salaNorte, '2026-11-27', '150.00');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), [
            'resultado' => 'entregado', 'forma_cobro' => 'sin_cobro', 'quedan_numero' => 'Q-900', 'quedan_fecha_pago' => '2026-12-05',
            'entregado_por_id' => $this->persona->id,
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('1 CCF por cobrar', $this->tarjeta($this->salaNorte));
        $historia = $this->historia($this->salaNorte);
        $this->assertStringContainsString('1 CCF por cobrar', $historia);
        $this->assertSame(1, substr_count($historia, '>Por cobrar</span>'));

        // Cobros lo trae a «Facturas por cobrar»: sigue siendo una sola factura, no dos.
        $cobro = $this->porCobrar($dte);
        $this->assertStringContainsString('1 CCF por cobrar', $this->tarjeta($this->salaNorte));

        // Un pago de menos: sigue por cobrar, y lo pagado no se muestra.
        $this->pagar($cobro, '11.11');
        $this->assertSame(EstadoPagoCobro::Parcial, $cobro->refresh()->pago_estado);
        $tarjeta = $this->tarjeta($this->salaNorte);
        $this->assertStringContainsString('1 CCF por cobrar', $tarjeta);
        foreach (['150.00', '11.11', '$'] as $monto) {
            $this->assertStringNotContainsString($monto, $tarjeta, $monto);
            $this->assertStringNotContainsString($monto, $this->historia($this->salaNorte), $monto);
        }

        // Cobros lo marca pagado: ya no hay nada por cobrar.
        $this->pagar($cobro, '138.89');
        $this->assertSame(EstadoPagoCobro::Pagado, $cobro->refresh()->pago_estado);
        $this->assertStringNotContainsString('por cobrar', $this->tarjeta($this->salaNorte));
        $historia = $this->historia($this->salaNorte);
        $this->assertStringContainsString('Nada por cobrar', $historia);
        $this->assertStringNotContainsString('>Por cobrar</span>', $historia);
    }

    public function test_un_ccf_invalidado_despues_de_entregado_con_quedan_no_queda_por_cobrar(): void
    {
        $bueno = $this->ccf($this->salaNorte, '2026-11-20');
        $this->entregado($bueno, '2026-11-21 10:00:00');
        $malo = $this->ccf($this->salaNorte, '2026-11-26');
        $this->entregado($malo, '2026-11-27 10:00:00', 'Q-777');
        $this->porCobrar($malo);
        // Facturación lo invalida después (se simula lo que deja Hacienda en la base).
        DB::table('dtes')->where('id', $malo->id)->update(['estado' => 'invalidado']);

        $tarjeta = $this->tarjeta($this->salaNorte);
        // El último CCF que cuenta es el bueno.
        $this->assertStringContainsString($this->corto($bueno), $tarjeta);
        $this->assertStringNotContainsString($this->corto($malo), $tarjeta);
        // Esperaba: un CCF invalidado no se cobra, aunque el vendedor haya anotado quedan (antes del arreglo salía «1 CCF por cobrar»).
        $this->assertStringNotContainsString('por cobrar', $tarjeta);
        // Esperaba: la historia dice «Nada por cobrar» y ningún CCF de la lista tiene «Por cobrar» (antes decía 1 sin mostrar cuál).
        $this->assertStringContainsString('Nada por cobrar', $this->historia($this->salaNorte));
    }

    public function test_un_ccf_que_su_nc_salda_no_queda_por_cobrar_aunque_tenga_quedan(): void
    {
        $saldado = $this->ccf($this->salaNorte, '2026-11-20', '123.45');
        $this->entregado($saldado, '2026-11-21 10:00:00', 'Q-555');
        $this->porCobrar($saldado);
        $nota = $this->ccf($this->salaNorte, '2026-11-22', '123.45', ['tipo_dte' => '05', 'dte_relacionado_id' => $saldado->id]);
        $this->porCobrar($nota, '05');

        // Esperaba: la NC deja el CCF en saldo 0 y no hay nada que cobrar, con o sin quedan (antes el quedan lo seguía contando).
        $this->assertStringNotContainsString('por cobrar', $this->tarjeta($this->salaNorte));
        $this->assertStringContainsString('Nada por cobrar', $this->historia($this->salaNorte));
    }

    // ══════════════════════════════ la oficina anula o corrige

    public function test_anular_o_mover_el_ultimo_pedido_deja_el_anterior_vigente(): void
    {
        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->pedido($this->salaNorte, '2026-11-20', [[$this->galleta, '4']]);
        $ultimo = $this->pedido($this->salaNorte, '2026-11-27', [[$this->canilla, '9']]);
        $this->assertMatchesRegularExpression('/27\/11\/2026.*Canilla de prueba.*9 cajas/su', $this->tarjeta($this->salaNorte));

        // La oficina lo anula: lo último que se le sacó vuelve a ser el del 20.
        $this->actingAs($oficina)->patch(route('rutas.pedidos.anular.guardar', $ultimo), [
            'version' => $this->version($ultimo), 'motivo' => 'La sala no pidió nada.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Anulado, $ultimo->refresh()->estado);
        $tarjeta = $this->tarjeta($this->salaNorte);
        $this->assertMatchesRegularExpression('/20\/11\/2026.*Galleta de prueba.*4 cajas/su', $tarjeta);
        $this->assertStringNotContainsString('9 cajas', $tarjeta);
        $this->assertStringNotContainsString('27/11/2026', $this->historia($this->salaNorte));

        // Hoy se cargó en la sala equivocada y la oficina lo pasa a la otra.
        $equivocado = $this->pedido($this->salaNorte, '2026-11-28', [[$this->galleta, '7']]);
        $this->assertStringContainsString('7 cajas', $this->tarjeta($this->salaNorte));
        $this->actingAs($oficina)->put(route('rutas.pedidos.corregir.guardar', $equivocado), [
            'version' => $this->version($equivocado), 'motivo' => 'Era de la otra sala.', 'cliente_sucursal_id' => $this->salaSur->id,
            'cantidades' => [$equivocado->lineas()->value('id') => '7'],
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->salaSur->id, $equivocado->refresh()->cliente_sucursal_id);

        $norte = $this->tarjeta($this->salaNorte);
        $this->assertMatchesRegularExpression('/20\/11\/2026.*4 cajas/su', $norte);
        $this->assertStringNotContainsString('7 cajas', $norte);
        $this->assertMatchesRegularExpression('/28\/11\/2026.*Galleta de prueba.*7 cajas/su', $this->tarjeta($this->salaSur));
        $this->assertStringNotContainsString('28/11/2026', $this->historia($this->salaNorte));
        $this->assertStringContainsString('28/11/2026', $this->historia($this->salaSur));
    }

    // ══════════════════════════════ el mismo día, tiendas nuevas y pedidos web

    public function test_dos_pedidos_y_dos_ccf_el_mismo_dia_sale_el_ultimo_cargado_en_una_sola_tarjeta(): void
    {
        $this->pedido($this->salaNorte, '2026-11-26', [[$this->galleta, '3']]);
        $this->pedido($this->salaNorte, '2026-11-26', [[$this->galleta, '5']]);
        // Cargado después, pero de un día anterior: no es el último.
        $this->pedido($this->salaNorte, '2026-11-25', [[$this->galleta, '8']]);
        $primero = $this->ccf($this->salaNorte, '2026-11-26');
        $segundo = $this->ccf($this->salaNorte, '2026-11-26');
        $anterior = $this->ccf($this->salaNorte, '2026-11-24');
        $this->entregado($primero, '2026-11-27 08:00:00');

        $tarjeta = $this->tarjeta($this->salaNorte);
        $this->assertStringContainsString('5 cajas', $tarjeta);
        $this->assertStringNotContainsString('3 cajas', $tarjeta);
        $this->assertStringNotContainsString('8 cajas', $tarjeta);
        $this->assertStringContainsString($this->corto($segundo), $tarjeta);
        $this->assertStringNotContainsString($this->corto($primero), $tarjeta);
        $this->assertStringContainsString('Por entregar', $tarjeta);
        // Ninguna sala se repite en la lista.
        $html = $this->actingAs($this->jefe)->get(route('rutas.clientes.index'))->assertOk()->getContent();
        $this->assertCount(2, $this->tarjetas($html));

        // La historia, del más nuevo al más viejo con el mismo orden.
        $this->actingAs($this->jefe)->get(route('rutas.clientes.show', $this->salaNorte))->assertOk()
            ->assertSeeInOrder(['5 cajas', '3 cajas', '8 cajas'])
            ->assertSeeInOrder([$this->corto($segundo), $this->corto($primero), $this->corto($anterior)]);
    }

    public function test_la_tienda_nueva_y_el_pedido_web_no_caen_en_ninguna_sala(): void
    {
        $nueva = PedidoSala::create(['cliente_sucursal_id' => null, 'sala_nueva_nombre' => 'Tienda nueva de prueba', 'ruta_id' => $this->norte->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id, 'fecha' => '2026-11-28', 'estado' => EstadoPedidoSala::Registrado]);
        $nueva->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => '6']);
        $web = PedidoSala::create(['origen' => OrigenPedidoSala::Web, 'fecha' => '2026-11-28', 'estado' => EstadoPedidoSala::Registrado]);
        $web->lineas()->create(['producto_id' => $this->canilla->id, 'cantidad' => '2']);

        $html = $this->contenido($this->actingAs($this->jefe)->get(route('rutas.clientes.index'))->assertOk()->getContent());
        $this->assertStringNotContainsString('Tienda nueva de prueba', $html);
        $this->assertStringNotContainsString('Pedido web', $html);
        $this->assertSame(2, substr_count($html, 'Todavía no tiene pedidos'));
        // «Todos los pedidos» los sigue mostrando.
        $this->get(route('rutas.pedidos.index'))->assertOk()->assertSee('Tienda nueva de prueba');
    }

    // ══════════════════════════════ salas desactivadas, borradas y por cobertura

    public function test_la_sala_desactivada_sale_de_la_lista_pero_su_historia_abre_y_la_borrada_da_404(): void
    {
        $this->pedido($this->salaSur, '2026-11-25', [[$this->galleta, '2']]);
        $delSur = $this->ccf($this->salaSur, '2026-11-25');
        $delNorte = $this->ccf($this->salaNorte, '2026-11-25');
        $this->salaSur->update(['activo' => false]);

        $this->actingAs($this->jefe)->get(route('rutas.clientes.index'))->assertOk()->assertDontSee('Sala de prueba Sur');
        $historia = $this->historia($this->salaSur);
        $this->assertMatchesRegularExpression('/25\/11\/2026.*2 cajas/su', $historia);
        $this->assertStringContainsString($this->corto($delSur), $historia);
        $this->get(route('rutas.clientes.ccf', [$this->salaSur, $delSur]))->assertOk();

        $this->salaNorte->delete();
        $this->assertSoftDeleted($this->salaNorte);
        $this->get(route('rutas.clientes.show', $this->salaNorte->id))->assertNotFound();
        $this->get(route('rutas.clientes.ccf', [$this->salaNorte->id, $delNorte->id]))->assertNotFound();
        $this->get(route('rutas.clientes.index'))->assertOk()->assertDontSee('Sala de prueba Norte');
    }

    public function test_la_zona_por_cobertura_encuentra_la_sala_sin_ruta_propia(): void
    {
        $depA = Departamento::firstOrCreate(['codigo' => '09'], ['nombre' => 'Departamento de prueba A']);
        $depB = Departamento::firstOrCreate(['codigo' => '07'], ['nombre' => 'Departamento de prueba B']);
        $distrito = Distrito::create(['departamento_id' => $depA->id, 'municipio' => 'Municipio de prueba', 'nombre' => 'Distrito de prueba']);
        RutaCobertura::create(['ruta_id' => $this->norte->id, 'distrito_id' => $distrito->id]);
        RutaCobertura::create(['ruta_id' => $this->sur->id, 'departamento_id' => $depB->id]);
        $cerrada = Ruta::create(['nombre' => 'Zona de prueba Cerrada', 'activa' => false]);

        $porDistrito = $this->sala('Sala de prueba Distrito', '9101', null, ['distrito_id' => $distrito->id, 'departamento_id' => $depA->id]);
        $porDepartamento = $this->sala('Sala de prueba Departamento', '9102', null, ['departamento_id' => $depB->id]);
        // Su ruta está desactivada: cae en la que cubre su distrito.
        $enCerrada = $this->sala('Sala de prueba Cerrada', '9103', $cerrada, ['distrito_id' => $distrito->id, 'departamento_id' => $depA->id]);
        $sinNada = $this->sala('Sala de prueba Suelta', '9104', null);

        $this->actingAs($this->jefe)->get(route('rutas.clientes.index', ['zona' => $this->norte->id]))->assertOk()
            ->assertSee('Sala de prueba Norte')->assertSee('Sala de prueba Distrito')->assertSee('Sala de prueba Cerrada')
            ->assertDontSee('Sala de prueba Sur')->assertDontSee('Sala de prueba Departamento')->assertDontSee('Sala de prueba Suelta');
        $this->get(route('rutas.clientes.index', ['zona' => $this->sur->id]))->assertOk()
            ->assertSee('Sala de prueba Sur')->assertSee('Sala de prueba Departamento')
            ->assertDontSee('Sala de prueba Distrito')->assertDontSee('Sala de prueba Suelta');
        // La zona apagada no se ofrece ni filtra.
        $this->get(route('rutas.clientes.index'))->assertOk()->assertDontSee('<option value="'.$cerrada->id.'"', false);

        $this->assertStringContainsString('Zona de prueba Norte', $this->tarjeta($porDistrito));
        $this->assertStringContainsString('Zona de prueba Norte', $this->tarjeta($enCerrada));
        $this->assertStringContainsString('Zona de prueba Sur', $this->tarjeta($porDepartamento));
        $this->assertStringContainsString('Sin zona', $this->tarjeta($sinNada));
        $this->assertStringContainsString('Zona de prueba Norte', $this->historia($porDistrito));
    }

    // ══════════════════════════════ paginación

    public function test_mas_de_25_salas_se_reparten_en_paginas_y_una_pagina_de_mas_no_rompe(): void
    {
        foreach (range(1, 26) as $i) {
            $this->sala(sprintf('Sala de prueba P%02d', $i), (string) (9200 + $i), $this->norte);
        }
        // 28 salas: Norte, P01…P26 y Sur, por nombre.
        $this->actingAs($this->jefe);
        $uno = $this->get(route('rutas.clientes.index'))->assertOk()->getContent();
        $this->assertCount(25, $this->tarjetas($uno));
        $this->assertStringContainsString('Sala de prueba P24', $uno);
        $this->assertStringNotContainsString('Sala de prueba P25', $uno);

        $dos = $this->get(route('rutas.clientes.index', ['page' => 2]))->assertOk()->getContent();
        $this->assertCount(3, $this->tarjetas($dos));
        $this->assertStringContainsString('Sala de prueba Sur', $dos);

        // Con filtro, el enlace a la página 2 lo conserva.
        $filtrada = $this->get(route('rutas.clientes.index', ['q' => 'prueba p']))->assertOk()->getContent();
        $this->assertCount(25, $this->tarjetas($filtrada));
        $this->assertMatchesRegularExpression('/href="[^"]*q=prueba(\+|%20)p[^"]*page=2"/u', $filtrada);
        $this->assertCount(1, $this->tarjetas($this->get(route('rutas.clientes.index', ['q' => 'prueba p', 'page' => 2]))->assertOk()->getContent()));

        // Una página que no existe lo dice y ofrece volver; una armada a mano no da 500.
        $this->get(route('rutas.clientes.index', ['page' => 99]))->assertOk()->assertSee('Esa página no tiene salas')->assertSee('Ir a la primera');
        $this->assertCount(25, $this->tarjetas($this->get('/rutas/clientes?page=abc')->assertOk()->getContent()));
        $this->get('/rutas/clientes?page[]=2')->assertOk();
        $this->get('/rutas/clientes?page=-3')->assertOk();
        // Esperaba: un número de página gigante armado a mano no da 500 (como «Ver más» con 99999).
        $this->get('/rutas/clientes?page=9223372036854775807')->assertOk();
    }

    // ══════════════════════════════ qué CCF es «el último»

    public function test_el_ultimo_ccf_es_el_ultimo_que_cuenta_en_rutas(): void
    {
        $bueno = $this->ccf($this->salaNorte, '2026-11-20');
        $otroAmbiente = $this->ccf($this->salaNorte, '2026-11-27', extra: ['ambiente' => '00']);
        $archivado = $this->ccf($this->salaNorte, '2026-11-26', extra: ['archivado' => true]);
        $rechazado = $this->ccf($this->salaNorte, '2026-11-25', extra: ['estado' => 'rechazado', 'sello_recepcion' => null]);
        $factura = $this->ccf($this->salaNorte, '2026-11-24', extra: ['tipo_dte' => '01']);

        $tarjeta = $this->tarjeta($this->salaNorte);
        $this->assertMatchesRegularExpression('/'.preg_quote($this->corto($bueno), '/').'.*20\/11\/2026/su', $tarjeta);
        $historia = $this->historia($this->salaNorte);
        foreach ([$otroAmbiente, $archivado, $rechazado, $factura] as $otro) {
            $this->assertStringNotContainsString($this->corto($otro), $tarjeta);
            $this->assertStringNotContainsString($this->corto($otro), $historia);
        }
    }

    /** El borde de los 45 días: el mismo día que «Por entregar» deja de listarlo, el panel deja de decir «Por entregar». */
    public function test_el_borde_de_los_45_dias_coincide_con_por_entregar(): void
    {
        $limite = $this->ccf($this->salaNorte, '2026-10-14');
        $fuera = $this->ccf($this->salaSur, '2026-10-13');
        $lista = app(EntregasCcf::class)->porEntregar()->pluck('id')->all();
        $this->assertContains($limite->id, $lista);
        $this->assertNotContains($fuera->id, $lista);

        $this->assertStringContainsString('Por entregar', $this->tarjeta($this->salaNorte));
        $tarjeta = $this->tarjeta($this->salaSur);
        $this->assertStringContainsString('Sin entrega anotada', $tarjeta);
        $this->assertStringNotContainsString('Por entregar', $tarjeta);
    }

    // ══════════════════════════════ permisos

    public function test_solo_con_pedidos_ver_o_con_roles_sin_rutas_no_se_abre(): void
    {
        $dte = $this->ccf($this->salaNorte, '2026-11-20');
        $urls = [
            route('rutas.clientes.index'),
            route('rutas.clientes.show', $this->salaNorte),
            route('rutas.clientes.ccf', [$this->salaNorte, $dte]),
        ];

        // `rutas.pedidos.ver` sin `rutas.ver`: no entra a Rutas.
        $soloPedidos = User::factory()->create(['activo' => true])->givePermissionTo('rutas.pedidos.ver');
        $usuarios = [$soloPedidos];
        foreach (['fabrica', 'produccion', 'contabilidad'] as $rol) {
            $usuarios[] = User::factory()->create(['activo' => true])->assignRole($rol);
        }
        foreach ($usuarios as $usuario) {
            foreach ($urls as $url) {
                $this->actingAs($usuario)->get($url)->assertForbidden();
            }
        }
        // Un usuario desactivado con el rol correcto tampoco.
        $inactivo = User::factory()->create(['activo' => false])->assignRole('jefe_vendedores');
        $this->actingAs($inactivo)->get(route('rutas.clientes.index'))->assertStatus(302);
    }

    // ══════════════════════════════ nombres raros

    public function test_un_nombre_con_comillas_y_etiquetas_no_rompe_la_pagina_ni_el_boton_de_compartir(): void
    {
        $rara = $this->sala('Sala de prueba "Comillas" & <b>negrita</b> O\'Neil', '9300', $this->norte);
        $this->ccf($rara, '2026-11-27');
        $this->pedido($rara, '2026-11-27', [[$this->galleta, '1']]);

        $this->actingAs($this->jefe);
        $lista = $this->get(route('rutas.clientes.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<b>negrita</b>', $lista);
        $this->assertStringContainsString('&lt;b&gt;negrita&lt;/b&gt;', $lista);
        $this->assertMatchesRegularExpression('/Galleta de prueba.*1 caja\b/su', $this->tarjeta($rara));

        $historia = $this->get(route('rutas.clientes.show', $rara))->assertOk()->getContent();
        $this->assertStringNotContainsString('<b>negrita</b>', $historia);
        // El x-data del botón «Compartir» sigue siendo un solo atributo bien cerrado.
        $this->assertSame(1, preg_match_all('/x-data="compartirPdf\([^"]*\)"/u', $historia));

        // Se encuentra buscando con o sin la comilla.
        $this->get(route('rutas.clientes.index', ['q' => "o'neil"]))->assertOk()->assertSee('O&#039;Neil', false)->assertDontSee('Sala de prueba Sur');
        $this->get(route('rutas.clientes.index', ['q' => 'COMILLAS']))->assertOk()->assertDontSee('Sala de prueba Sur');
    }
}
