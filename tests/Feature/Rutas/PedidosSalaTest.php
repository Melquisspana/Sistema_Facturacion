<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Sacar pedido en la sala desde el celular (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10): el
 * propósito de la salida, el «Sugerido» de cada sala (sin foto), corregir y anular el mismo
 * día, la lista y el detalle de la oficina y la OC que se agrega después desde ahí. Todos
 * los datos son inventados.
 */
class PedidosSalaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $companero;

    private User $oficina;

    private PersonalRuta $persona;

    private PersonalRuta $otraPersona;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    private ClienteSucursal $salaOc;

    private Producto $galleta;

    private Producto $pan;

    private SalidaRuta $salida;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null, 'dte.ambiente' => '01']);
        $this->travelTo('2026-11-05 09:00:00');

        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->companero = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->vendedor->id]);
        $this->otraPersona = PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $this->companero->id]);

        $this->ruta = Ruta::create(['nombre' => 'Santa Ana']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Tienda Inventada'])
            ->sucursales()->create(['nombre' => 'Tienda Centro', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
        $this->salaOc = Cliente::factory()->create(['nombre' => 'Súper Ficticio', 'requiere_orden_compra' => true])
            ->sucursales()->create(['nombre' => 'Súper Ficticio Norte', 'codigo' => '0236', 'ruta_id' => $this->ruta->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->pan = Producto::factory()->create(['nombre' => 'Pan de prueba', 'codigo' => 'P01']);

        $this->salida = $this->salidaCon(PropositoSalida::Ambos);
        $this->actingAs($this->vendedor);
    }

    private function salidaCon(PropositoSalida $proposito, EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-05', 'estado' => $estado, 'proposito' => $proposito]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->persona->id, $this->otraPersona->id], null);

        return $salida;
    }

    /** @param  array<string, mixed>  $extra */
    private function sacar(array $extra = [], ?ClienteSucursal $sala = null, ?SalidaRuta $salida = null): TestResponse
    {
        return $this->post(route('rutas.mi-salida.pedidos.store', [$salida ?? $this->salida, $sala ?? $this->sala]), $extra + [
            'cantidades' => [$this->galleta->id => '12', $this->pan->id => ''],
        ]);
    }

    private function ccf(ClienteSucursal $sala): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa Inventada', 'ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-000000000000001', 'fecha_emision' => '2026-11-04', 'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    // ══════════════════════════════ permisos y propósito

    public function test_permisos_por_rol(): void
    {
        $this->assertSame(['rutas.entregas.registrar', 'rutas.pedidos.registrar'], RolSistema::Vendedor->permisos());
        $this->assertContains('rutas.pedidos.registrar', RolSistema::JefeVendedores->permisos());
        $this->assertContains('rutas.pedidos.ver', RolSistema::JefeVendedores->permisos());
        foreach ([RolSistema::Jefatura, RolSistema::Facturacion] as $rol) {
            $this->assertContains('rutas.pedidos.ver', $rol->permisos());
            $this->assertNotContains('rutas.pedidos.registrar', $rol->permisos());
        }
        foreach ([RolSistema::Contabilidad, RolSistema::Produccion] as $rol) {
            $this->assertNotContains('rutas.pedidos.ver', $rol->permisos());
            $this->assertNotContains('rutas.pedidos.registrar', $rol->permisos());
        }
        $this->assertContains('rutas.pedidos.ver', RolSistema::Administrador->permisos());
        $this->assertContains('rutas.pedidos.registrar', RolSistema::Administrador->permisos());
    }

    public function test_las_salidas_que_ya_existen_quedan_para_entregar_sin_cambios(): void
    {
        $id = DB::table('salidas_ruta')->insertGetId(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-05', 'estado' => 'en_curso', 'created_at' => now(), 'updated_at' => now()]);
        $vieja = SalidaRuta::findOrFail($id);
        $this->assertSame(PropositoSalida::Entregar, $vieja->proposito);
        $this->assertSame(PropositoSalida::Entregar, SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-05', 'estado' => EstadoSalidaRuta::EnCurso])->proposito);

        app(ParticipantesSalida::class)->sincronizar($vieja, [$this->persona->id], null);
        $this->get(route('rutas.mi-salida.show', $vieja))->assertOk()->assertSee('Para entregar')->assertDontSee('Sacar pedido');
        $this->get(route('rutas.mi-salida.pedidos.create', [$vieja, $this->sala]))->assertForbidden();
        $this->sacar([], null, $vieja)->assertForbidden();
        $this->assertSame(0, PedidoSala::count());
    }

    public function test_armar_salida_elige_el_proposito_y_la_de_pedido_no_lleva_ccf(): void
    {
        $this->ccf($this->sala);
        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->actingAs($jefe)->get(route('rutas.salidas.create'))->assertOk()
            ->assertSee('¿Para qué sale?')->assertSee('Sacar pedido')->assertSee('Las dos');

        $datos = ['ruta_id' => $this->ruta->id, 'dia' => 'hoy', 'personal' => [$this->persona->id]];
        $this->post(route('rutas.salidas.store'), $datos + ['proposito' => 'pedido'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Salida en camino para sacar pedido.');
        $pedido = SalidaRuta::latest('id')->first();
        $this->assertSame(PropositoSalida::Pedido, $pedido->proposito);
        $this->assertSame(0, $pedido->entregas()->count());
        $this->assertSame(0, app(EntregasCcf::class)->cargarNuevosEnCurso($pedido));
        $this->get(route('rutas.salidas.show', $pedido))->assertOk()->assertSee('Para sacar pedido')->assertDontSee('Esta salida no lleva CCF');

        // La de entregar se lleva el CCF: la de pedido no lo había tomado.
        $this->post(route('rutas.salidas.store'), $datos)->assertSessionHas('status', 'Salida en camino con 1 CCF por entregar.');
        $this->assertSame(PropositoSalida::Entregar, SalidaRuta::latest('id')->first()->proposito);

        $this->post(route('rutas.salidas.store'), $datos + ['proposito' => 'repartir'])->assertSessionHasErrors('proposito');
    }

    // ══════════════════════════════ sacar pedido

    public function test_mi_salida_muestra_sacar_pedido_y_el_formulario_del_sugerido(): void
    {
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertOk()
            ->assertSee('Para entregar y sacar pedido')
            ->assertSee(route('rutas.mi-salida.pedidos.create', [$this->salida, $this->sala]))
            ->assertSee('Tienda Centro');

        Producto::factory()->create(['nombre' => 'Producto apagado', 'activo' => false]);
        $this->get(route('rutas.mi-salida.pedidos.create', [$this->salida, $this->sala]))->assertOk()
            ->assertSee('Sugerido')->assertDontSee('type="file"', false)->assertDontSee('multipart/form-data', false)
            ->assertSee('Galleta de prueba')->assertDontSee('Producto apagado')
            // Esta sala no usa OC.
            ->assertDontSee('Número de orden de compra');
        $this->get(route('rutas.mi-salida.pedidos.create', [$this->salida, $this->salaOc]))->assertOk()
            ->assertSee('Número de orden de compra')
            ->assertSeeText('Si ya la tenés, son de 13 a 15 números.')
            ->assertDontSeeText('14 dígitos');
    }

    public function test_guarda_el_pedido_con_auditoria(): void
    {
        $this->sacar(['notas' => ' Pidió para el viernes '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('rutas.mi-salida.show', $this->salida).'#sala-'.$this->sala->id)
            ->assertSessionHas('status', 'Pedido de «Tienda Centro» guardado.');

        $pedido = PedidoSala::sole();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $this->assertSame($this->persona->id, $pedido->sacado_por_id);
        $this->assertSame($this->vendedor->id, $pedido->registrado_por);
        $this->assertSame($this->ruta->id, $pedido->ruta_id);
        $this->assertSame('2026-11-05', $pedido->fecha->toDateString());
        $this->assertSame('Pidió para el viernes', $pedido->notas);
        $this->assertSame([[$this->galleta->id, '12.0000']], $pedido->lineas->map(fn ($l) => [$l->producto_id, $l->cantidad])->all());

        $actividad = Activity::where('log_name', 'pedido_sala')->sole();
        $this->assertSame('sacó el pedido de la sala', $actividad->description);
        $this->assertSame($this->galleta->id, $actividad->properties['lineas'][0]['producto_id']);

        $this->get(route('rutas.mi-salida.show', $this->salida))->assertSee('Pedido sacado')->assertSee('Galleta de prueba')
            ->assertSee('Anular');

        // Una sola vez por sala: el segundo intento no crea otro.
        $this->sacar()->assertSessionHasErrors('lineas');
        $this->get(route('rutas.mi-salida.pedidos.create', [$this->salida, $this->sala]))
            ->assertRedirect(route('rutas.mi-salida.pedidos.edit', [$this->salida, $pedido]));
        $this->assertSame(1, PedidoSala::count());
    }

    public function test_validaciones_del_servidor(): void
    {
        $inactivo = Producto::factory()->create(['activo' => false]);
        $casos = [
            [['cantidades' => [$this->galleta->id => '', $this->pan->id => '']], 'lineas'],
            [['cantidades' => []], 'lineas'],
            [['cantidades' => [$this->galleta->id => '0']], 'lineas.0.cantidad'],
            [['cantidades' => [$this->galleta->id => '-3']], 'lineas.0.cantidad'],
            [['cantidades' => [$this->galleta->id => 'doce']], 'lineas.0.cantidad'],
            [['cantidades' => [$this->galleta->id => '100000']], 'lineas.0.cantidad'],
            [['cantidades' => [$inactivo->id => '2']], 'lineas.0.producto_id'],
            [['cantidades' => [999999 => '2']], 'lineas.0.producto_id'],
            [['notas' => str_repeat('a', 501)], 'notas'],
        ];
        foreach ($casos as [$datos, $campo]) {
            $this->sacar($datos)->assertSessionHasErrors($campo);
        }
        $this->sacar(['cantidades' => [$this->galleta->id => '0']])
            ->assertSessionHasErrors(['lineas.0.cantidad' => 'Cada cantidad tiene que ser mayor que cero.']);
        $this->sacar(['cantidades' => []])->assertSessionHasErrors(['lineas' => 'Anotá al menos un producto con su cantidad.']);
        $this->assertSame(0, PedidoSala::count());

        // Con decimales: se guarda.
        $this->sacar(['cantidades' => [$this->pan->id => '2.5']])->assertSessionHasNoErrors();
        $this->assertSame('2.5000', PedidoSala::sole()->lineas->sole()->cantidad);
    }

    public function test_la_sala_tiene_que_ser_de_la_salida(): void
    {
        $ajena = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala de otra ruta', 'ruta_id' => Ruta::create(['nombre' => 'Otra'])->id]);
        $this->get(route('rutas.mi-salida.pedidos.create', [$this->salida, $ajena]))->assertNotFound();
        $this->sacar([], $ajena)->assertNotFound();

        $inactiva = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala cerrada', 'ruta_id' => $this->ruta->id, 'activo' => false]);
        $this->sacar([], $inactiva)->assertNotFound();
        $this->assertSame(0, PedidoSala::count());

        // La de otra ruta que lleva un CCF en esta salida sí es de la salida.
        $this->salida->entregas()->create(['dte_id' => $this->ccf($ajena)->id, 'cliente_sucursal_id' => $ajena->id]);
        $this->sacar([], $ajena)->assertSessionHasNoErrors();
        $this->assertSame($ajena->id, PedidoSala::sole()->cliente_sucursal_id);
    }

    public function test_solo_quien_va_en_la_salida_en_curso_y_con_permiso(): void
    {
        $ajeno = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Vendedor Ajeno', 'user_id' => $ajeno->id]);
        $this->actingAs($ajeno);
        $this->get(route('rutas.mi-salida.pedidos.create', [$this->salida, $this->sala]))->assertForbidden();
        $this->sacar()->assertForbidden();

        // La oficina no saca pedidos: no tiene el permiso de registrar.
        $this->actingAs($this->oficina);
        $this->sacar()->assertForbidden();

        // Salida planificada, terminada o solo para entregar: no.
        $this->actingAs($this->vendedor);
        foreach ([EstadoSalidaRuta::Planificada, EstadoSalidaRuta::Finalizada, EstadoSalidaRuta::Cancelada] as $estado) {
            $this->salida->update(['estado' => $estado]);
            $this->sacar()->assertForbidden();
        }
        $this->salida->update(['estado' => EstadoSalidaRuta::EnCurso, 'proposito' => PropositoSalida::Entregar]);
        $this->sacar()->assertForbidden();
        $this->assertSame(0, PedidoSala::count());

        // Solo para sacar pedido: sí.
        $this->salida->update(['proposito' => PropositoSalida::Pedido]);
        $this->sacar()->assertSessionHasNoErrors();
        $this->assertSame(1, PedidoSala::count());

        auth()->logout();
        $this->sacar()->assertRedirect(route('login'));
    }

    // ══════════════════════════════ corregir y anular

    public function test_corrige_el_mismo_dia(): void
    {
        $this->sacar()->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $this->get(route('rutas.mi-salida.pedidos.edit', [$this->salida, $pedido]))->assertOk()
            ->assertSee('Guardar cambios')->assertSee('value="12"', false);

        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), [
            'cantidades' => [$this->pan->id => '4'], 'notas' => 'Cambió de idea',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Pedido de «Tienda Centro» corregido.');
        $pedido->refresh();
        $this->assertSame([$this->pan->id], $pedido->lineas->pluck('producto_id')->all());
        $this->assertSame('Cambió de idea', $pedido->notas);
        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), ['cantidades' => [$this->pan->id => '6']])->assertSessionHasNoErrors();
        $this->assertNull($pedido->refresh()->notas);
        $this->assertSame(2, Activity::where('description', 'corrigió el pedido de la sala')->count());

        // El compañero no lo corrige ni lo anula.
        $this->actingAs($this->companero);
        $this->get(route('rutas.mi-salida.pedidos.edit', [$this->salida, $pedido]))->assertForbidden();
        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), ['cantidades' => [$this->pan->id => '1']])->assertForbidden();
        $this->patch(route('rutas.mi-salida.pedidos.anular', [$this->salida, $pedido]))->assertForbidden();
        $this->get(route('rutas.mi-salida.pedidos.create', [$this->salida, $this->sala]))->assertRedirect()->assertSessionHas('error');

        // Al día siguiente ya no se corrige.
        $this->actingAs($this->vendedor);
        $this->travelTo('2026-11-06 09:00:00');
        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), ['cantidades' => [$this->pan->id => '1']])->assertForbidden();
        $this->patch(route('rutas.mi-salida.pedidos.anular', [$this->salida, $pedido]))->assertForbidden();
        $this->assertSame('6.0000', $pedido->refresh()->lineas->sole()->cantidad);
    }

    public function test_anular_lo_conserva_y_deja_sacar_de_nuevo(): void
    {
        $this->sacar()->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $this->patch(route('rutas.mi-salida.pedidos.anular', [$this->salida, $pedido]))->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'anulado'));
        $this->assertSame(EstadoPedidoSala::Anulado, $pedido->refresh()->estado);
        $this->assertSame(1, $pedido->lineas()->count());
        $this->assertTrue(Activity::where('description', 'anuló el pedido de la sala')->exists());

        // Anulado ya no se corrige ni se vuelve a anular, pero la sala se puede sacar de nuevo.
        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), ['cantidades' => [$this->pan->id => '1']])->assertForbidden();
        $this->patch(route('rutas.mi-salida.pedidos.anular', [$this->salida, $pedido]))->assertForbidden();
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertDontSee('Pedido sacado');
        $this->sacar()->assertSessionHasNoErrors();
        $this->assertSame(2, PedidoSala::count());

        // El pedido de otra salida no se toca por esta.
        $otra = $this->salidaCon(PropositoSalida::Pedido);
        $this->patch(route('rutas.mi-salida.pedidos.anular', [$otra, PedidoSala::latest('id')->first()]))->assertNotFound();
    }

    // ══════════════════════════════ OC

    public function test_la_oc_es_opcional_y_su_forma_solo_avisa(): void
    {
        $this->sacar([], $this->salaOc)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pedido de «Súper Ficticio Norte» guardado.');
        $this->assertNull(PedidoSala::sole()->numero_orden_compra);
        $this->assertSame([], PedidoSala::sole()->avisosOrdenCompra());
        PedidoSala::query()->delete();

        $this->sacar(['numero_orden_compra' => ' 261102360045 '], $this->salaOc)->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'tiene 12 dígitos y deben ser de 13 a 15'));
        $pedido = PedidoSala::sole();
        $this->assertSame('261102360045', $pedido->numero_orden_compra);
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertSee('tiene 12 dígitos y deben ser de 13 a 15');

        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), [
            'cantidades' => [$this->galleta->id => '12'], 'numero_orden_compra' => '26080236004586',
        ])->assertSessionHas('status', fn ($m) => str_contains($m, 'es de 08/2026 y el pedido es del 05/11/2026'));

        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), [
            'cantidades' => [$this->galleta->id => '12'], 'numero_orden_compra' => '26110236004586',
        ])->assertSessionHas('status', 'Pedido de «Súper Ficticio Norte» corregido.');
        $this->assertSame([], $pedido->refresh()->avisosOrdenCompra());
    }

    public function test_la_oficina_agrega_la_oc_despues_con_aviso_y_auditoria(): void
    {
        $this->sacar([], $this->salaOc)->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();

        $this->actingAs($this->oficina);
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()->assertSee('Todavía sin OC')->assertSee('Agregar la OC')
            ->assertSeeText('De 13 a 15 números, del portal EDI.')->assertDontSeeText('14 dígitos');
        $this->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => '261102360045'])
            ->assertRedirect(route('rutas.pedidos.show', $pedido))
            ->assertSessionHas('status', fn ($m) => str_starts_with($m, 'Orden de compra guardada.') && str_contains($m, '12 dígitos'));
        $this->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => '26110236004586'])
            ->assertSessionHas('status', 'Orden de compra guardada.');
        $this->assertSame('26110236004586', $pedido->refresh()->numero_orden_compra);
        $this->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => str_repeat('1', 31)])->assertSessionHasErrors('numero_orden_compra');

        $cambios = Activity::where('description', 'cambió la orden de compra del pedido')->orderBy('id')->get();
        $this->assertCount(2, $cambios);
        $this->assertNull($cambios[0]->properties['anterior']);
        $this->assertSame('26110236004586', $cambios[1]->properties['nueva']);
        $this->assertSame($this->oficina->id, $cambios[1]->causer_id);

        // El jefe de vendedores y el vendedor no la cambian (no son la oficina).
        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->actingAs($jefe)->get(route('rutas.pedidos.show', $pedido))->assertOk()->assertDontSee('Corregir la OC');
        $this->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => '1'])->assertForbidden();
        $this->actingAs($this->vendedor)->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => '1'])->assertForbidden();

        // Anulado: ya no se toca.
        $pedido->update(['estado' => EstadoPedidoSala::Anulado]);
        $this->actingAs($this->oficina)->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => '26110236004587'])->assertForbidden();
        $this->assertSame('26110236004586', $pedido->refresh()->numero_orden_compra);
    }

    // ══════════════════════════════ oficina

    public function test_la_oficina_ve_la_lista_con_filtros_y_el_detalle(): void
    {
        $this->sacar()->assertSessionHasNoErrors();
        $this->sacar([], $this->salaOc)->assertSessionHasNoErrors();
        $primero = PedidoSala::orderBy('id')->firstOrFail();
        $otraRuta = Ruta::create(['nombre' => 'San Miguel']);

        $this->actingAs($this->oficina);
        $this->get(route('rutas.pedidos.index'))->assertOk()
            ->assertSee('Tienda Centro')->assertSee('Súper Ficticio Norte')->assertDontSee('Falta la foto');
        $this->get(route('rutas.pedidos.index', ['sala' => 'Norte']))->assertOk()->assertSee('Súper Ficticio Norte')->assertDontSee('Tienda Centro');
        $this->get(route('rutas.pedidos.index', ['ruta_id' => $otraRuta->id]))->assertOk()->assertSee('Nada coincide con estos filtros.');
        $this->get(route('rutas.pedidos.index', ['desde' => '2026-11-06']))->assertOk()->assertSee('Nada coincide con estos filtros.');
        $this->get(route('rutas.pedidos.index', ['desde' => '2026-11-05', 'hasta' => '2026-11-05']))->assertOk()->assertSee('Tienda Centro');
        $this->get(route('rutas.pedidos.index', ['salida' => $this->salida->id]))->assertOk()->assertSee('Tienda Centro');
        $this->get(route('rutas.pedidos.index', ['desde' => 'ayer']))->assertSessionHasErrors('desde');

        $this->get(route('rutas.pedidos.show', $primero))->assertOk()
            ->assertSee('Pedido de Tienda Centro')->assertSee('Galleta de prueba')->assertSee('Carlos Inventado')->assertDontSee('Falta la foto');
        $this->get(route('rutas.salidas.show', $this->salida))->assertOk()->assertSee('2 pedidos sacados');

        // El jefe de vendedores también mira; el vendedor, contabilidad y producción no.
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('jefe_vendedores'))
            ->get(route('rutas.pedidos.index'))->assertOk();
        foreach (['vendedor', 'contabilidad', 'produccion'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol));
            $this->get(route('rutas.pedidos.index'))->assertForbidden();
            $this->get(route('rutas.pedidos.show', $primero))->assertForbidden();
        }
    }

    // ══════════════════════════════ vendedor contra fábrica

    /** Un envío de la fábrica, directo en la base (la pantalla tiene sus propias pruebas). */
    private function envioDeFabrica(PedidoSala $pedido, string $cantidad = '12'): PedidoSalaEnvio
    {
        $envio = $pedido->envios()->create(['numero' => $pedido->envios()->count() + 1, 'clave' => (string) Str::uuid(), 'fecha' => '2026-11-05', 'registrado_por' => $this->oficina->id]);
        $envio->lineas()->create(['pedido_sala_linea_id' => $pedido->lineas()->first()->id, 'cantidad' => $cantidad]);

        return $envio;
    }

    public function test_cuando_la_fabrica_empezo_el_vendedor_ya_no_corrige_ni_anula(): void
    {
        $this->sacar()->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $envio = $this->envioDeFabrica($pedido);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);
        $lineas = $pedido->lineas()->pluck('id')->all();
        $error = 'La fábrica ya marcó este pedido; ya no se puede corregir. Avisá a la oficina.';

        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), ['cantidades' => [$this->pan->id => '4']])
            ->assertSessionHasErrors(['lineas' => $error]);
        $this->patch(route('rutas.mi-salida.pedidos.anular', [$this->salida, $pedido]))->assertSessionHasErrors(['lineas' => $error]);
        $this->assertSame($lineas, $pedido->lineas()->pluck('id')->all());
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);

        // Con la pantalla vieja o desde la sala: el aviso, no un 403.
        $aviso = 'El pedido de «Tienda Centro» ya está en la fábrica. Si cambió algo, avisá a la oficina.';
        $this->get(route('rutas.mi-salida.pedidos.edit', [$this->salida, $pedido]))->assertRedirect()->assertSessionHas('error', $aviso);
        $this->get(route('rutas.mi-salida.pedidos.create', [$this->salida, $this->sala]))->assertRedirect()->assertSessionHas('error', $aviso);
        // Un vendedor que no va en esa salida no se entera de qué sala tiene pedido: 403.
        $ajeno = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Vendedor Ajeno', 'user_id' => $ajeno->id]);
        $this->actingAs($ajeno)->get(route('rutas.mi-salida.pedidos.edit', [$this->salida, $pedido]))
            ->assertForbidden();
        $this->actingAs($this->vendedor);
        // Sigue «Pedido sacado», sin «Corregir», y no se saca otro en la misma salida.
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertOk()->assertSee('Pedido sacado')->assertDontSee('Corregir');
        $this->sacar()->assertSessionHasErrors('lineas');
        $this->assertSame(1, PedidoSala::count());

        // También con el envío anulado (cuenta igual) y con «No se trae» o el armado empezado.
        $envio->update(['anulado_en' => now(), 'anulado_por' => $this->oficina->id]);
        $pedido->update(['estado' => EstadoPedidoSala::Registrado]);
        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $pedido]), ['cantidades' => [$this->pan->id => '4']])->assertSessionHasErrors(['lineas' => $error]);

        $this->sacar([], $this->salaOc)->assertSessionHasNoErrors();
        $otro = PedidoSala::where('cliente_sucursal_id', $this->salaOc->id)->sole();
        $otro->update(['estado' => EstadoPedidoSala::NoSeTrae]);
        $this->patch(route('rutas.mi-salida.pedidos.anular', [$this->salida, $otro]))->assertSessionHasErrors(['lineas' => $error]);
        $otro->update(['estado' => EstadoPedidoSala::Registrado, 'armando_desde' => now(), 'armando_por' => $this->oficina->id]);
        $this->put(route('rutas.mi-salida.pedidos.update', [$this->salida, $otro]), ['cantidades' => [$this->pan->id => '4']])->assertSessionHasErrors(['lineas' => $error]);
        $this->assertSame(EstadoPedidoSala::Registrado, $otro->refresh()->estado);
    }

    public function test_un_pedido_que_la_fabrica_marco_sigue_contando_como_sacado(): void
    {
        $this->sacar()->assertSessionHasNoErrors();
        $this->sacar([], $this->salaOc)->assertSessionHasNoErrors();
        PedidoSala::where('cliente_sucursal_id', $this->sala->id)->update(['estado' => EstadoPedidoSala::Parcial->value]);
        PedidoSala::where('cliente_sucursal_id', $this->salaOc->id)->update(['estado' => EstadoPedidoSala::NoSeTrae->value]);

        $this->sacar()->assertSessionHasErrors('lineas');
        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $this->salida))->assertOk()->assertSee('2 pedidos sacados');

        // Mi salida con varios pedidos no hace una consulta por pedido para «Corregir».
        $this->actingAs($this->vendedor);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertOk()->assertSee('Pedido sacado');
        DB::disableQueryLog();
        // Solo las que buscan envíos de un pedido; el ritmo de las salas también nombra la tabla
        // (deja afuera los CCF que salieron de un pedido) y es una sola consulta para todas.
        $this->assertSame(1, collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/[`"]pedido_sala_envios[`"]\.[`"]pedido_sala_id[`"]/', $q['query']))->count());
    }

    public function test_la_oc_se_agrega_en_un_pedido_mandado_pero_no_en_uno_anulado(): void
    {
        $this->sacar()->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $this->actingAs($this->oficina);
        foreach ([EstadoPedidoSala::Parcial, EstadoPedidoSala::Enviado, EstadoPedidoSala::NoSeTrae] as $estado) {
            $pedido->update(['estado' => $estado]);
            $this->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => '26110236004586'])->assertSessionHasNoErrors()
                ->assertSessionHas('status', 'Orden de compra guardada.');
        }
        $pedido->update(['estado' => EstadoPedidoSala::Anulado]);
        $this->patch(route('rutas.pedidos.orden-compra', $pedido), ['numero_orden_compra' => '1'])->assertForbidden();
    }

    public function test_la_oficina_filtra_por_lo_que_se_mando_un_dia_y_ve_los_envios(): void
    {
        $this->sacar()->assertSessionHasNoErrors();
        $this->sacar([], $this->salaOc)->assertSessionHasNoErrors();
        $pedido = PedidoSala::where('cliente_sucursal_id', $this->sala->id)->sole();
        $otro = PedidoSala::where('cliente_sucursal_id', $this->salaOc->id)->sole();
        $this->envioDeFabrica($pedido, '8');
        $this->envioDeFabrica($otro)->update(['anulado_en' => now(), 'anulado_por' => $this->oficina->id]);
        $pedido->lineas()->first()->update(['motivo_no_enviado' => 'No alcanzó']);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);

        $this->actingAs($this->oficina);
        $this->get(route('rutas.pedidos.index'))->assertOk(); // se lleva el aviso de «guardado» del vendedor
        $this->get(route('rutas.pedidos.index', ['mandado_el' => '2026-11-05']))->assertOk()
            ->assertSee('Tienda Centro')->assertDontSee('Súper Ficticio Norte')->assertSee('Mandado el');
        $this->get(route('rutas.pedidos.index', ['mandado_el' => '2026-11-06']))->assertSee('Nada coincide con estos filtros.');
        $this->get(route('rutas.pedidos.index', ['mandado_el' => 'hoy']))->assertSessionHasErrors('mandado_el');

        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertSeeInOrder(['Pidió', 'Mandado', 'Falta', 'Motivo', 'Galleta de prueba', '12', '8', '4', 'No alcanzó'])
            ->assertSee('Envío 1 · 05/11/2026');
        $this->get(route('rutas.pedidos.show', $otro))->assertOk()->assertSee('Anulado por')->assertSee('line-through', false);
    }
}
