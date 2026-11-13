<?php

namespace Tests\Feature\Fabrica;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Enums\TipoDte;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Corregir» un envío de hoy desde «Hechos hoy» (12/11/2026): se anula (como «Deshacer»,
 * con los mismos límites) y el pedido se abre con lo que había ido ya marcado; el envío
 * nuevo imprime «Corrige al envío N.º X». Y «Salir» de la barra no pierde lo marcado.
 * Todos los datos son inventados.
 */
class CorregirEnvioTest extends TestCase
{
    use RefreshDatabase;

    private User $fabrica;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');
        config()->set('planta.enabled', false);

        $this->fabrica = User::factory()->create(['activo' => true, 'name' => 'Fábrica Inventada'])->assignRole('fabrica');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Inventada']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Súper Ficticio'])
            ->sucursales()->create(['nombre' => 'Súper Ficticio Centro', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
        $this->otraSala = Cliente::factory()->create(['nombre' => 'Tienda Inventada'])
            ->sucursales()->create(['nombre' => 'Tienda Inventada Norte', 'codigo' => '9002', 'ruta_id' => $this->ruta->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01']);

        $this->actingAs($this->fabrica);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(array $cantidades, ?ClienteSucursal $sala = null): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => ($sala ?? $this->sala)->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado,
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

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function mandar(PedidoSala $pedido, array $cantidades, array $extra = []): TestResponse
    {
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }
        $version = PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion();

        return $this->post(route('fabrica.pedidos.mandar', $pedido), $extra + [
            'clave' => (string) Str::uuid(), 'version' => $version, 'cantidades' => $porLinea,
        ]);
    }

    private function corregir(PedidoSala $pedido, PedidoSalaEnvio $envio): TestResponse
    {
        return $this->patch(route('fabrica.pedidos.corregir-envio', [$pedido, $envio]));
    }

    private function facturar(PedidoSalaEnvio $envio): void
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'ambiente' => '00', 'estado' => EstadoDte::Borrador->value,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $this->sala->cliente_id,
            'fecha_emision' => '2026-11-09', 'hora_emision' => '08:00:00',
        ]);
        $dte->save();
        $envio->update(['dte_id' => $dte->id]);
    }

    // ══════════════════════════════ el botón

    public function test_hechos_hoy_tiene_corregir_solo_en_los_envios_que_se_pueden_corregir(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $otro = $this->pedido([$this->canilla->id => '6'], $this->otraSala);
        $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $this->mandar($otro, [$this->canilla->id => '6'])->assertSessionHasNoErrors();
        $envio = $pedido->envios()->sole();
        $delOtro = $otro->envios()->sole();
        $hechos = route('fabrica.pedidos.index', ['ver' => 'hechos']);

        $this->get($hechos)->assertOk()
            ->assertSee('action="'.route('fabrica.pedidos.corregir-envio', [$pedido, $envio]).'"', false)
            ->assertSee('action="'.route('fabrica.pedidos.corregir-envio', [$otro, $delOtro]).'"', false)
            ->assertSee('Corregir</button>', false);

        // Facturado o deshecho: sin «Corregir».
        $this->facturar($envio);
        $this->patch(route('fabrica.pedidos.anular-envio', [$otro, $delOtro]))->assertSessionHasNoErrors();
        $this->get($hechos)->assertOk()
            ->assertDontSee('Corregir</button>', false)
            ->assertSee(PedidosFabrica::FACTURADO);
    }

    // ══════════════════════════════ corregir

    public function test_corregir_anula_el_envio_y_abre_el_pedido_con_lo_que_habia_ido_ya_marcado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '6']);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);
        $this->mandar($pedido, [$this->galleta->id => '4', $this->canilla->id => '6'], ['motivos' => [$g => 'Faltó masa'], 'sigue' => '1'])
            ->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();

        $this->corregir($pedido, $envio)->assertSessionHasNoErrors()
            ->assertRedirect(route('fabrica.pedidos.show', $pedido))
            ->assertSessionHas('status', 'Envío 1 anulado para corregirlo. Cambiá lo que haga falta y tocá «Imprimir y mandar».');

        // Anulado, nunca borrado; el pedido vuelve a estar por mandar y armándose.
        $this->assertSame(1, PedidoSalaEnvio::count());
        $this->assertNotNull($envio->refresh()->anulado_en);
        $this->assertSame($this->fabrica->id, $envio->anulado_por);
        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $this->assertNotNull($pedido->armando_desde);
        $this->assertTrue($pedido->armando_sigue);

        // Lo que había ido, ya marcado, con su motivo.
        $lineas = $pedido->lineas()->get()->keyBy('id');
        $this->assertSame('4.0000', (string) $lineas[$g]->va_borrador);
        $this->assertSame('Faltó masa', $lineas[$g]->motivo_borrador);
        $this->assertSame('6.0000', (string) $lineas[$c]->va_borrador);
        $this->assertNull($lineas[$c]->motivo_borrador);

        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('Envío 1 anulado para corregirlo.')
            ->assertSee('id="va-'.$g.'" name="cantidades['.$g.']" x-model="va" value="4"', false)
            ->assertSee('value="Faltó masa"', false)
            ->assertSee('name="sigue" value="1" checked', false);

        // La auditoría: la anulación de siempre y la corrección, con lo que quedó marcado.
        $anulo = Activity::where('description', 'anuló un envío del pedido')->sole();
        $this->assertSame(1, $anulo->properties['numero']);
        $corrigio = Activity::where('description', PedidosFabrica::AUDITORIA_CORRIGE)->sole();
        $this->assertSame($pedido->id, $corrigio->subject_id);
        $this->assertSame($this->fabrica->id, $corrigio->causer_id);
        $this->assertSame($envio->id, $corrigio->properties['envio_id']);
        $this->assertSame(1, $corrigio->properties['numero']);
        $this->assertEqualsCanonicalizing(
            [['pedido_sala_linea_id' => $g, 'va_borrador' => '4.0000'], ['pedido_sala_linea_id' => $c, 'va_borrador' => '6.0000']],
            $corrigio->properties['lineas'],
        );
    }

    public function test_corregir_un_envio_cerrado_con_faltantes_lo_deja_sin_la_casilla(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '6']);
        $c = $this->linea($pedido, $this->canilla);
        $this->mandar($pedido, [$this->galleta->id => '10'], ['motivos' => [$c => 'No hubo horno']])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);

        $this->corregir($pedido, PedidoSalaEnvio::sole())->assertSessionHasNoErrors();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $this->assertFalse($pedido->armando_sigue);
        $linea = $pedido->lineas()->whereKey($c)->sole();
        $this->assertSame('0.0000', (string) $linea->va_borrador);
        $this->assertSame('No hubo horno', $linea->motivo_borrador);
    }

    public function test_el_envio_nuevo_dice_que_corrige_al_anulado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->mandar($pedido, [$this->galleta->id => '4'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $primero = PedidoSalaEnvio::sole();
        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $primero]))->assertOk()->assertDontSee('Corrige al envío');

        $this->corregir($pedido, $primero)->assertSessionHasNoErrors();
        $this->mandar($pedido, [$this->galleta->id => '5'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $segundo = $pedido->envios()->where('numero', 2)->sole();

        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $segundo]))->assertOk()
            ->assertSeeInOrder(['Corrige al envío N.º 1', 'Lo que va', 'Galleta de prueba', '5']);
        $mando = Activity::where('description', PedidosFabrica::AUDITORIA_PARTE)->where('properties->envio_id', $segundo->id)->sole();
        $this->assertSame($primero->id, $mando->properties['corrige_envio_id']);
        $this->assertSame(1, $mando->properties['corrige_numero']);

        // La hoja del anulado ya no se imprime; el envío siguiente, sin corrección, no lo dice.
        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $primero]))->assertNotFound();
        $this->mandar($pedido, [$this->galleta->id => '5'])->assertSessionHasNoErrors();
        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $pedido->envios()->where('numero', 3)->sole()]))->assertOk()
            ->assertDontSee('Corrige al envío');
    }

    public function test_borrar_lo_marcado_despues_de_corregir_corta_la_correccion(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->mandar($pedido, [$this->galleta->id => '4'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->corregir($pedido, PedidoSalaEnvio::sole())->assertSessionHasNoErrors();

        $this->patch(route('fabrica.pedidos.borrador.borrar', $pedido))->assertSessionHasNoErrors();
        $this->mandar($pedido, [$this->galleta->id => '5'], ['sigue' => '1'])->assertSessionHasNoErrors();

        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $pedido->envios()->where('numero', 2)->sole()]))->assertOk()
            ->assertDontSee('Corrige al envío');
    }

    /** Decidido: lo que se mande ya no reemplaza solo al corregido, así que no lo dice. */
    public function test_deshacer_otro_envio_despues_de_corregir_corta_la_correccion(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '6']);
        $this->mandar($pedido, [$this->galleta->id => '4'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->mandar($pedido, [$this->canilla->id => '6'], ['sigue' => '1'])->assertSessionHasNoErrors();
        [$primero, $segundo] = $pedido->envios()->orderBy('numero')->get()->all();

        $this->corregir($pedido, $primero)->assertSessionHasNoErrors();
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $segundo]))->assertSessionHasNoErrors();
        $this->mandar($pedido, [$this->galleta->id => '5', $this->canilla->id => '6'], ['sigue' => '1'])->assertSessionHasNoErrors();

        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $pedido->envios()->where('numero', 3)->sole()]))->assertOk()
            ->assertDontSee('Corrige al envío');
    }

    public function test_un_doble_toque_no_anula_dos_veces_ni_pisa_lo_marcado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $g = $this->linea($pedido, $this->galleta);
        $this->mandar($pedido, [$this->galleta->id => '4'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();

        $this->corregir($pedido, $envio)->assertRedirect(route('fabrica.pedidos.show', $pedido));
        // Entre un toque y otro ya cambió algo (el guardado automático).
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '3']])->assertOk();
        $this->corregir($pedido, $envio)->assertSessionHasNoErrors()
            ->assertRedirect(route('fabrica.pedidos.show', $pedido))->assertSessionMissing('status');

        $this->assertSame(1, Activity::where('description', 'anuló un envío del pedido')->count());
        $this->assertSame(1, Activity::where('description', PedidosFabrica::AUDITORIA_CORRIGE)->count());
        $this->assertSame('3.0000', (string) $pedido->lineas()->whereKey($g)->value('va_borrador'));
        $this->assertSame(1, PedidoSalaEnvio::count());
    }

    // ══════════════════════════════ límites

    public function test_un_envio_facturado_no_se_corrige(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();
        $this->facturar($envio);

        $this->corregir($pedido, $envio)->assertSessionHasErrors(['envio' => PedidosFabrica::FACTURADO]);

        $this->assertTrue($envio->refresh()->estaVigente());
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertNull($pedido->lineas()->value('va_borrador'));
        $this->assertFalse(Activity::where('description', PedidosFabrica::AUDITORIA_CORRIGE)->exists());
    }

    public function test_un_envio_de_otro_dia_no_se_corrige(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();

        $this->travelTo('2026-11-10 08:00:00');
        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertOk()->assertDontSee('Corregir</button>', false);
        $this->corregir($pedido, $envio)->assertForbidden();

        $this->assertTrue($envio->refresh()->estaVigente());
        $this->assertNull($pedido->lineas()->value('va_borrador'));
        $this->assertFalse(Activity::where('description', PedidosFabrica::AUDITORIA_CORRIGE)->exists());
    }

    public function test_sin_permiso_403_e_invitado_al_login(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();

        foreach (['vendedor', 'jefe_vendedores', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol));
            $this->corregir($pedido, $envio)->assertForbidden();
        }
        auth()->logout();
        $this->corregir($pedido, $envio)->assertRedirect(route('login'));

        // Sin CSRF no hay GET que corrija.
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.corregir-envio', [$pedido, $envio]))->assertStatus(405);
        $this->assertTrue($envio->refresh()->estaVigente());
    }

    public function test_un_envio_de_otro_pedido_da_404(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $otro = $this->pedido([$this->galleta->id => '3'], $this->otraSala);
        $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();

        $this->corregir($otro, $envio)->assertNotFound();
        $this->assertTrue($envio->refresh()->estaVigente());
    }

    // ══════════════════════════════ «Salir» y cerrar la pestaña

    public function test_salir_y_cerrar_la_pestana_esperan_lo_marcado(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);

        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            // «Salir» de la barra: la hoja lo intercepta y cierra sesión solo con todo guardado.
            ->assertSee('data-salir', false)
            ->assertSee('@submit.window.capture="cerrarSesion($event)"', false)
            // Cerrar o recargar la pestaña con algo sin guardar o en vuelo: el navegador pregunta.
            ->assertSee('@beforeunload.window="avisarSiFalta($event)"', false)
            ->getContent();
        $this->assertStringContainsString('return this.sucio || this.guardando;', $html);
        // Si no se pudo guardar, «Salir» no queda trabado: pregunta si sale igual.
        $this->assertStringContainsString("confirm('No se pudo guardar lo marcado. ¿Salir igual y perderlo?')", $html);

        // La lista (sin hoja) cierra sesión como siempre.
        $this->get(route('fabrica.pedidos.index'))->assertOk()->assertDontSee('cerrarSesion', false);
    }
}
