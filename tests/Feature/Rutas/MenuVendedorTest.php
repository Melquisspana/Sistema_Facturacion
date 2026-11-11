<?php

namespace Tests\Feature\Rutas;

use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «Menú del vendedor» (Melqui, 11/11/2026): la entrada única de la oficina al celular del
 * vendedor. El administrador hace ahí lo mismo que un vendedor («yo tengo que poder hacer
 * todo»): su ficha de Rutas se arma sola la primera vez que entra. Jefatura y Facturación
 * siguen sin ficha: miran «Por entregar» y nada más. Datos ficticios.
 */
class MenuVendedorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ClienteSucursal $sala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes.
        $this->travelTo('2026-11-10 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-06-01', 'rutas.ambiente_ccf' => null]);
        $this->admin = User::factory()->create(['name' => 'Admin Ficticio', 'activo' => true])->assignRole('administrador');
        $zona = Ruta::create(['nombre' => 'Zona de prueba']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Cliente de prueba'])
            ->sucursales()->create(['nombre' => 'Sala de prueba', 'codigo' => '7001', 'ruta_id' => $zona->id]);
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
    }

    private function ccf(): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-000000000000701',
            'fecha_emision' => '2026-11-09', 'hora_emision' => '07:00:00', 'subtotal' => '50.00', 'total_pagar' => '50.00',
        ]);
    }

    public function test_el_administrador_sin_ficha_entra_y_hace_todo_lo_del_vendedor(): void
    {
        $dte = $this->ccf();
        $this->assertSame(0, PersonalRuta::count());
        $this->actingAs($this->admin);

        // Entra: la ficha se arma sola, una vez, enlazada y activa. Ve sus pestañas y no un aviso.
        $this->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSeeInOrder(['>Inicio</a>', '>Sacar pedido</a>', '>Por entregar</a>'], false)
            ->assertSee('Todavía no tenés un viaje abierto')
            ->assertDontSee('Tu usuario no es vendedor');
        $this->get(route('rutas.mi-salida.index'))->assertOk();
        $ficha = PersonalRuta::sole();
        $this->assertSame([$this->admin->id, true, 'Admin Ficticio'], [$ficha->user_id, $ficha->activo, $ficha->nombre]);

        // Saca un pedido: se abre su viaje.
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'Sala de prueba']))->assertOk()->assertSee('id="buscar-sala"', false);
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasNoErrors()->assertRedirect(route('rutas.sacar-pedido.index'));
        $this->assertSame($ficha->id, PedidoSala::sole()->sacado_por_id);
        $viaje = SalidaRuta::sole();

        // Marca una entrega.
        $this->get(route('rutas.por-entregar.index', ['ver' => 'todas']))->assertOk()
            ->assertSee('action="'.route('rutas.por-entregar.marcar', $dte).'"', false);
        $this->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame([ResultadoEntrega::Entregado, $ficha->id, $this->admin->id], [$entrega->resultado, $entrega->entregado_por_id, $entrega->registrado_por]);

        // Termina el día.
        $this->get(route('rutas.mi-salida.dia'))->assertOk()->assertSee('¿Mañana seguís en la misma zona?');
        $this->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => '0'])
            ->assertRedirect(route('rutas.mi-salida.index'))->assertSessionHas('status');
        $this->assertFalse($viaje->refresh()->suma_pedidos);
        $this->assertSame(1, PersonalRuta::count());
    }

    public function test_la_ficha_tambien_se_arma_entrando_por_sacar_pedido_o_por_entregar(): void
    {
        $this->actingAs($this->admin)->get(route('rutas.sacar-pedido.index'))->assertOk()->assertDontSee('Tu usuario no es vendedor');
        $this->assertSame($this->admin->id, PersonalRuta::sole()->user_id);

        $otro = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($otro)->get(route('rutas.por-entregar.index'))->assertOk()->assertDontSee('Tu usuario no es vendedor');
        $this->assertTrue(PersonalRuta::where('user_id', $otro->id)->where('activo', true)->exists());
    }

    public function test_no_se_crea_una_segunda_persona_para_el_administrador_que_ya_esta_en_personal(): void
    {
        // En Personal, con su ficha de Rutas ya creada pero sin usuario enlazado.
        $deAntes = PersonalRuta::create(['nombre' => 'Admin Ficticio de Personal']);
        $enPersonal = PlanillaEmpleado::create([
            'clave' => 'admin-1', 'registrado_por' => $this->admin->id, 'user_id' => $this->admin->id,
            'nombre' => 'Admin Ficticio de Personal', 'personal_ruta_id' => $deAntes->id,
            'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1', 'con_descuentos_ley' => false, 'activo' => true,
        ]);

        $this->actingAs($this->admin)->get(route('rutas.mi-salida.index'))->assertOk();

        $this->assertSame(1, PersonalRuta::count());
        $this->assertSame(1, PlanillaEmpleado::count());
        $this->assertSame($this->admin->id, $deAntes->refresh()->user_id);
        $this->assertSame($deAntes->id, $enPersonal->refresh()->personal_ruta_id);
    }

    public function test_la_ficha_que_alguien_desactivo_no_se_reactiva_por_entrar(): void
    {
        $ficha = PersonalRuta::create(['nombre' => 'Admin Ficticio', 'user_id' => $this->admin->id, 'activo' => false]);

        // Tampoco queda en una pantalla muerta: el aviso, sus pestañas y por dónde seguir.
        $this->actingAs($this->admin)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSeeInOrder(['>Inicio</a>', '>Sacar pedido</a>', '>Por entregar</a>'], false)
            ->assertSee('Tu usuario no es vendedor')
            ->assertSee('href="'.route('rutas.por-entregar.index').'"', false)
            ->assertSee('href="'.route('rutas.dashboard').'"', false)
            ->assertDontSee('no está enlazado')
            ->assertDontSee('Terminar el día');
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSee('Tu usuario no es vendedor')
            ->assertSee('Ver «Por entregar»')
            ->assertDontSee('id="buscar-sala"', false);
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])->assertForbidden();

        $this->assertFalse($ficha->refresh()->activo);
        $this->assertSame([1, 0], [PersonalRuta::count(), PedidoSala::count()]);
    }

    public function test_jefatura_y_facturacion_siguen_sin_ficha_miran_por_entregar_y_no_sacan_pedido(): void
    {
        $dte = $this->ccf();
        foreach (['jefatura', 'facturacion'] as $rol) {
            $usuario = User::factory()->create(['activo' => true])->assignRole($rol);
            $this->actingAs($usuario);

            // Su «Menú del vendedor» abre «Por entregar»: mira, con el aviso, y no marca.
            $this->get(route('rutas.por-entregar.index'))->assertOk()
                ->assertSee('Tu usuario no es vendedor: podés mirar la lista, pero no marcar entregas.')
                ->assertSee($dte->numero_control)
                ->assertDontSee('action="'.route('rutas.por-entregar.marcar', $dte).'"', false);
            $this->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])->assertForbidden();
            // Sin permisos nuevos: ni el Inicio del celular ni «Sacar pedido».
            $this->get(route('rutas.mi-salida.index'))->assertForbidden();
            $this->get(route('rutas.sacar-pedido.index'))->assertForbidden();
            $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])->assertForbidden();
        }

        $this->assertSame([0, 0, 0], [PersonalRuta::count(), PedidoSala::count(), SalidaRutaEntrega::count()]);
    }

    public function test_al_vendedor_que_la_oficina_desenlazo_no_se_le_rearma_la_ficha(): void
    {
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');

        $this->actingAs($vendedor)->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('Tu usuario no es vendedor');

        $this->assertSame(0, PersonalRuta::count());
    }
}
