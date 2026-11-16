<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Errores de vendedores (revisión del 13/11/2026, aprobada el 16/11/2026): si no tocó «Terminar
 * el día» y su viaje sigue sumando, al día siguiente Inicio y Sacar pedido le preguntan si sigue
 * en ese viaje, para que el pedido de hoy no se sume al viejo sin que se entere. Datos ficticios.
 */
class AvisoViajeAbiertoTest extends TestCase
{
    use RefreshDatabase;

    private const AVISO = 'Tu viaje del lunes 09/11 sigue abierto. ¿Seguís en ese viaje?';

    private User $vendedor;

    private PersonalRuta $persona;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-09 09:00:00');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);

        $dep = Departamento::firstOrCreate(['codigo' => '12'], ['nombre' => 'San Miguel', 'activo' => true]);
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->sala = $cliente->sucursales()->create(['nombre' => 'Sala de prueba Oriente', 'ruta_id' => $ruta->id, 'departamento_id' => $dep->id]);
        $this->otraSala = $cliente->sucursales()->create(['nombre' => 'Tienda de prueba Centro', 'ruta_id' => $ruta->id, 'departamento_id' => $dep->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->actingAs($this->vendedor);
    }

    private function sacar(ClienteSucursal $sala): TestResponse
    {
        return $this->post(route('rutas.sacar-pedido.store', $sala), ['cantidades' => [$this->galleta->id => '12']])->assertSessionHasNoErrors();
    }

    /** El aviso, en Inicio y en Sacar pedido. */
    private function preguntaSiSigue(bool $si): void
    {
        foreach ([route('rutas.mi-salida.index'), route('rutas.sacar-pedido.index')] as $pantalla) {
            $respuesta = $this->get($pantalla)->assertOk();
            $si ? $respuesta->assertSee(self::AVISO)->assertSee('>No, empiezo uno nuevo</button>', false)
                : $respuesta->assertDontSee('sigue abierto. ¿Seguís en ese viaje?');
        }
    }

    public function test_al_dia_siguiente_con_el_viaje_sumando_pregunta_y_el_mismo_dia_no(): void
    {
        $this->sacar($this->sala);
        $viaje = SalidaRuta::sole();
        $this->preguntaSiSigue(false);

        $this->travelTo('2026-11-10 08:00:00');
        $this->preguntaSiSigue(true);
        $this->get(route('rutas.sacar-pedido.index'))
            ->assertSee('action="'.route('rutas.mi-salida.sigue-viaje', $viaje).'"', false);
        // Preguntar no cambia nada.
        $this->assertTrue($viaje->refresh()->suma_pedidos);
    }

    public function test_no_pregunta_con_el_viaje_que_ya_no_suma_ni_si_hoy_ya_se_movio(): void
    {
        // Terminó el día con «No, vuelvo»: al otro día no hay nada que preguntar.
        $this->sacar($this->sala);
        $viaje = SalidaRuta::sole();
        $this->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => '0']);
        $this->travelTo('2026-11-10 08:00:00');
        $this->preguntaSiSigue(false);

        // Un viaje que sigue sumando pero ya tuvo una entrega hoy: el último movimiento es de hoy.
        $viaje->refresh()->update(['suma_pedidos' => true]);
        $this->preguntaSiSigue(true);
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $dte = Dte::create(['establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id, 'numero_control' => 'DTE-03-M001P002-000000000000901',
            'fecha_emision' => '2026-11-09', 'hora_emision' => '07:00:00', 'total_pagar' => '50.00']);
        $viaje->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $this->sala->id, 'resultado' => ResultadoEntrega::Entregado,
            'fecha_resultado' => '2026-11-10 07:30:00', 'entregado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id]);
        $this->preguntaSiSigue(false);

        // Cerrado, tampoco.
        $viaje->entregas()->delete();
        $viaje->update(['estado' => EstadoSalidaRuta::Finalizada, 'fecha_fin_real' => '2026-11-09']);
        $this->preguntaSiSigue(false);
    }

    public function test_si_sigo_lo_oculta_hoy_y_el_pedido_va_al_mismo_viaje(): void
    {
        $this->sacar($this->sala);
        $viaje = SalidaRuta::sole();
        $this->travelTo('2026-11-10 08:00:00');

        $this->from(route('rutas.sacar-pedido.index'))->post(route('rutas.mi-salida.sigue-viaje', $viaje), ['sigue' => '1'])
            ->assertRedirect(route('rutas.sacar-pedido.index'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Listo. Tus pedidos de hoy se siguen sumando a ese viaje.');
        $this->preguntaSiSigue(false);

        // No cambió nada: ni el viaje ni la bitácora. El pedido de hoy va a ese viaje.
        $this->assertTrue($viaje->refresh()->suma_pedidos);
        $this->assertFalse(Activity::where('subject_type', SalidaRuta::class)->where('description', 'like', 'terminó el día%')->exists());
        $this->sacar($this->otraSala);
        $this->assertSame([$viaje->id], SalidaRuta::pluck('id')->all());

        // Es solo por ese día: al siguiente, si sigue sumando, vuelve a preguntar.
        $this->travelTo('2026-11-11 08:00:00');
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee(self::AVISO);
    }

    public function test_no_empiezo_uno_nuevo_cierra_el_viaje_y_el_pedido_siguiente_abre_otro(): void
    {
        $this->sacar($this->sala);
        $viaje = SalidaRuta::sole();
        $this->travelTo('2026-11-10 08:00:00');

        $this->from(route('rutas.mi-salida.index'))->post(route('rutas.mi-salida.sigue-viaje', $viaje), ['sigue' => '0'])
            ->assertRedirect(route('rutas.mi-salida.index'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Listo. Ese viaje ya no suma pedidos: tu próximo pedido abre uno nuevo.');

        // Lo mismo que «Terminar el día» con «No, vuelvo»: deja de sumar y queda abierto para entregar.
        $viaje->refresh();
        $this->assertFalse($viaje->suma_pedidos);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->estado);
        $this->assertTrue(Activity::where('subject_type', SalidaRuta::class)->where('subject_id', $viaje->id)
            ->where('description', 'terminó el día: no sigue en la zona')->where('causer_id', $this->vendedor->id)->exists());
        $this->preguntaSiSigue(false);

        $this->sacar($this->otraSala);
        $nuevo = SalidaRuta::whereKeyNot($viaje->id)->sole();
        $this->assertSame('2026-11-10', $nuevo->fecha_inicio->toDateString());
        $this->assertTrue($nuevo->suma_pedidos);
    }

    public function test_solo_contesta_quien_abrio_el_viaje_y_tiene_que_decir_si_o_no(): void
    {
        $this->sacar($this->sala);
        $viaje = SalidaRuta::sole();
        $this->travelTo('2026-11-10 08:00:00');

        $this->post(route('rutas.mi-salida.sigue-viaje', $viaje), [])->assertSessionHasErrors(['sigue' => 'Contestá si seguís en ese viaje.']);

        $otro = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $otro->id]);
        $this->actingAs($otro)->post(route('rutas.mi-salida.sigue-viaje', $viaje), ['sigue' => '0'])->assertForbidden();
        $this->assertTrue($viaje->refresh()->suma_pedidos);
    }
}
