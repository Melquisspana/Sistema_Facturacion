<?php

namespace Tests\Feature\Facturacion;

use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
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
    private function pedido(ClienteSucursal $sala, array $cantidades, ?string $oc = null): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $this->ruta->id,
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

    private function ocDelCorreo(ClienteSucursal $sala, string $numero): void
    {
        OrdenCompraRecibida::create([
            'numero' => $numero, 'sala_codigo' => $sala->codigo, 'cliente_sucursal_id' => $sala->id,
            'fecha_creacion' => '2026-11-08', 'fecha_despacho' => '2026-11-10', 'total' => '22.90',
            'pdf_path' => 'ppq/ordenes-compra/x.pdf', 'pdf_hash' => hash('sha256', $numero),
            'gmail_message_id' => 'm-'.$numero, 'estado' => OrdenCompraRecibida::LEIDA,
        ]);
    }

    public function test_muestra_una_tarjeta_por_sala_con_lo_que_llego_hoy_y_su_hoja(): void
    {
        $norte = $this->pedido($this->sala('Sala de prueba Norte', '9001'), [$this->galleta->id => '10', $this->canilla->id => '4']);
        $norte->lineas()->where('producto_id', $this->galleta->id)->update(['motivo_no_enviado' => 'No hubo masa']);
        $this->envio($norte, [$this->galleta->id => '6']);
        $this->envio($norte, [$this->canilla->id => '4']);
        $ayer = $this->pedido($this->sala('Sala de prueba Sur', '9002'), [$this->galleta->id => '3']);
        $this->envio($ayer, [$this->galleta->id => '3'], '2026-11-08');
        $anulado = $this->pedido($this->sala('Sala de prueba Oeste', '9003'), [$this->galleta->id => '2']);
        $this->envio($anulado, [$this->galleta->id => '2'], '2026-11-09', anulado: true);

        $respuesta = $this->get(route('facturacion.llego-de-fabrica'))->assertOk();

        $respuesta->assertSee('Llegó de la fábrica')
            ->assertSee('Sala de prueba Norte')
            ->assertSee('9001')
            ->assertSee('Ruta de prueba')
            ->assertSee('Vendedor de prueba')
            ->assertSee(route('rutas.pedidos.hoja', $norte), false)
            ->assertSeeInOrder(['Galleta de prueba', 'No hubo masa', '10', '6', 'F', 'Canilla de prueba', '4', '4'])
            ->assertDontSee('Sala de prueba Sur')
            ->assertDontSee('Sala de prueba Oeste')
            ->assertDontSee('7.77');
        // Una sola tarjeta para la sala aunque llegó en dos envíos.
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

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertDontSee('Sala de prueba Norte')
            ->assertSee('No llegó nada de la fábrica este día.');
    }

    public function test_insignia_de_la_oc_por_sala(): void
    {
        $exigeSinOc = $this->pedido($this->sala('Sala de prueba A', '9101', exigeOc: true), [$this->galleta->id => '1']);
        $noExige = $this->pedido($this->sala('Sala de prueba B', '9102'), [$this->galleta->id => '1']);
        $ocPedido = $this->pedido($this->sala('Sala de prueba C', '9103', exigeOc: true), [$this->galleta->id => '1'], '43010000123456');
        $salaCorreo = $this->sala('Sala de prueba D', '9104', exigeOc: true);
        $ocCorreo = $this->pedido($salaCorreo, [$this->galleta->id => '1']);
        $this->ocDelCorreo($salaCorreo, '43010000999999');
        foreach ([$exigeSinOc, $noExige, $ocPedido, $ocCorreo] as $pedido) {
            $this->envio($pedido, [$this->galleta->id => '1']);
        }

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()->assertSeeInOrder([
            'Sala de prueba A', 'Esperando OC',
            'Sala de prueba B', 'Lista',
            'Sala de prueba C', 'Lista', '43010000123456',
            'Sala de prueba D', 'Lista', '43010000999999',
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
            ->assertSeeInOrder(['Nuevo CCF', 'Llegó de la fábrica'])
            ->assertSee(route('facturacion.llego-de-fabrica'), false);
    }
}
