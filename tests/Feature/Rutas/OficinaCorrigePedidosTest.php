<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Enums\TipoDte;
use App\Facades\Ajustes;
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
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosOficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * La oficina corrige o anula un pedido de sala (Melqui, 13/11/2026): Facturación, el jefe de
 * vendedores y el administrador cambian la sala equivocada y las cantidades de lo que todavía
 * no salió, o lo anulan con motivo. Nunca si ya se facturó. Ni la PC de pedidos, ni el
 * vendedor, ni Jefatura. Todos los datos son inventados.
 */
class OficinaCorrigePedidosTest extends TestCase
{
    use RefreshDatabase;

    private User $oficina;

    private User $fabrica;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private Ruta $otraZona;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    private Producto $pan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 09:00:00');
        config()->set('planta.enabled', false);

        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Zona Inventada']);
        $this->otraZona = Ruta::create(['nombre' => 'Otra Zona Inventada']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Súper Ficticio'])
            ->sucursales()->create(['nombre' => 'Súper Ficticio Centro', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
        $this->otraSala = Cliente::factory()->create(['nombre' => 'Tienda Inventada'])
            ->sucursales()->create(['nombre' => 'Tienda Inventada Norte', 'codigo' => '9002', 'ruta_id' => $this->otraZona->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->pan = Producto::factory()->create(['nombre' => 'Pan de prueba', 'codigo' => 'P01']);

        $this->actingAs($this->oficina);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(array $cantidades, ?ClienteSucursal $sala = null): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => ($sala ?? $this->sala)->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => '2026-11-13', 'estado' => EstadoPedidoSala::Registrado,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $pedido->lineas()->create(['producto_id' => $producto, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    private function linea(PedidoSala $pedido, Producto $producto): int
    {
        return $pedido->lineas()->where('producto_id', $producto->id)->value('id');
    }

    private function version(PedidoSala $pedido): string
    {
        return PedidosOficina::version(PedidoSala::with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id));
    }

    /** La PC de pedidos manda una parte; con `sigue`, lo que falta queda por mandar. */
    private function mandar(PedidoSala $pedido, array $cantidades, bool $sigue = true): PedidoSalaEnvio
    {
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }
        $cargado = PedidoSala::with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id);

        return app(PedidosFabrica::class)->mandar($pedido, [
            'clave' => (string) Str::uuid(), 'version' => $cargado->versionProduccion(), 'cantidades' => $porLinea, 'sigue' => $sigue,
        ], $this->fabrica);
    }

    /**
     * @param  array<int, string>  $cantidades  [producto_id => cantidad]
     * @param  array<string, mixed>  $extra
     */
    private function corregir(PedidoSala $pedido, array $cantidades, array $extra = []): TestResponse
    {
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }

        return $this->put(route('rutas.pedidos.corregir.guardar', $pedido), $extra + [
            'version' => $this->version($pedido),
            'cantidades' => $porLinea,
            'motivo' => 'El vendedor se equivocó.',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function anular(PedidoSala $pedido, array $extra = []): TestResponse
    {
        return $this->patch(route('rutas.pedidos.anular.guardar', $pedido), $extra + [
            'version' => $this->version($pedido),
            'motivo' => 'La sala no pidió nada.',
        ]);
    }

    private function facturar(PedidoSalaEnvio $envio, EstadoDte $estado): void
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '00', 'estado' => $estado->value,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $this->sala->cliente_id,
            'fecha_emision' => '2026-11-13', 'hora_emision' => '08:00:00',
        ]);
        $dte->save();
        $envio->update(['dte_id' => $dte->id]);
    }

    // ══════════════════════════════ corregir

    public function test_facturacion_corrige_la_sala_y_las_cantidades_y_queda_en_la_auditoria(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '1000', $this->pan->id => '5']);

        $this->get(route('rutas.pedidos.corregir', $pedido))->assertOk()->assertSee('Galleta de prueba');
        $this->get(route('rutas.pedidos.corregir', ['pedido' => $pedido, 'q' => 'Tienda Inventada']))->assertOk()->assertSee('Tienda Inventada Norte');

        $this->corregir($pedido, [$this->galleta->id => '10', $this->pan->id => '5.5'], ['cliente_sucursal_id' => $this->otraSala->id])
            ->assertRedirect(route('rutas.pedidos.show', $pedido))->assertSessionHas('status');

        $pedido->refresh();
        $this->assertSame($this->otraSala->id, $pedido->cliente_sucursal_id);
        // La zona sigue a la sala nueva; el viaje no cambia.
        $this->assertSame($this->otraZona->id, $pedido->ruta_id);
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $this->assertSame('10.0000', (string) $pedido->lineas()->where('producto_id', $this->galleta->id)->value('cantidad'));
        $this->assertSame('5.5000', (string) $pedido->lineas()->where('producto_id', $this->pan->id)->value('cantidad'));

        $log = Activity::where('description', PedidosOficina::AUDITORIA_CORRIGE)->sole();
        $this->assertSame($this->oficina->id, $log->causer_id);
        $this->assertSame('El vendedor se equivocó.', $log->properties['motivo']);
        $this->assertSame($this->sala->id, $log->properties['antes']['cliente_sucursal_id']);
        $this->assertSame('Súper Ficticio Centro', $log->properties['antes']['sala']);
        $this->assertSame($this->otraSala->id, $log->properties['despues']['cliente_sucursal_id']);
        $antes = collect($log->properties['antes']['lineas'])->keyBy('producto_id');
        $despues = collect($log->properties['despues']['lineas'])->keyBy('producto_id');
        $this->assertSame('1000.0000', $antes[$this->galleta->id]['cantidad']);
        $this->assertSame('10.0000', $despues[$this->galleta->id]['cantidad']);
        $this->assertSame('5.5000', $despues[$this->pan->id]['cantidad']);
    }

    public function test_el_jefe_de_vendedores_y_el_administrador_tambien_corrigen_y_anulan(): void
    {
        foreach (['jefe_vendedores', 'administrador'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol));
            $pedido = $this->pedido([$this->galleta->id => '12']);

            $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
                ->assertSee(route('rutas.pedidos.corregir', $pedido))->assertSee(route('rutas.pedidos.anular', $pedido));
            $this->corregir($pedido, [$this->galleta->id => '10'])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('10.0000', (string) $pedido->lineas()->value('cantidad'));
            $this->anular($pedido)->assertSessionHasNoErrors()->assertRedirect(route('rutas.pedidos.show', $pedido));
            $this->assertSame(EstadoPedidoSala::Anulado, $pedido->refresh()->estado);
        }
    }

    public function test_la_pc_de_pedidos_el_vendedor_y_jefatura_no_corrigen_ni_anulan(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $version = $this->version($pedido);
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');

        foreach ([$this->fabrica, $this->vendedor, $jefatura] as $usuario) {
            $this->actingAs($usuario);
            $this->get(route('rutas.pedidos.corregir', $pedido))->assertForbidden();
            $this->get(route('rutas.pedidos.anular', $pedido))->assertForbidden();
            $this->put(route('rutas.pedidos.corregir.guardar', $pedido), [
                'version' => $version, 'motivo' => 'Prueba', 'cantidades' => [$this->linea($pedido, $this->galleta) => '1'],
            ])->assertForbidden();
            $this->patch(route('rutas.pedidos.anular.guardar', $pedido), ['version' => $version, 'motivo' => 'Prueba'])->assertForbidden();
        }

        // Jefatura ve el detalle, pero sin los botones.
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertDontSee(route('rutas.pedidos.corregir', $pedido))->assertDontSee(route('rutas.pedidos.anular', $pedido));
        $this->assertSame('12.0000', (string) $pedido->lineas()->value('cantidad'));
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame(0, Activity::whereIn('description', [PedidosOficina::AUDITORIA_CORRIGE, PedidosOficina::AUDITORIA_ANULA])->count());
    }

    public function test_no_baja_de_lo_ya_mandado_y_no_toca_lo_enviado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12', $this->pan->id => '6']);
        $envio = $this->mandar($pedido, [$this->galleta->id => '4']);
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);

        $this->corregir($pedido, [$this->galleta->id => '3', $this->pan->id => '6'])
            ->assertSessionHasErrors('cantidades.'.$this->linea($pedido, $this->galleta));
        $this->assertSame('12.0000', (string) $pedido->lineas()->where('producto_id', $this->galleta->id)->value('cantidad'));

        // Dejar cada producto en lo que ya salió: lo que falta no se trae y el pedido queda mandado.
        $this->corregir($pedido, [$this->galleta->id => '4', $this->pan->id => '6'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
        $this->corregir($pedido, [$this->galleta->id => '4', $this->pan->id => '0'])
            ->assertSessionHasErrors('cantidades.'.$this->linea($pedido, $this->pan));

        // Lo enviado no cambió.
        $this->assertSame(['4.0000'], $envio->lineas()->pluck('cantidad')->map(fn ($c) => (string) $c)->all());
        $this->assertTrue($envio->refresh()->estaVigente());
    }

    public function test_al_dejar_solo_lo_mandado_el_pedido_queda_mandado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '4']);

        $this->corregir($pedido, [$this->galleta->id => '4'])->assertSessionHasNoErrors();

        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
    }

    public function test_cantidades_con_las_mismas_reglas_que_al_sacar_el_pedido(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $clave = 'cantidades.'.$this->linea($pedido, $this->galleta);

        foreach (['100000', '1.23456', '-1', '1e3', 'diez', '0'] as $mala) {
            $this->corregir($pedido, [$this->galleta->id => $mala])->assertSessionHasErrors($clave);
        }
        $this->assertSame('12.0000', (string) $pedido->lineas()->value('cantidad'));
    }

    public function test_en_un_pedido_cerrado_cambia_la_sala_pero_no_las_cantidades(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12'], sigue: false);
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);

        $this->corregir($pedido, [$this->galleta->id => '13'])->assertSessionHasErrors('cantidades.'.$this->linea($pedido, $this->galleta));

        $this->corregir($pedido, [], ['cliente_sucursal_id' => $this->otraSala->id])->assertSessionHasNoErrors();
        $this->assertSame($this->otraSala->id, $pedido->refresh()->cliente_sucursal_id);
    }

    public function test_sin_motivo_o_sin_cambios_no_se_corrige(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);

        $this->corregir($pedido, [$this->galleta->id => '10'], ['motivo' => ' '])->assertSessionHasErrors('motivo');
        $this->corregir($pedido, [$this->galleta->id => '12'])->assertSessionHasErrors('pedido');

        $this->assertSame('12.0000', (string) $pedido->lineas()->value('cantidad'));
    }

    public function test_la_sala_nueva_pasa_las_reglas_de_sacar_pedido(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $inactiva = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala Cerrada', 'codigo' => '9003', 'activo' => false]);
        $bodega = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Bodega Inventada', 'codigo' => '9004']);
        Ajustes::guardarComoSistema('pedidos.salas_sin_pedidos', [(string) $bodega->id]);
        // La otra sala ya tiene su pedido de ese día.
        $this->pedido([$this->pan->id => '3'], $this->otraSala);

        $this->corregir($pedido, [], ['cliente_sucursal_id' => $inactiva->id])->assertSessionHasErrors('cliente_sucursal_id');
        $this->corregir($pedido, [], ['cliente_sucursal_id' => $bodega->id])->assertSessionHasErrors('cliente_sucursal_id');
        $this->corregir($pedido, [], ['cliente_sucursal_id' => $this->otraSala->id])->assertSessionHasErrors('cliente_sucursal_id');

        $this->assertSame($this->sala->id, $pedido->refresh()->cliente_sucursal_id);
    }

    public function test_una_version_vieja_no_pisa_lo_que_mando_la_pc_de_pedidos(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $vieja = $this->version($pedido);
        // Mientras la oficina miraba el formulario, la PC de pedidos mandó una parte.
        $this->mandar($pedido, [$this->galleta->id => '8']);

        $this->put(route('rutas.pedidos.corregir.guardar', $pedido), [
            'version' => $vieja, 'motivo' => 'Prueba', 'cantidades' => [$this->linea($pedido, $this->galleta) => '5'],
        ])->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);
        $this->patch(route('rutas.pedidos.anular.guardar', $pedido), ['version' => $vieja, 'motivo' => 'Prueba'])
            ->assertSessionHasErrors('pedido');

        $this->assertSame('12.0000', (string) $pedido->lineas()->value('cantidad'));
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
    }

    public function test_la_correccion_cambia_la_version_y_la_pc_de_pedidos_no_manda_con_la_vieja(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $cargado = PedidoSala::with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id);
        $versionFabrica = $cargado->versionProduccion();

        $this->corregir($pedido, [$this->galleta->id => '5'])->assertSessionHasNoErrors();

        $this->actingAs($this->fabrica)->post(route('fabrica.pedidos.mandar', $pedido), [
            'clave' => (string) Str::uuid(), 'version' => $versionFabrica, 'cantidades' => [$this->linea($pedido, $this->galleta) => '12'],
        ])->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);
        $this->assertSame(0, PedidoSalaEnvio::count());
    }

    // ══════════════════════════════ anular

    public function test_anula_con_motivo_y_queda_en_la_auditoria(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);

        $this->get(route('rutas.pedidos.anular', $pedido))->assertOk()->assertSee('Anular pedido');
        $this->anular($pedido)->assertSessionHasNoErrors()->assertRedirect(route('rutas.pedidos.show', $pedido));

        $this->assertSame(EstadoPedidoSala::Anulado, $pedido->refresh()->estado);
        $log = Activity::where('description', PedidosOficina::AUDITORIA_ANULA)->sole();
        $this->assertSame($this->oficina->id, $log->causer_id);
        $this->assertSame('La sala no pidió nada.', $log->properties['motivo']);
        $this->assertSame(EstadoPedidoSala::Registrado->value, $log->properties['estado_anterior']);
        $this->assertSame('12.0000', $log->properties['lineas'][0]['cantidad']);
        // Ya no está por mandar.
        $this->assertFalse(app(PedidosFabrica::class)->porMandar()->whereKey($pedido->id)->exists());
    }

    public function test_sin_motivo_no_se_anula(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);

        $this->anular($pedido, ['motivo' => ''])->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
    }

    public function test_con_un_envio_vigente_no_se_anula(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '4']);

        $this->anular($pedido)->assertSessionHasErrors(['pedido' => PedidosOficina::CON_ENVIO]);

        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()->assertDontSee(route('rutas.pedidos.anular', $pedido));
    }

    // ══════════════════════════════ facturado

    public function test_si_ya_se_facturo_ni_se_corrige_ni_se_anula(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $envio = $this->mandar($pedido, [$this->galleta->id => '4']);
        $this->facturar($envio, EstadoDte::Aceptado);

        $this->corregir($pedido, [$this->galleta->id => '4'], ['cliente_sucursal_id' => $this->otraSala->id])
            ->assertSessionHasErrors(['pedido' => PedidosOficina::FACTURADO]);
        $this->anular($pedido)->assertSessionHasErrors(['pedido' => PedidosOficina::FACTURADO]);

        $this->assertSame($this->sala->id, $pedido->refresh()->cliente_sucursal_id);
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertDontSee(route('rutas.pedidos.corregir', $pedido))->assertSee(PedidosOficina::FACTURADO);
    }

    public function test_con_un_ccf_en_borrador_tampoco(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $envio = $this->mandar($pedido, [$this->galleta->id => '12'], sigue: false);
        $this->facturar($envio, EstadoDte::Borrador);

        $this->corregir($pedido, [], ['cliente_sucursal_id' => $this->otraSala->id])
            ->assertSessionHasErrors(['pedido' => PedidosOficina::EN_BORRADOR]);

        $this->assertSame($this->sala->id, $pedido->refresh()->cliente_sucursal_id);
    }
}
