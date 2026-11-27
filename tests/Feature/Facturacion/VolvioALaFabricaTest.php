<?php

namespace Tests\Feature\Facturacion;

use App\Enums\EstadoPedidoSala;
use App\Enums\TipoDte;
use App\Mail\Pedidos\ResumenDiarioPedidosCorreo;
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
use App\Services\Rutas\PasoPedido;
use App\Services\Rutas\PdfFabricaDiario;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosYEntregas;
use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Volvió a la fábrica» en Facturación › «Llegó de pedidos» (Melqui, 27/11/2026): el pedido
 * salió pero la sala no lo recibió y la mercadería volvió. Se cierra como `volvio`, sus envíos
 * quedan anulados (no por facturar, no pendientes) y la auditoría guarda el motivo. No toca
 * inventario ni crea documentos. Datos inventados.
 */
class VolvioALaFabricaTest extends TestCase
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

        $this->oficina = User::factory()->create(['activo' => true, 'name' => 'Usuario de prueba'])->assignRole('facturacion');
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta de prueba']);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01']);

        $this->actingAs($this->oficina);
    }

    private function sala(string $nombre = 'Sala de prueba Norte', string $codigo = '9001'): ClienteSucursal
    {
        return Cliente::factory()->create(['nombre' => 'Cliente de prueba '.$codigo])
            ->sucursales()->create(['nombre' => $nombre, 'codigo' => $codigo, 'ruta_id' => $this->ruta->id]);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(array $cantidades, EstadoPedidoSala $estado = EstadoPedidoSala::Enviado, ?ClienteSucursal $sala = null, string $origen = 'sala'): PedidoSala
    {
        $pedido = PedidoSala::create([
            'origen' => $origen, 'cliente_sucursal_id' => $origen === 'sala' ? ($sala ?? $this->sala())->id : null, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->oficina->id,
            'fecha' => '2026-11-08', 'estado' => $estado,
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

    /** Un CCF para esos envíos, como lo deja FacturarPedido. */
    private function facturado(array $envios, string $estado = 'aceptado'): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::firstOrCreate(['codigo' => 'M001'], ['empresa_id' => $empresa->id, 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::firstOrCreate(['codigo' => 'P001'], ['establecimiento_id' => $estab->id, 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '00', 'estado' => $estado,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $envios[0]->pedido->sala->cliente_id,
            'numero_control' => $estado === 'borrador' ? null : 'DTE-03-M001P001-000000000000001',
            'fecha_emision' => '2026-11-09', 'hora_emision' => '08:00:00', 'total_pagar' => 10,
        ]);
        $dte->save();
        foreach ($envios as $envio) {
            $envio->update(['dte_id' => $dte->id]);
        }

        return $dte;
    }

    private function volvio(PedidoSala $pedido, ?string $motivo = 'La sala no lo recibió'): TestResponse
    {
        return $this->from(route('facturacion.llego-de-fabrica'))
            ->patch(route('facturacion.pedidos.volvio', $pedido), ['motivo' => $motivo, 'volvio_pedido' => $pedido->id]);
    }

    private function auditorias(PedidoSala $pedido)
    {
        return Activity::query()->where('subject_type', $pedido->getMorphClass())->where('subject_id', $pedido->id)
            ->where('description', PedidosFabrica::AUDITORIA_VOLVIO)->get();
    }

    public function test_la_tarjeta_lleva_el_boton_con_su_confirmacion_y_el_motivo(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5']);
        $this->envio($pedido, [$this->galleta->id => '5']);

        $html = $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee(route('facturacion.pedidos.volvio', $pedido), false)
            ->assertSeeInOrder(['Imprimir hoja', 'Volvió a la fábrica', 'Facturar'])
            ->getContent();

        // El diálogo pide el motivo (hasta 200) y lleva CSRF y PATCH.
        $this->assertMatchesRegularExpression('/name="motivo"[^>]*required[^>]*maxlength="200"/', $html);
        $this->assertMatchesRegularExpression('/action="'.preg_quote(route('facturacion.pedidos.volvio', $pedido), '/').'".*?name="_token".*?name="_method" value="PATCH"/s', $html);
    }

    public function test_marcar_que_volvio_lo_saca_de_por_facturar_cierra_el_pedido_y_audita_el_motivo(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '4']);
        $deshecho = $this->envio($pedido, [$this->galleta->id => '3'], '2026-11-08', anulado: true);
        $uno = $this->envio($pedido, [$this->galleta->id => '6'], '2026-11-08');
        $dos = $this->envio($pedido, [$this->galleta->id => '4', $this->canilla->id => '4']);

        $this->volvio($pedido)
            ->assertRedirect(route('facturacion.llego-de-fabrica'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'El pedido de «Sala de prueba Norte» quedó cerrado: volvió a la fábrica.');

        $this->assertSame(EstadoPedidoSala::Volvio, $pedido->refresh()->estado);
        $this->assertSame('Volvió a la fábrica', $pedido->estado->label());
        foreach ([$uno, $dos] as $envio) {
            $envio->refresh();
            $this->assertNotNull($envio->anulado_en);
            $this->assertSame($this->oficina->id, $envio->anulado_por);
        }
        // El deshecho por la fábrica queda como estaba.
        $this->assertSame($this->fabrica->id, $deshecho->refresh()->anulado_por);

        $auditoria = $this->auditorias($pedido)->sole();
        $this->assertSame('pedido_sala', $auditoria->log_name);
        $this->assertSame($this->oficina->id, $auditoria->causer_id);
        $this->assertSame('La sala no lo recibió', $auditoria->properties['motivo']);
        $this->assertSame('enviado', $auditoria->properties['estado_anterior']);
        $this->assertSame([['envio_id' => $uno->id, 'numero' => 2], ['envio_id' => $dos->id, 'numero' => 3]], $auditoria->properties['envios']);

        // Ya no está en «Llegó de pedidos», ni para facturar ni en otro grupo.
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertViewHas('pedidos', fn ($pedidos) => ! $pedidos->contains('id', $pedido->id));
        // No quedó nada pendiente ni por facturar.
        $this->assertSame(0, $pedido->envios()->porFacturar()->count());
    }

    public function test_sin_motivo_o_con_mas_de_200_letras_no_cambia_nada(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5']);
        $envio = $this->envio($pedido, [$this->galleta->id => '5']);

        foreach ([null, '   ', str_repeat('a', 201)] as $motivo) {
            $this->volvio($pedido, $motivo)->assertRedirect(route('facturacion.llego-de-fabrica'))->assertSessionHasErrors('motivo');
        }

        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertNull($envio->refresh()->anulado_en);
        $this->assertCount(0, $this->auditorias($pedido));

        // 200 justas sí.
        $this->volvio($pedido, str_repeat('a', 200))->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Volvio, $pedido->refresh()->estado);
    }

    public function test_sin_permiso_de_facturar_es_403_y_no_ve_el_boton(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5']);
        $envio = $this->envio($pedido, [$this->galleta->id => '5']);

        // Jefatura mira la lista pero no factura.
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');
        $this->actingAs($jefatura)->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSee('Sala de prueba Norte')
            ->assertDontSee(route('facturacion.pedidos.volvio', $pedido), false);

        foreach ([$jefatura, $this->fabrica, User::factory()->create(['activo' => true])->assignRole('contabilidad')] as $usuario) {
            $this->actingAs($usuario)->patch(route('facturacion.pedidos.volvio', $pedido), ['motivo' => 'La sala no lo recibió'])->assertForbidden();
        }

        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertNull($envio->refresh()->anulado_en);
    }

    public function test_con_un_envio_facturado_o_en_borrador_no_se_ve_y_el_servidor_lo_rechaza(): void
    {
        // Salió una parte, ya facturada, y llegó otra por facturar: sigue en la lista.
        $conCcf = $this->pedido([$this->galleta->id => '10'], EstadoPedidoSala::Enviado);
        $this->facturado([$this->envio($conCcf, [$this->galleta->id => '6'], '2026-11-08')]);
        $porFacturar = $this->envio($conCcf, [$this->galleta->id => '4']);
        $conBorrador = $this->pedido([$this->galleta->id => '3'], sala: $this->sala('Sala de prueba Sur', '9002'));
        $this->facturado([$this->envio($conBorrador, [$this->galleta->id => '3'])], 'borrador');

        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertViewHas('pedidos', fn ($pedidos) => $pedidos->contains('id', $conCcf->id) && $pedidos->contains('id', $conBorrador->id))
            ->assertDontSee(route('facturacion.pedidos.volvio', $conCcf), false)
            ->assertDontSee(route('facturacion.pedidos.volvio', $conBorrador), false);

        $this->volvio($conCcf)->assertSessionHasErrors(['pedido' => PedidosFabrica::VOLVIO_FACTURADO]);
        $this->volvio($conBorrador)->assertSessionHasErrors(['pedido' => PedidosFabrica::VOLVIO_EN_BORRADOR]);

        $this->assertSame(EstadoPedidoSala::Enviado, $conCcf->refresh()->estado);
        $this->assertNull($porFacturar->refresh()->anulado_en);
        $this->assertSame(0, $conCcf->envios()->whereNotNull('anulado_en')->count() + $conBorrador->envios()->whereNotNull('anulado_en')->count());
        $this->assertSame(0, Activity::where('description', PedidosFabrica::AUDITORIA_VOLVIO)->count());
    }

    public function test_el_pedido_web_se_rechaza(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '2'], origen: 'web');
        $envio = $this->envio($pedido, [$this->galleta->id => '2']);

        $this->volvio($pedido)->assertSessionHasErrors(['pedido' => PedidosFabrica::VOLVIO_WEB]);

        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertNull($envio->refresh()->anulado_en);
    }

    public function test_el_doble_envio_del_formulario_hace_un_solo_cambio(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5']);
        $envio = $this->envio($pedido, [$this->galleta->id => '5']);

        $this->volvio($pedido)->assertSessionHasNoErrors();
        $anulado = $envio->refresh()->anulado_en;
        $this->travel(1)->minutes();
        $this->volvio($pedido, 'Otro motivo')->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Ese pedido ya estaba marcado: volvió a la fábrica.');

        $this->assertEquals($anulado, $envio->refresh()->anulado_en);
        $this->assertSame('La sala no lo recibió', $this->auditorias($pedido)->sole()->properties['motivo']);
    }

    public function test_la_fabrica_no_lo_vuelve_a_ver_para_mandar_ni_se_reabre(): void
    {
        // Salió una parte y el resto seguía por mandar: vuelve todo y no queda nada pendiente.
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '4'], EstadoPedidoSala::Parcial);
        $this->envio($pedido, [$this->galleta->id => '10']);
        $pedido->lineas()->update(['va_borrador' => '1']);
        $pedido->update(['armando_desde' => now(), 'armando_por' => $this->fabrica->id]);

        $this->volvio($pedido)->assertSessionHasNoErrors();

        $pedido->refresh();
        $this->assertNull($pedido->armando_desde);
        $this->assertSame(0, $pedido->lineas()->whereNotNull('va_borrador')->count());
        $fabrica = app(PedidosFabrica::class);
        $this->assertFalse($fabrica->porMandar()->get()->contains('id', $pedido->id));
        $this->assertFalse($fabrica->hechos()['cerrados']->contains('id', $pedido->id));
        $this->assertFalse($this->fabrica->can('reabrir', $pedido));
        $this->assertFalse($this->fabrica->can('mandar', $pedido));
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.index'))->assertOk()->assertViewHas('cuantosPorMandar', 0);
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.show', $pedido))->assertRedirect(route('fabrica.pedidos.index'));

        // La oficina ya no lo corrige ni lo anula: si la sala lo quiere, se saca otro pedido.
        $this->assertFalse($this->oficina->can('corregirEnOficina', $pedido));

        // En Viajes no espera nada: va aparte, como lo que no se trae.
        [$paso, $detalle] = app(PasoPedido::class)->de($pedido->load('envios'), []);
        $this->assertSame([PasoPedido::NO_SE_TRAE, 'Volvió a la fábrica'], [$paso, $detalle]);
        $this->assertTrue(PasoPedido::terminado($paso));
    }

    public function test_la_ficha_dice_que_volvio_con_fecha_motivo_y_quien_y_el_envio_salio_y_volvio(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5']);
        $this->envio($pedido, [$this->galleta->id => '2'], '2026-11-08', anulado: true);
        $this->envio($pedido, [$this->galleta->id => '5']);
        $this->volvio($pedido)->assertSessionHasNoErrors();

        $html = $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertSee('Volvió a la fábrica el 09/11/2026 · Motivo: La sala no lo recibió · Lo marcó Usuario de prueba')
            ->assertSee('Salió y volvió a la fábrica el 09/11/2026 · lo marcó Usuario de prueba')
            ->assertDontSee('Imprimir hoja')
            ->assertDontSee(route('rutas.pedidos.corregir', $pedido), false)
            ->assertDontSee(route('rutas.pedidos.anular', $pedido), false)
            ->getContent();

        // El envío que deshizo la fábrica sigue diciendo «Anulado»; el que volvió, no.
        $this->assertSame(1, substr_count($html, 'Anulado por'));
    }

    public function test_pedidos_y_entregas_y_el_correo_no_lo_cuentan_por_entregar_y_dicen_que_volvio(): void
    {
        // Entró hoy, salió ayer (lo dijo el correo anterior) y hoy volvió.
        $pedido = $this->pedido([$this->galleta->id => '5'], EstadoPedidoSala::Parcial);
        $envio = $this->envio($pedido, [$this->galleta->id => '3'], '2026-11-08');
        PedidoSalaEnvio::whereKey($envio->id)->update(['created_at' => '2026-11-08 15:00:00']);
        $this->volvio($pedido)->assertSessionHasNoErrors();

        $datos = app(PedidosYEntregas::class)->calcular('hoy');
        $this->assertSame(EstadoPedidoSala::Volvio, collect($datos['pedidos']['lista'])->firstWhere('numero', $pedido->id)['estado']);
        $this->assertSame(1, $datos['pedidos']['por_estado']['volvio']);
        foreach (['sin_empezar', 'armando', 'listos', 'salieron'] as $grupo) {
            $this->assertNotContains($pedido->id, array_column($datos['fabrica'][$grupo], 'numero'), $grupo);
        }

        $contenido = app(ResumenDiarioPedidos::class)->contenido(Carbon::parse('2026-11-09'), now());
        $sala = $contenido['salas']['Sala de prueba Norte'];
        $this->assertSame([[], [], [], []], [$sala['salio'], $sala['deshecho'], $sala['listos'], $sala['por_mandar']]);
        $this->assertSame([['numero' => $pedido->id, 'nada' => false, 'volvio' => true, 'motivo' => 'La sala no lo recibió', 'productos' => []]], $sala['cerrados']);

        $html = (new ResumenDiarioPedidosCorreo($contenido + ['ventas' => null, 'vendedores' => null]))->render();
        $this->assertStringContainsString('volvió a la fábrica el pedido N.º '.$pedido->id.' (La sala no lo recibió)', $html);
        $this->assertStringNotContainsString('se deshizo', $html);
        $this->assertStringContainsString('Volvió a la fábrica el pedido N.º '.$pedido->id, app(PdfFabricaDiario::class)->html($contenido));
    }
}
