<?php

namespace Tests\Feature\Facturacion;

use App\Enums\EstadoPedidoSala;
use App\Enums\TipoDte;
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
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Facturación › «Llegó de la fábrica» (etapa 2, PR 1): una tarjeta por sala con lo que
 * mandó la fábrica ese día, la insignia de la OC y el botón para imprimir la hoja. Solo
 * lectura: todavía no factura. La fábrica no ve nada de esto. Datos inventados.
 */
class LlegoDeFabricaTest extends TestCase
{
    use RefreshDatabase;

    private User $oficina;

    private User $fabrica;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');

        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta de prueba']);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01']);

        $this->actingAs($this->oficina);
    }

    private function sala(string $nombre, string $codigo, bool $exigeOc = false): ClienteSucursal
    {
        return Cliente::factory()->create(['nombre' => 'Cliente de prueba '.$codigo, 'requiere_orden_compra' => $exigeOc])
            ->sucursales()->create(['nombre' => $nombre, 'codigo' => $codigo, 'ruta_id' => $this->ruta->id]);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(ClienteSucursal $sala, array $cantidades, ?string $oc = null, EstadoPedidoSala $estado = EstadoPedidoSala::Enviado): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->oficina->id,
            'fecha' => '2026-11-08', 'estado' => $estado, 'numero_orden_compra' => $oc,
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

    private function ocDelCorreo(ClienteSucursal $sala, string $numero): void
    {
        OrdenCompraRecibida::create([
            'numero' => $numero, 'sala_codigo' => $sala->codigo, 'cliente_sucursal_id' => $sala->id,
            'fecha_creacion' => '2026-11-08', 'fecha_despacho' => '2026-11-10', 'total' => '22.90',
            'pdf_path' => 'ppq/ordenes-compra/x.pdf', 'pdf_hash' => hash('sha256', $numero),
            'gmail_message_id' => 'm-'.$numero, 'estado' => OrdenCompraRecibida::LEIDA,
        ]);
    }

    public function test_con_un_dia_muestra_una_fila_por_sala_con_lo_que_llego_y_su_hoja(): void
    {
        $norte = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10', $this->canilla->id => '4']);
        $norte->lineas()->where('producto_id', $this->galleta->id)->update(['motivo_no_enviado' => 'No hubo masa']);
        $this->envio($norte, [$this->galleta->id => '6']);
        $this->envio($norte, [$this->canilla->id => '4']);
        $ayer = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '3']);
        $this->envio($ayer, [$this->galleta->id => '3'], '2026-11-08');
        $anulado = $this->pedido($this->sala('Sala de prueba Oeste', '9003'), [$this->galleta->id => '2']);
        $this->envio($anulado, [$this->galleta->id => '2'], '2026-11-09', anulado: true);

        $respuesta = $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk();

        $respuesta->assertSee('Llegó de pedidos')
            ->assertSee('Sala de prueba Norte')
            ->assertSee('9001')
            ->assertSee('Ruta de prueba')
            ->assertSee('Vendedor de prueba')
            ->assertSee(route('rutas.pedidos.hoja', $norte), false)
            ->assertSeeInOrder(['Galleta de prueba', 'No hubo masa', '10', '6', 'F', 'Canilla de prueba', '4', '4'])
            ->assertDontSee('Sala de prueba Sur')
            ->assertDontSee('Sala de prueba Oeste')
            ->assertDontSee('7.77');
        // Una sola fila para la sala aunque llegó en dos envíos.
        $this->assertSame(1, substr_count($respuesta->getContent(), route('rutas.pedidos.hoja', $norte)));
        // La F solo en lo que faltó: 4 galletas.
        $this->assertSame(1, substr_count($respuesta->getContent(), 'aria-label="Faltó"'));
    }

    public function test_filtra_por_el_dia_del_envio(): void
    {
        $hoy = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '1']);
        $this->envio($hoy, [$this->galleta->id => '1']);
        $ayer = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '3']);
        $this->envio($ayer, [$this->galleta->id => '3'], '2026-11-08');

        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-08']))->assertOk()
            ->assertSee('Sala de prueba Sur')
            ->assertDontSee('Sala de prueba Norte');

        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '9/11/2026']))
            ->assertSessionHasErrors('fecha');
    }

    public function test_el_envio_anulado_no_cuenta_aunque_el_pedido_tenga_otro_de_otro_dia(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '5']);
        $this->envio($pedido, [$this->galleta->id => '5'], '2026-11-09', anulado: true);
        $this->envio($pedido, [$this->galleta->id => '5'], '2026-11-08');

        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()
            ->assertDontSee('Sala de prueba Norte')
            ->assertSee('No salió nada de pedidos ese día.');
    }

    public function test_insignia_de_la_oc_por_sala(): void
    {
        $exigeSinOc = $this->pedido($this->sala('Sala de prueba A', '9101', exigeOc: true), [$this->galleta->id => '1']);
        $noExige = $this->pedido($this->sala('Sala de prueba B', '9102'), [$this->galleta->id => '1']);
        $ocPedido = $this->pedido($this->sala('Sala de prueba C', '9103', exigeOc: true), [$this->galleta->id => '1'], '26119103123456');
        $salaCorreo = $this->sala('Sala de prueba D', '9104', exigeOc: true);
        $ocCorreo = $this->pedido($salaCorreo, [$this->galleta->id => '1']);
        $this->ocDelCorreo($salaCorreo, '43010000999999');
        foreach ([$exigeSinOc, $noExige, $ocPedido, $ocCorreo] as $pedido) {
            $this->envio($pedido, [$this->galleta->id => '1']);
        }

        // «OC lista», no «Lista» sola: que no se lea como «el pedido está listo».
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->assertSeeInOrder([
            'Para facturar (OC lista)',
            'Sala de prueba B', 'OC lista',
            'Sala de prueba C', 'OC lista', '26119103123456',
            'Sala de prueba D', 'OC lista', '43010000999999',
            'Esperando OC', 'Sala de prueba A', 'Esperando OC',
        ]);
    }

    public function test_no_hace_consultas_por_cada_tarjeta(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('facturacion.llego-de-fabrica'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $primero = $this->pedido($this->sala('Sala de prueba 1', '9201', exigeOc: true), [$this->galleta->id => '2', $this->canilla->id => '1']);
        $this->envio($primero, [$this->galleta->id => '1']);
        $contar(); // La primera carga trae la sesión y los permisos.
        $conUna = $contar();

        foreach (range(2, 5) as $n) {
            $pedido = $this->pedido($this->sala('Sala de prueba '.$n, '920'.$n, exigeOc: $n % 2 === 0), [$this->galleta->id => '2', $this->canilla->id => '1']);
            $this->envio($pedido, [$this->galleta->id => '1']);
            $this->envio($pedido, [$this->canilla->id => '1']);
        }

        $this->assertSame($conUna, $contar());
    }

    public function test_la_fabrica_y_quien_no_ve_facturacion_reciben_403(): void
    {
        $this->actingAs($this->fabrica)->get(route('facturacion.llego-de-fabrica'))->assertForbidden();

        // El jefe de vendedores ve los pedidos pero no Facturación.
        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->assertTrue($jefe->can('rutas.pedidos.ver'));
        $this->actingAs($jefe)->get(route('facturacion.llego-de-fabrica'))->assertForbidden();
    }

    public function test_facturacion_tiene_el_enlace_junto_a_nuevo_ccf(): void
    {
        $this->get(route('facturacion.index'))->assertOk()
            ->assertSeeInOrder(['Nuevo CCF', 'Llegó de pedidos'])
            ->assertSee(route('facturacion.llego-de-fabrica'), false);
    }

    public function test_con_envios_de_otros_dias_separa_lo_que_llego_el_dia_elegido(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->envio($pedido, [$this->galleta->id => '4'], '2026-11-09');

        // Hoy llegaron 4 y el 08/11 llegaron 6. Ninguno se facturó: «Facturar» lleva los 10.
        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()
            ->assertSee('También tuvo envíos otros días.')
            ->assertSee('«Facturar» lleva todo lo que sigue sin facturar, también lo de otros días.')
            ->assertSeeInOrder(['Llegó el 09/11', 'Total', 'Galleta de prueba', '10', '4', '10']);

        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-08']))->assertOk()
            ->assertSee('También tuvo envíos otros días.')
            ->assertSeeInOrder(['Llegó el 08/11', 'Total', 'Galleta de prueba', '10', '6', '10']);

        // Lo del 08/11 ya facturado: no hace falta avisar que «Facturar» lo lleva.
        $this->facturado([$pedido->envios()->where('numero', 1)->sole()], 'aceptado');
        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()
            ->assertSee('También tuvo envíos otros días.')
            ->assertDontSee('también lo de otros días');

        // Sin día (todo lo pendiente), la columna aparte es lo que va en el CCF.
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Una parte ya se facturó: «Por facturar» es lo que va en el CCF.')
            ->assertSeeInOrder(['Por facturar', 'Total', 'Galleta de prueba', '10', '4', '10']);
    }

    public function test_con_envios_de_un_solo_dia_no_agrega_columna_ni_aviso(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '6']);
        $this->envio($pedido, [$this->galleta->id => '4']);

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertDontSee('También tuvo envíos otros días')
            ->assertDontSee('Llegó el 09/11');
    }

    public function test_mandado_en_parte_muestra_el_estado_y_lo_que_va_en_otro_envio_sin_f(): void
    {
        $parcial = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10'], estado: EstadoPedidoSala::Parcial);
        $this->envio($parcial, [$this->galleta->id => '6']);
        $cerrado = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '3']);
        $this->envio($cerrado, [$this->galleta->id => '1']);

        $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder(['Sala de prueba Norte', 'Mandado en parte', 'Galleta de prueba', '10', '6', 'Va en otro envío', '4'])
            ->assertSeeInOrder(['Sala de prueba Sur', 'Mandado', 'Galleta de prueba', '3', '1', 'F', '2']);
        // La F solo en el pedido cerrado: en el parcial lo que falta todavía va a llegar.
        $this->assertSame(1, substr_count($respuesta->getContent(), 'aria-label="Faltó"'));
    }

    public function test_el_resto_listo_para_salir_lo_dice_y_sigue_yendo_en_otro_envio(): void
    {
        $listo = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10'], estado: EstadoPedidoSala::Listo);
        $this->envio($listo, [$this->galleta->id => '6']);

        $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder(['Sala de prueba Norte', 'Listo para salir', 'Galleta de prueba', '10', '6', 'Va en otro envío', '4'])
            ->assertDontSee('Listo en fábrica');
        $this->assertSame(0, substr_count($respuesta->getContent(), 'aria-label="Faltó"'));
    }

    public function test_la_oc_del_pedido_de_otra_sala_o_ya_facturada_no_sale_lista(): void
    {
        $otraSala = $this->pedido($this->sala('Sala de prueba Norte', '9001', exigeOc: true), [$this->galleta->id => '1'], '2611 0232 0017265');
        $usada = $this->pedido($this->sala('Sala de prueba Sur', '9002', exigeOc: true), [$this->galleta->id => '1'], '26119002000317');
        foreach ([$otraSala, $usada] as $pedido) {
            $this->envio($pedido, [$this->galleta->id => '1']);
        }
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        (new Dte)->forceFill([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '00', 'estado' => 'aceptado',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $usada->sala->cliente_id,
            'numero_control' => 'DTE-03-M001P001-000000000000001', 'numero_orden_compra' => '26119002000317',
            'fecha_emision' => '2026-11-08', 'hora_emision' => '08:00:00', 'total_pagar' => 10,
        ])->save();

        $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder(['Sala de prueba Norte', 'Revisar OC', 'La OC es de la sala 0232 y el pedido es de la 9001.'])
            ->assertSeeInOrder(['Sala de prueba Sur', 'Revisar OC', 'Esa OC ya tiene un CCF.']);
        $this->assertStringNotContainsString('>Lista<', preg_replace('/\s+/', '', $respuesta->getContent()));
    }

    public function test_la_hoja_lleva_el_dia_que_se_estaba_mirando(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10']);
        $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $this->envio($pedido, [$this->galleta->id => '4'], '2026-11-09');

        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-08']))->assertOk()
            ->assertSee(route('rutas.pedidos.hoja', [$pedido, 'fecha' => '2026-11-08']), false);
    }

    public function test_jefatura_y_contabilidad_ven_el_enlace_aunque_no_facturen(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '1']);
        $this->envio($pedido, [$this->galleta->id => '1']);

        foreach (['jefatura', 'contabilidad'] as $rol) {
            $usuario = User::factory()->create(['activo' => true])->assignRole($rol);
            $this->assertFalse($usuario->can('create', Dte::class));
            $this->actingAs($usuario)->get(route('facturacion.index'))->assertOk()
                ->assertSee(route('facturacion.llego-de-fabrica'), false)
                ->assertDontSee('Nuevo CCF');
        }

        // Contabilidad no ve los pedidos: la tarjeta no dibuja un pie vacío.
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Sala de prueba Norte')
            ->assertDontSee('Imprimir hoja')
            ->assertDontSee('border-t border-gray-100 pt-4', false);
    }

    /** Un CCF hecho para esos envíos (como lo deja FacturarPedido), en el estado pedido. */
    private function facturado(array $envios, string $estado = 'borrador', bool $borrado = false, string $emitido = '2026-11-09'): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::firstOrCreate(['codigo' => 'M001'], ['empresa_id' => $empresa->id, 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::firstOrCreate(['codigo' => 'P001'], ['establecimiento_id' => $estab->id, 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '00', 'estado' => $estado,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $envios[0]->pedido->sala->cliente_id,
            'numero_control' => $estado === 'borrador' ? null : 'DTE-03-M001P001-0000000000000'.str_pad((string) (Dte::withTrashed()->count() + 1), 2, '0', STR_PAD_LEFT),
            'fecha_emision' => $emitido, 'hora_emision' => '08:00:00', 'total_pagar' => 10,
        ]);
        $dte->save();
        foreach ($envios as $envio) {
            $envio->update(['dte_id' => $dte->id]);
        }
        if ($borrado) {
            $dte->delete();
        }

        return $dte;
    }

    public function test_sin_dia_lista_lo_pendiente_de_cualquier_dia_y_lo_facturado_hoy(): void
    {
        $ayer = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '3']);
        $this->envio($ayer, [$this->galleta->id => '3'], '2026-11-07');
        $hecho = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '3']);
        $this->facturado([$this->envio($hecho, [$this->galleta->id => '3'], '2026-11-08')], 'aceptado', emitido: '2026-11-08');
        $borrado = $this->pedido($this->sala('Sala de prueba Oeste', '9003'), [$this->galleta->id => '2']);
        $this->facturado([$this->envio($borrado, [$this->galleta->id => '2'], '2026-11-08')], borrado: true);
        $anulado = $this->pedido($this->sala('Sala de prueba Este', '9004'), [$this->galleta->id => '2']);
        $this->envio($anulado, [$this->galleta->id => '2'], '2026-11-08', anulado: true);
        $hoy = $this->pedido($this->sala('Sala de prueba Centro', '9005'), [$this->galleta->id => '2']);
        $this->facturado([$this->envio($hoy, [$this->galleta->id => '2'], '2026-11-07')], 'aceptado');

        $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->assertViewHas('fecha', null);

        // Lo facturado ayer y lo anulado no salen; el borrador borrado vuelve a «por facturar».
        $this->assertSame([$ayer->id, $borrado->id, $hoy->id], $respuesta->viewData('pedidos')->pluck('id')->all());
        $grupos = $respuesta->viewData('grupos')->mapWithKeys(fn ($g) => [$g['clave'] => $g['pedidos']->pluck('id')->all()]);
        $this->assertSame([$ayer->id, $borrado->id], $grupos['para_facturar']);
        $this->assertSame([$hoy->id], $grupos['facturados']);
    }

    public function test_cada_tarjeta_trae_su_estado_de_facturacion(): void
    {
        $porFacturar = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '3']);
        $this->envio($porFacturar, [$this->galleta->id => '3']);
        $conBorrador = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '3']);
        $borrador = $this->facturado([$this->envio($conBorrador, [$this->galleta->id => '3'])]);
        $conCcf = $this->pedido($this->sala('Sala de prueba Oeste', '9003'), [$this->galleta->id => '2']);
        $ccf = $this->facturado([$this->envio($conCcf, [$this->galleta->id => '2'])], 'aceptado');

        $facturacion = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertViewHas('sinFacturar', false)->viewData('facturacion');

        $this->assertCount(1, $facturacion[$porFacturar->id]['por_facturar']);
        $this->assertNull($facturacion[$porFacturar->id]['borrador']);
        $this->assertCount(0, $facturacion[$conBorrador->id]['por_facturar']);
        $this->assertSame($borrador->id, $facturacion[$conBorrador->id]['borrador']->id);
        $this->assertSame([$ccf->id], $facturacion[$conCcf->id]['facturados']->modelKeys());
    }

    public function test_avisa_cuantos_pedidos_de_otros_dias_siguen_sin_facturar(): void
    {
        $hoy = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '3']);
        $this->envio($hoy, [$this->galleta->id => '3']);
        foreach (['9002' => '2026-11-07', '9003' => '2026-11-08'] as $codigo => $dia) {
            $this->envio($this->pedido($this->sala('Sala de prueba '.$codigo, $codigo), [$this->galleta->id => '1']), [$this->galleta->id => '1'], $dia);
        }
        $hecho = $this->pedido($this->sala('Sala de prueba Sur', '9004'), [$this->galleta->id => '3']);
        $this->facturado([$this->envio($hecho, [$this->galleta->id => '3'], '2026-11-08')], 'aceptado');

        // Sin día ya se ve todo: no hace falta el aviso.
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->assertViewHas('otrosDiasSinFacturar', 0);
        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()->assertViewHas('otrosDiasSinFacturar', 2);
        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-08']))->assertOk()->assertViewHas('otrosDiasSinFacturar', 2);
    }

    public function test_la_tarjeta_dice_como_va_la_facturacion_y_solo_quien_factura_ve_el_boton(): void
    {
        $porFacturar = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '3']);
        $this->envio($porFacturar, [$this->galleta->id => '3']);
        $conBorrador = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '3']);
        $borrador = $this->facturado([$this->envio($conBorrador, [$this->galleta->id => '3'])]);
        $conCcf = $this->pedido($this->sala('Sala de prueba Oeste', '9003'), [$this->galleta->id => '2']);
        $ccf = $this->facturado([$this->envio($conCcf, [$this->galleta->id => '2'])], 'aceptado');
        $html = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Por facturar')
            ->assertSee('Borrador abierto')
            ->assertSee(route('facturacion.edit', $borrador), false)
            ->assertSee('Facturado en CCF n.º …'.substr($ccf->numero_control, -4))
            ->assertSee(route('facturacion.show', $ccf), false)
            ->getContent();
        $this->assertMatchesRegularExpression($this->facturar($porFacturar), $html);
        $this->assertDoesNotMatchRegularExpression($this->facturar($conBorrador), $html);
        $this->assertDoesNotMatchRegularExpression($this->facturar($conCcf), $html);

        // Jefatura mira pero no factura: sin botón.
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');
        $html = $this->actingAs($jefatura)->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Por facturar')->getContent();
        $this->assertDoesNotMatchRegularExpression($this->facturar($porFacturar), $html);
    }

    /** El «Facturar» de la fila: el enlace al formulario del CCF o el botón que va directo al borrador. */
    private function facturar(PedidoSala $pedido): string
    {
        return '/href="'.preg_quote(e(route('facturacion.create-ccf', ['pedido' => $pedido->id])), '/').'[&"]'
            .'|action="'.preg_quote(route('facturacion.pedidos.facturar', $pedido), '/').'"/';
    }

    /** Jefatura (13/11/2026): el CCF por su número corto y su PDF, sin el número de control. */
    public function test_jefatura_ve_el_ccf_corto_y_su_pdf_sin_numero_de_control(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Oeste', '9003'), [$this->galleta->id => '2']);
        $ccf = $this->facturado([$this->envio($pedido, [$this->galleta->id => '2'])], 'aceptado');
        $ccf->forceFill(['sello_recepcion' => 'SELLO-FICTICIO-1', 'fecha_procesamiento_mh' => now()]);
        Dte::withoutEvents(fn () => $ccf->saveQuietly());
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');

        $this->actingAs($jefatura)->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Facturado en CCF n.º …'.substr($ccf->numero_control, -4))
            ->assertSee(route('facturacion.pdf', $ccf), false)
            ->assertDontSee($ccf->numero_control)
            ->assertDontSee('href="'.route('facturacion.show', $ccf).'"', false);

        $this->actingAs($this->oficina)->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('title="'.$ccf->numero_control.'"', false)
            ->assertSee('href="'.route('facturacion.show', $ccf).'"', false);
    }

    public function test_el_ccf_del_mismo_pedido_no_avisa_que_la_oc_ya_tiene_ccf_si_no_queda_nada(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001', exigeOc: true), [$this->galleta->id => '5'], '26119001000317');
        $dte = $this->facturado([$this->envio($pedido, [$this->galleta->id => '3'])]);
        Dte::whereKey($dte->id)->update(['numero_orden_compra' => '26119001000317']);

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertDontSee('Esa OC ya tiene un CCF.')
            ->assertSee('OC lista');

        // Llega otro envío: ahora sí avisa, porque «Facturar» haría otro CCF con la misma OC.
        $this->envio($pedido, [$this->galleta->id => '2']);
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Esa OC ya tiene un CCF.');
    }

    public function test_el_ccf_rechazado_del_pedido_no_avisa_que_la_oc_ya_tiene_ccf(): void
    {
        $norte = $this->pedido($this->sala('Sala de prueba Norte', '9001', exigeOc: true), [$this->galleta->id => '5'], '26119001000317');
        $rechazado = $this->facturado([$this->envio($norte, [$this->galleta->id => '5'])], 'rechazado');
        Dte::whereKey($rechazado->id)->update(['numero_orden_compra' => '26119001000317']);

        // Hacienda lo rechazó: hay que volver a facturar y la tarjeta no dice lo contrario.
        $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Por facturar')
            ->assertDontSee('Esa OC ya tiene un CCF.')
            ->assertDontSee('Revisar OC');
        $this->assertMatchesRegularExpression($this->facturar($norte), $respuesta->getContent());
        $this->assertTrue($respuesta->viewData('oc')[$norte->id]['lista']);

        // Un CCF hecho a mano con la misma OC, aunque sea anterior al rechazado, sigue avisando.
        $sala = $this->sala('Sala de prueba Sur', '9002', exigeOc: true);
        $sur = $this->pedido($sala, [$this->galleta->id => '5'], '26119002000456');
        $envio = $this->envio($sur, [$this->galleta->id => '5']);
        $aMano = $this->facturado([$envio], 'aceptado');
        $envio->update(['dte_id' => null]);
        $rechazadoSur = $this->facturado([$envio], 'rechazado');
        Dte::whereKey([$aMano->id, $rechazadoSur->id])->update(['numero_orden_compra' => '26119002000456']);

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder(['Sala de prueba Sur', 'Revisar OC', 'Esa OC ya tiene un CCF.']);
    }

    public function test_el_pedido_facturado_con_una_oc_del_correo_muestra_la_oc_de_su_ccf(): void
    {
        // Norte: quedan otras OC del correo de la sala. Sur: no queda ninguna.
        $salaNorte = $this->sala('Sala de prueba Norte', '9001', exigeOc: true);
        $salaSur = $this->sala('Sala de prueba Sur', '9002', exigeOc: true);
        $this->ocDelCorreo($salaNorte, '43010000000123');
        $this->ocDelCorreo($salaNorte, '43010000000456');
        $this->ocDelCorreo($salaSur, '43020000000789');
        $ccf = [];
        foreach ([[$salaNorte, '43010000000123'], [$salaSur, '43020000000789']] as [$sala, $numero]) {
            $pedido = $this->pedido($sala, [$this->galleta->id => '5']);
            $dte = $this->facturado([$this->envio($pedido, [$this->galleta->id => '5'])]);
            Dte::whereKey($dte->id)->update(['numero_orden_compra' => $numero]);
            $ccf[$pedido->id] = [$dte, $numero];
        }

        foreach (['borrador', 'aceptado'] as $estado) {
            Dte::query()->update(['estado' => $estado]);
            $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
                ->assertSeeInOrder(['Sala de prueba Norte', 'OC lista', 'OC del CCF', '43010000000123'])
                ->assertSeeInOrder(['Sala de prueba Sur', 'OC lista', 'OC del CCF', '43020000000789'])
                ->assertDontSee('Esperando OC')
                ->assertDontSee('La sala pide OC y todavía no llegó.')
                ->assertDontSee('43010000000456');
            foreach ($ccf as $pedidoId => [, $numero]) {
                $this->assertSame([$numero], $respuesta->viewData('oc')[$pedidoId]['propuestas'], $estado);
            }
        }
    }

    public function test_con_un_borrador_abierto_y_un_envio_nuevo_la_tarjeta_no_dice_que_ya_se_facturo(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10']);
        $this->facturado([$this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08')]);
        $this->envio($pedido, [$this->galleta->id => '4']);
        $pendiente = route('facturacion.llego-de-fabrica');

        $this->get($pendiente)->assertOk()
            ->assertSee('Llegó algo después del borrador. Borralo y tocá «Facturar» otra vez: así sale un solo CCF con todo.')
            ->assertDontSee('Una parte ya se facturó');

        // Con el CCF ya hecho, sí: lo que sigue sin facturar va en otro CCF.
        Dte::query()->update(['estado' => 'aceptado']);
        $this->get($pendiente)->assertOk()->assertSee('Una parte ya se facturó');
    }

    public function test_facturar_desde_la_tarjeta_recuerda_el_filtro_para_volver(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '3']);
        $this->envio($pedido, [$this->galleta->id => '3']);

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('href="'.e(route('facturacion.create-ccf', ['pedido' => $pedido->id])).'"', false);
        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()
            ->assertSee('href="'.e(route('facturacion.create-ccf', ['pedido' => $pedido->id, 'fecha' => '2026-11-09'])).'"', false);
    }

    public function test_la_sala_que_espera_oc_igual_puede_abrir_el_formulario_para_escribirla(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001', exigeOc: true), [$this->galleta->id => '3']);
        $this->envio($pedido, [$this->galleta->id => '3']);

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Esperando OC')
            ->assertSee(route('facturacion.create-ccf', ['pedido' => $pedido->id]), false);
    }

    public function test_el_dia_es_un_filtro_aparte_y_avisa_lo_de_otros_dias(): void
    {
        $hoy = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '3']);
        $this->envio($hoy, [$this->galleta->id => '3']);
        $ayer = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '1']);
        $this->envio($ayer, [$this->galleta->id => '1'], '2026-11-08');
        $pendiente = route('facturacion.llego-de-fabrica');

        $this->get($pendiente)->assertOk()
            ->assertSee('Sala de prueba Norte')
            ->assertSee('Sala de prueba Sur')
            ->assertSee('Todo lo que falta facturar, de cualquier día.')
            ->assertDontSee('pedido de otros días sin facturar')
            ->assertDontSee('>Limpiar<', false);

        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()
            ->assertSee('Lo que salió de pedidos el 09/11/2026.')
            ->assertSee('Hay 1 pedido de otros días sin facturar.')
            ->assertSee('href="'.$pendiente.'"', false)
            ->assertSee('>Limpiar<', false)
            ->assertDontSee('Sala de prueba Sur');

        // Con todo facturado, lo dice.
        PedidoSalaEnvio::query()->update(['anulado_en' => now()]);
        $this->get($pendiente)->assertOk()->assertSee('No queda nada por facturar.');
    }

    /**
     * Melqui (24/11/2026): abre con TODO lo pendiente, sin importar el día, en grupos: lo que
     * se puede facturar ya, lo que espera la OC, lo que espera el resto (sin «Facturar», para
     * que el resto no salga en otro CCF con la misma OC sin que nadie lo note) y, al final y
     * cerrado, lo facturado hoy.
     */
    public function test_abre_con_todo_lo_pendiente_de_cualquier_dia_en_grupos(): void
    {
        $lista = $this->pedido($this->sala('Sala de prueba Lista', '9301'), [$this->galleta->id => '3']);
        $this->envio($lista, [$this->galleta->id => '3'], '2026-11-06');
        $sinOc = $this->pedido($this->sala('Sala de prueba Sin OC', '9302', exigeOc: true), [$this->galleta->id => '2']);
        $this->envio($sinOc, [$this->galleta->id => '2'], '2026-11-08');
        $resto = $this->pedido($this->sala('Sala de prueba Resto', '9303'), [$this->galleta->id => '10'], estado: EstadoPedidoSala::Parcial);
        $this->facturado([$this->envio($resto, [$this->galleta->id => '6'], '2026-11-05')], 'aceptado', emitido: '2026-11-05');
        $hoy = $this->pedido($this->sala('Sala de prueba Hoy', '9304'), [$this->galleta->id => '4']);
        $this->facturado([$this->envio($hoy, [$this->galleta->id => '4'], '2026-11-08')], 'aceptado');
        $antes = $this->pedido($this->sala('Sala de prueba Antes', '9305'), [$this->galleta->id => '4']);
        $this->facturado([$this->envio($antes, [$this->galleta->id => '4'], '2026-11-07')], 'aceptado', emitido: '2026-11-07');

        $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder([
                'Para facturar (OC lista)', 'Sala de prueba Lista',
                'Esperando OC', 'Sala de prueba Sin OC',
                'Esperando el resto', 'Sala de prueba Resto', 'Va en otro envío', '4',
                'Ya facturados hoy', 'Sala de prueba Hoy',
            ])
            ->assertDontSee('Sala de prueba Antes');
        $this->assertMatchesRegularExpression($this->facturar($lista), $respuesta->getContent());
        $this->assertDoesNotMatchRegularExpression($this->facturar($resto), $respuesta->getContent());
        $this->assertDoesNotMatchRegularExpression($this->facturar($hoy), $respuesta->getContent());
        // Lo facturado va cerrado al final: se abre si hace falta. Con el título en flex no sale
        // el triangulito, así que lleva una pista que se ve: «Ver» (o «Ocultar»), como las filas.
        $this->assertMatchesRegularExpression(
            '/<details(?![^>]*\sopen)[^>]*>\s*<summary[^>]*>\s*<h2[^>]*>\s*Ya facturados hoy\s*<\/h2>(?:(?!<\/summary>).)*<span class="[^"]*text-indigo-600 underline"[^>]*x-text="abierto \? \'Ocultar\' : \'Ver\'"[^>]*>Ver<\/span>\s*<\/summary>/s',
            $respuesta->getContent(),
        );
    }

    /** Una fila por sala: los productos (Pidió, Llegó, Falta) se abren al tocarla. */
    public function test_los_productos_se_ven_al_tocar_la_fila(): void
    {
        $pedido = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '5']);
        $this->envio($pedido, [$this->galleta->id => '5']);
        $panel = 'productos-'.$pedido->id;

        $html = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button type="button"[^>]*aria-expanded="false"[^>]*aria-controls="'.$panel.'"[^>]*>\s*<span[^>]*>\s*<span[^>]*>Sala de prueba Norte/', $html);
        $this->assertMatchesRegularExpression('/<div id="'.$panel.'" x-show="abierto" x-cloak[^>]*>.*?Pidió.*?Llegó.*?Falta.*?Galleta de prueba/s', $html);
    }

    /** Pastillas por zona, como en Rutas › «Por entregar» (aquí cerca o afuera): solo para mirar. */
    public function test_las_pastillas_por_zona_filtran_sin_guardar_nada(): void
    {
        $afuera = Ruta::create(['nombre' => 'Zona de prueba Afuera']);
        $cerca = $this->pedido($this->sala('Sala de prueba Cerca', '9401'), [$this->galleta->id => '1']);
        $this->envio($cerca, [$this->galleta->id => '1']);
        $lejos = $this->pedido($this->sala('Sala de prueba Lejos', '9402'), [$this->galleta->id => '1']);
        $lejos->update(['ruta_id' => $afuera->id]);
        $this->envio($lejos, [$this->galleta->id => '1'], '2026-11-08');

        $todas = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Sala de prueba Cerca')->assertSee('Sala de prueba Lejos');
        $this->assertMatchesRegularExpression('/<a[^>]*aria-current="page"[^>]*>\s*Todas 2/', $todas->getContent());
        $todas->assertSeeInOrder(['Ruta de prueba 1', 'Zona de prueba Afuera 1'])
            ->assertSee(e(route('facturacion.llego-de-fabrica', ['ruta' => $afuera->id])), false);

        $soloAfuera = $this->get(route('facturacion.llego-de-fabrica', ['ruta' => $afuera->id]))->assertOk()
            ->assertSee('Sala de prueba Lejos')->assertDontSee('Sala de prueba Cerca');
        $this->assertMatchesRegularExpression('/<a[^>]*aria-current="page"[^>]*>\s*Zona de prueba Afuera 1/', $soloAfuera->getContent());

        $this->get(route('facturacion.llego-de-fabrica', ['ruta' => '999999']))->assertOk()
            ->assertSee('Esa zona no tiene nada en esta lista.')
            ->assertDontSee('Sala de prueba Cerca');
    }

    /** Con su lugar en el menú de Facturación, marcado al estar ahí. */
    public function test_tiene_su_lugar_en_el_menu_de_facturacion(): void
    {
        $enlace = '/<a[^>]*href="'.preg_quote(route('facturacion.llego-de-fabrica'), '/').'"[^>]*>\s*Llegó de pedidos\s*</';

        $aca = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression($enlace, $aca);
        $this->assertMatchesRegularExpression('/<a[^>]*href="'.preg_quote(route('facturacion.llego-de-fabrica'), '/').'"[^>]*aria-current="page"/', $aca);

        $this->assertDoesNotMatchRegularExpression('/<a[^>]*href="'.preg_quote(route('facturacion.llego-de-fabrica'), '/').'"[^>]*aria-current="page"/',
            $this->get(route('facturacion.index'))->assertOk()->getContent());
    }
}
